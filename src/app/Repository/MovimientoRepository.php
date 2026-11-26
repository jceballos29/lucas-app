<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Movimiento;
use App\Support\ConversorMoneda;
use App\Support\Dinero;
use PDO;

/**
 * Único sitio con el SQL de `transacciones`. TODA consulta filtra por el usuario o el espacio compartido de la SESIÓN
 * (RNF-04). En un espacio compartido, `id_usuario` identifica qué miembro de la familia registró el movimiento.
 */
final class MovimientoRepository
{
    private const SELECT = 'SELECT t.id_transaccion, t.id_usuario, t.id_cuenta, t.id_categoria, t.tipo, t.monto, t.fecha, t.descripcion,
            t.etiqueta, t.grupo_split, t.num_cuotas, t.con_intereses, t.tasa_mensual_bps,
            c.nombre AS cuenta_nombre, c.moneda AS cuenta_moneda, k.nombre AS categoria_nombre, k.color AS categoria_color, u.nombre AS autor_nombre
        FROM transacciones t
        JOIN cuentas c ON c.id_cuenta = t.id_cuenta
        JOIN categorias k ON k.id_categoria = t.id_categoria
        JOIN usuarios u ON u.id_usuario = t.id_usuario';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string} $filtros
     * @return list<Movimiento>
     */
    public function search(int $userId, array $filtros, int $limit, int $offset, ?int $espacioId = null): array
    {
        [$where, $params] = self::where($userId, $filtros, $espacioId);
        $stmt = $this->pdo->prepare(self::SELECT . $where . ' ORDER BY t.fecha DESC, t.id_transaccion DESC LIMIT :limit OFFSET :offset');

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([Movimiento::class, 'fromRow'], $stmt->fetchAll());
    }

    /**
     * @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string} $filtros
     * @param array{fecha: string, id: int}|null $despuesDe
     * @return list<Movimiento>
     */
    public function searchAfter(int $userId, array $filtros, ?array $despuesDe, int $limit, ?int $espacioId = null): array
    {
        [$where, $params] = self::where($userId, $filtros, $espacioId);

        if ($despuesDe !== null) {
            $where .= ' AND (t.fecha < :c_fecha OR (t.fecha = :c_fecha2 AND t.id_transaccion < :c_id))';
            $params['c_fecha'] = $despuesDe['fecha'];
            $params['c_fecha2'] = $despuesDe['fecha'];
            $params['c_id'] = $despuesDe['id'];
        }

        $stmt = $this->pdo->prepare(self::SELECT . $where . ' ORDER BY t.fecha DESC, t.id_transaccion DESC LIMIT :limit');
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([Movimiento::class, 'fromRow'], $stmt->fetchAll());
    }

    /** @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string} $filtros */
    public function count(int $userId, array $filtros, ?int $espacioId = null): int
    {
        [$where, $params] = self::where($userId, $filtros, $espacioId);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transacciones t' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string} $filtros
     * @param array<string, int> $tasas
     * @return array{ingresos: string, gastos: string}
     */
    public function totals(int $userId, array $filtros, ?int $espacioId = null, string $monedaBase = '', array $tasas = []): array
    {
        [$where, $params] = self::where($userId, $filtros, $espacioId);
        if ($monedaBase === '') {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN t.tipo = 'ingreso' THEN t.monto END), 0) AS ingresos,
                        COALESCE(SUM(CASE WHEN t.tipo = 'gasto' THEN t.monto END), 0) AS gastos
                 FROM transacciones t" . $where
            );
            $stmt->execute($params);
            $row = $stmt->fetch();

            return ['ingresos' => (string) $row['ingresos'], 'gastos' => (string) $row['gastos']];
        }

        $stmt = $this->pdo->prepare(
            "SELECT c.moneda,
                    COALESCE(SUM(CASE WHEN t.tipo = 'ingreso' THEN t.monto END), 0) AS ingresos,
                    COALESCE(SUM(CASE WHEN t.tipo = 'gasto' THEN t.monto END), 0) AS gastos
             FROM transacciones t
             JOIN cuentas c ON c.id_cuenta = t.id_cuenta" . $where . ' GROUP BY c.moneda'
        );
        $stmt->execute($params);

        $ingresos = [];
        $gastos = [];
        foreach ($stmt->fetchAll() as $r) {
            $monedaCuenta = is_string($r['moneda'] ?? null) && $r['moneda'] !== '' ? $r['moneda'] : $monedaBase;
            $ing = (string) $r['ingresos'];
            $gas = (string) $r['gastos'];
            $ingresos[] = ConversorMoneda::convertir($ing, $monedaCuenta, $monedaBase, $tasas) ?? $ing;
            $gastos[] = ConversorMoneda::convertir($gas, $monedaCuenta, $monedaBase, $tasas) ?? $gas;
        }

        return ['ingresos' => Dinero::sumar($ingresos), 'gastos' => Dinero::sumar($gastos)];
    }

    public function findOwned(int $userId, int $id, ?int $espacioId = null): ?Movimiento
    {
        [$scope, $params] = self::scope($userId, $espacioId, 't');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope AND t.id_transaccion = :id");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Movimiento::fromRow($row);
    }

    public function insert(
        int $userId,
        int $cuentaId,
        int $categoriaId,
        string $tipo,
        string $monto,
        string $fecha,
        ?string $descripcion,
        ?int $espacioId = null,
        ?string $etiqueta = null,
        ?string $grupoSplit = null,
        int $numCuotas = 1,
        bool $conIntereses = true,
        int $tasaMensualBps = 0,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transacciones (id_usuario, id_espacio, id_cuenta, id_categoria, tipo, monto, fecha, descripcion, etiqueta, grupo_split, num_cuotas, con_intereses, tasa_mensual_bps)
             VALUES (:u, :e, :cuenta, :cat, :tipo, :monto, :fecha, :desc, :etiqueta, :split, :cuotas, :con_int, :tasa)'
        );
        $stmt->execute([
            'u' => $userId,
            'e' => $espacioId,
            'cuenta' => $cuentaId,
            'cat' => $categoriaId,
            'tipo' => $tipo,
            'monto' => $monto,
            'fecha' => $fecha,
            'desc' => $descripcion,
            'etiqueta' => $etiqueta,
            'split' => $grupoSplit,
            'cuotas' => max(1, min(36, $numCuotas)),
            'con_int' => $conIntereses ? 1 : 0,
            'tasa' => max(0, min(2000, $tasaMensualBps)),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $userId,
        int $id,
        int $cuentaId,
        int $categoriaId,
        string $tipo,
        string $monto,
        string $fecha,
        ?string $descripcion,
        ?int $espacioId = null,
        ?string $etiqueta = null,
        int $numCuotas = 1,
        bool $conIntereses = true,
        int $tasaMensualBps = 0,
    ): bool {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "UPDATE transacciones SET id_cuenta = :cuenta, id_categoria = :cat, tipo = :tipo, monto = :monto, fecha = :fecha, descripcion = :desc, etiqueta = :etiqueta, num_cuotas = :cuotas, con_intereses = :con_int, tasa_mensual_bps = :tasa
             WHERE id_transaccion = :id AND $scope"
        );
        $stmt->execute($params + [
            'cuenta' => $cuentaId,
            'cat' => $categoriaId,
            'tipo' => $tipo,
            'monto' => $monto,
            'fecha' => $fecha,
            'desc' => $descripcion,
            'etiqueta' => $etiqueta,
            'cuotas' => max(1, min(36, $numCuotas)),
            'con_int' => $conIntereses ? 1 : 0,
            'tasa' => max(0, min(2000, $tasaMensualBps)),
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("DELETE FROM transacciones WHERE id_transaccion = :id AND $scope");
        $stmt->execute($params + ['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Lista las etiquetas distintas usadas por el usuario o espacio compartido, ordenadas alfabéticamente.
     *
     * @return list<string>
     */
    public function listEtiquetas(int $userId, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT etiqueta FROM transacciones WHERE $scope AND etiqueta IS NOT NULL AND etiqueta <> '' ORDER BY etiqueta"
        );
        $stmt->execute($params);

        return array_map(static fn (array $r): string => (string) $r['etiqueta'], $stmt->fetchAll());
    }

    /**
     * @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string, etiqueta?: string} $f
     * @return array{0: string, 1: array<string, string|int>}
     */
    private static function where(int $userId, array $f, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId, 't');
        $conditions = [$scope];

        if ($f['desde'] !== '') {
            $conditions[] = 't.fecha >= :desde';
            $params['desde'] = $f['desde'];
        }
        if ($f['hasta'] !== '') {
            $conditions[] = 't.fecha <= :hasta';
            $params['hasta'] = $f['hasta'];
        }
        if ($f['cuenta'] > 0) {
            $conditions[] = 't.id_cuenta = :cuenta';
            $params['cuenta'] = $f['cuenta'];
        }
        if ($f['categoria'] > 0) {
            $conditions[] = 't.id_categoria = :cat';
            $params['cat'] = $f['categoria'];
        }
        if ($f['tipo'] !== '') {
            $conditions[] = 't.tipo = :tipo';
            $params['tipo'] = $f['tipo'];
        }
        if (($f['etiqueta'] ?? '') !== '') {
            $conditions[] = 't.etiqueta = :etiqueta';
            $params['etiqueta'] = $f['etiqueta'];
        }

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @return array{0: string, 1: array<string, int>} */
    private static function scope(int $userId, ?int $espacioId, string $alias = ''): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        if ($espacioId === null) {
            return ["{$p}id_usuario = :u AND {$p}id_espacio IS NULL", ['u' => $userId]];
        }

        return ["{$p}id_espacio = :e", ['e' => $espacioId]];
    }
}
