<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Presupuesto;
use App\Support\ConversorMoneda;
use App\Support\Dinero;
use App\Support\Mes;
use PDO;
use PDOException;

/**
 * Único sitio con el SQL de `presupuestos`. TODA consulta filtra por el usuario o el espacio compartido de la SESIÓN (RNF-04).
 * Cuando se indica `$monedaBase` y `$tasas`, los gastos en cuentas de otras monedas se convierten al vuelo.
 */
final class PresupuestoRepository
{
    private const SELECT_PERSONAL = "SELECT p.id_presupuesto, p.id_usuario, p.id_categoria, p.mes, p.anio, p.monto_limite,
            k.nombre AS categoria_nombre, k.color AS categoria_color,
            COALESCE((SELECT SUM(t.monto) FROM transacciones t
                      WHERE t.id_usuario = p.id_usuario AND t.id_espacio IS NULL AND t.id_categoria = p.id_categoria AND t.tipo = 'gasto'
                        AND t.fecha >= :desde AND t.fecha < :hasta), 0) AS gastado
        FROM presupuestos p
        JOIN categorias k ON k.id_categoria = p.id_categoria";

    private const SELECT_ESPACIO = "SELECT p.id_presupuesto, p.id_usuario, p.id_categoria, p.mes, p.anio, p.monto_limite,
            k.nombre AS categoria_nombre, k.color AS categoria_color,
            COALESCE((SELECT SUM(t.monto) FROM transacciones t
                      WHERE t.id_espacio = p.id_espacio AND t.id_categoria = p.id_categoria AND t.tipo = 'gasto'
                        AND t.fecha >= :desde AND t.fecha < :hasta), 0) AS gastado
        FROM presupuestos p
        JOIN categorias k ON k.id_categoria = p.id_categoria";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Los presupuestos de un mes, por nombre de categoría.
     *
     * @param array<string, int> $tasas
     * @return list<Presupuesto>
     */
    public function listByMonth(int $userId, int $mes, int $anio, ?int $espacioId = null, string $monedaBase = '', array $tasas = []): array
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        [$select, $scope, $params] = self::selectAndScope($userId, $espacioId);
        $stmt = $this->pdo->prepare("$select WHERE $scope AND p.mes = :mes AND p.anio = :anio ORDER BY k.nombre, p.id_presupuesto");
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta, 'mes' => $mes, 'anio' => $anio]);
        $rows = $stmt->fetchAll();

        if ($monedaBase !== '' && $rows !== []) {
            $gastados = $this->gastadoConvertidoPorCategoria($userId, $mes, $anio, $espacioId, $monedaBase, $tasas);
            foreach ($rows as &$r) {
                $r['gastado'] = $gastados[(int) $r['id_categoria']] ?? '0.00';
            }
            unset($r);
        }

        return array_map([Presupuesto::class, 'fromRow'], $rows);
    }

    /** @param array<string, int> $tasas */
    public function findOwned(int $userId, int $id, ?int $espacioId = null, string $monedaBase = '', array $tasas = []): ?Presupuesto
    {
        [$select, $scope, $params] = self::selectAndScope($userId, $espacioId);
        $stmt = $this->pdo->prepare("SELECT mes, anio FROM presupuestos p WHERE p.id_presupuesto = :id AND $scope");
        $stmt->execute($params + ['id' => $id]);
        $mes = $stmt->fetch();

        if ($mes === false) {
            return null;
        }

        [$desde, $hasta] = Mes::limites((int) $mes['mes'], (int) $mes['anio']);
        $stmt = $this->pdo->prepare("$select WHERE $scope AND p.id_presupuesto = :id");
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta, 'id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        if ($monedaBase !== '') {
            $gastados = $this->gastadoConvertidoPorCategoria($userId, (int) $mes['mes'], (int) $mes['anio'], $espacioId, $monedaBase, $tasas, (int) $row['id_categoria']);
            $row['gastado'] = $gastados[(int) $row['id_categoria']] ?? '0.00';
        }

        return Presupuesto::fromRow($row);
    }

    /** @param array<string, int> $tasas */
    public function findForCategory(int $userId, int $categoriaId, int $mes, int $anio, ?int $espacioId = null, string $monedaBase = '', array $tasas = []): ?Presupuesto
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        [$select, $scope, $params] = self::selectAndScope($userId, $espacioId);
        $stmt = $this->pdo->prepare("$select WHERE $scope AND p.id_categoria = :cat AND p.mes = :mes AND p.anio = :anio");
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta, 'cat' => $categoriaId, 'mes' => $mes, 'anio' => $anio]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        if ($monedaBase !== '') {
            $gastados = $this->gastadoConvertidoPorCategoria($userId, $mes, $anio, $espacioId, $monedaBase, $tasas, $categoriaId);
            $row['gastado'] = $gastados[$categoriaId] ?? '0.00';
        }

        return Presupuesto::fromRow($row);
    }

    /**
     * @param array<string, int> $tasas
     * @return list<Presupuesto>
     */
    public function alertas(int $userId, int $mes, int $anio, ?int $espacioId = null, string $monedaBase = '', array $tasas = []): array
    {
        $alertas = array_values(array_filter($this->listByMonth($userId, $mes, $anio, $espacioId, $monedaBase, $tasas), static fn (Presupuesto $p): bool => $p->requiereAviso()));
        usort($alertas, static fn (Presupuesto $a, Presupuesto $b): int => $b->progreso()['percent'] <=> $a->progreso()['percent']);

        return $alertas;
    }

    /**
     * Suma de gastos del mes por categoría convirtiendo al vuelo los movimientos de cuentas en otra moneda.
     *
     * @param array<string, int> $tasas
     * @return array<int, string> id_categoria => total convertido en `$monedaBase`
     */
    private function gastadoConvertidoPorCategoria(int $userId, int $mes, int $anio, ?int $espacioId, string $monedaBase, array $tasas, ?int $categoriaId = null): array
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        if ($espacioId === null) {
            $scope = 't.id_usuario = :u AND t.id_espacio IS NULL';
            $params = ['u' => $userId];
        } else {
            $scope = 't.id_espacio = :e';
            $params = ['e' => $espacioId];
        }

        $filtroCat = '';
        if ($categoriaId !== null) {
            $filtroCat = ' AND t.id_categoria = :cat';
            $params['cat'] = $categoriaId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT t.id_categoria, c.moneda, SUM(t.monto) AS total
             FROM transacciones t
             JOIN cuentas c ON c.id_cuenta = t.id_cuenta
             WHERE $scope AND t.tipo = 'gasto' AND t.fecha >= :desde AND t.fecha < :hasta$filtroCat
             GROUP BY t.id_categoria, c.moneda",
        );
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);

        $centavos = [];
        foreach ($stmt->fetchAll() as $r) {
            $idCat = (int) $r['id_categoria'];
            $monedaCuenta = is_string($r['moneda'] ?? null) && $r['moneda'] !== '' ? $r['moneda'] : $monedaBase;
            $sub = (string) $r['total'];
            $conv = ConversorMoneda::convertir($sub, $monedaCuenta, $monedaBase, $tasas) ?? $sub;
            $centavos[$idCat] = ($centavos[$idCat] ?? 0) + Dinero::aCentavos($conv);
        }

        return array_map([Dinero::class, 'deCentavos'], $centavos);
    }

    public function create(int $userId, int $categoriaId, int $mes, int $anio, string $limite, ?int $espacioId = null): ?int
    {
        if ($this->findForCategory($userId, $categoriaId, $mes, $anio, $espacioId) !== null) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare('INSERT INTO presupuestos (id_usuario, id_espacio, id_categoria, mes, anio, monto_limite) VALUES (:u, :e, :cat, :mes, :anio, :limite)');
            $stmt->execute(['u' => $userId, 'e' => $espacioId, 'cat' => $categoriaId, 'mes' => $mes, 'anio' => $anio, 'limite' => $limite]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return null;
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function updateLimit(int $userId, int $id, string $limite, ?int $espacioId = null): bool
    {
        [, $scope, $params] = self::selectAndScope($userId, $espacioId);
        $stmt = $this->pdo->prepare("UPDATE presupuestos p SET p.monto_limite = :limite WHERE p.id_presupuesto = :id AND $scope");
        $stmt->execute($params + ['limite' => $limite, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id, ?int $espacioId = null): bool
    {
        [, $scope, $params] = self::selectAndScope($userId, $espacioId);
        $stmt = $this->pdo->prepare("DELETE p FROM presupuestos p WHERE p.id_presupuesto = :id AND $scope");
        $stmt->execute($params + ['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{0: string, 1: string, 2: array<string, int>} */
    private static function selectAndScope(int $userId, ?int $espacioId): array
    {
        if ($espacioId === null) {
            return [self::SELECT_PERSONAL, 'p.id_usuario = :u AND p.id_espacio IS NULL', ['u' => $userId]];
        }

        return [self::SELECT_ESPACIO, 'p.id_espacio = :e', ['e' => $espacioId]];
    }
}
