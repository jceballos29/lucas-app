<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Presupuesto;
use App\Support\Mes;
use PDO;
use PDOException;

/**
 * Único sitio con el SQL de `presupuestos`. TODA consulta filtra por el usuario o el espacio compartido de la SESIÓN (RNF-04).
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

    /** Los presupuestos de un mes, por nombre de categoría. @return list<Presupuesto> */
    public function listByMonth(int $userId, int $mes, int $anio, ?int $espacioId = null): array
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        [$select, $scope, $params] = self::selectAndScope($userId, $espacioId);
        $stmt = $this->pdo->prepare("$select WHERE $scope AND p.mes = :mes AND p.anio = :anio ORDER BY k.nombre, p.id_presupuesto");
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta, 'mes' => $mes, 'anio' => $anio]);

        return array_map([Presupuesto::class, 'fromRow'], $stmt->fetchAll());
    }

    public function findOwned(int $userId, int $id, ?int $espacioId = null): ?Presupuesto
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

        return $row === false ? null : Presupuesto::fromRow($row);
    }

    public function findForCategory(int $userId, int $categoriaId, int $mes, int $anio, ?int $espacioId = null): ?Presupuesto
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        [$select, $scope, $params] = self::selectAndScope($userId, $espacioId);
        $stmt = $this->pdo->prepare("$select WHERE $scope AND p.id_categoria = :cat AND p.mes = :mes AND p.anio = :anio");
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta, 'cat' => $categoriaId, 'mes' => $mes, 'anio' => $anio]);
        $row = $stmt->fetch();

        return $row === false ? null : Presupuesto::fromRow($row);
    }

    /**
     * @return list<Presupuesto>
     */
    public function alertas(int $userId, int $mes, int $anio, ?int $espacioId = null): array
    {
        $alertas = array_values(array_filter($this->listByMonth($userId, $mes, $anio, $espacioId), static fn (Presupuesto $p): bool => $p->requiereAviso()));
        usort($alertas, static fn (Presupuesto $a, Presupuesto $b): int => $b->progreso()['percent'] <=> $a->progreso()['percent']);

        return $alertas;
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
