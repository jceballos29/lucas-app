<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Movimiento;
use PDO;

/**
 * Único sitio con el SQL de `transacciones`. TODA consulta lleva el id del usuario de la SESIÓN (RNF-04): un movimiento
 * ajeno simplemente «no existe». Los montos viajan como cadenas decimales, nunca como float (RNF-16).
 */
final class MovimientoRepository
{
    private const SELECT = 'SELECT t.id_transaccion, t.id_usuario, t.id_cuenta, t.id_categoria, t.tipo, t.monto, t.fecha, t.descripcion,
            c.nombre AS cuenta_nombre, k.nombre AS categoria_nombre, k.color AS categoria_color
        FROM transacciones t
        JOIN cuentas c ON c.id_cuenta = t.id_cuenta
        JOIN categorias k ON k.id_categoria = t.id_categoria';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Página del listado, de lo más reciente a lo más antiguo.
     *
     * @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string} $filtros
     * @return list<Movimiento>
     */
    public function search(int $userId, array $filtros, int $limit, int $offset): array
    {
        [$where, $params] = self::where($userId, $filtros);
        $stmt = $this->pdo->prepare(self::SELECT . $where . ' ORDER BY t.fecha DESC, t.id_transaccion DESC LIMIT :limit OFFSET :offset');

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([Movimiento::class, 'fromRow'], $stmt->fetchAll());
    }

    /** @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string} $filtros */
    public function count(int $userId, array $filtros): int
    {
        [$where, $params] = self::where($userId, $filtros);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transacciones t' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Suma de ingresos y de gastos de TODO lo que cumple los filtros (no solo de la página).
     *
     * @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string} $filtros
     * @return array{ingresos: string, gastos: string}
     */
    public function totals(int $userId, array $filtros): array
    {
        [$where, $params] = self::where($userId, $filtros);
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN t.tipo = 'ingreso' THEN t.monto END), 0) AS ingresos,
                    COALESCE(SUM(CASE WHEN t.tipo = 'gasto' THEN t.monto END), 0) AS gastos
             FROM transacciones t" . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return ['ingresos' => (string) $row['ingresos'], 'gastos' => (string) $row['gastos']];
    }

    public function findOwned(int $userId, int $id): ?Movimiento
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE t.id_usuario = :u AND t.id_transaccion = :id');
        $stmt->execute(['u' => $userId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Movimiento::fromRow($row);
    }

    public function insert(int $userId, int $cuentaId, int $categoriaId, string $tipo, string $monto, string $fecha, ?string $descripcion): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha, descripcion) VALUES (:u, :cuenta, :cat, :tipo, :monto, :fecha, :desc)'
        );
        $stmt->execute(['u' => $userId, 'cuenta' => $cuentaId, 'cat' => $categoriaId, 'tipo' => $tipo, 'monto' => $monto, 'fecha' => $fecha, 'desc' => $descripcion]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return bool false si no existe o no es del usuario */
    public function update(int $userId, int $id, int $cuentaId, int $categoriaId, string $tipo, string $monto, string $fecha, ?string $descripcion): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE transacciones SET id_cuenta = :cuenta, id_categoria = :cat, tipo = :tipo, monto = :monto, fecha = :fecha, descripcion = :desc
             WHERE id_transaccion = :id AND id_usuario = :u'
        );
        $stmt->execute(['cuenta' => $cuentaId, 'cat' => $categoriaId, 'tipo' => $tipo, 'monto' => $monto, 'fecha' => $fecha, 'desc' => $descripcion, 'id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** @return bool false si no existe o no es del usuario */
    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM transacciones WHERE id_transaccion = :id AND id_usuario = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string} $f
     * @return array{0: string, 1: array<string, string|int>}
     */
    private static function where(int $userId, array $f): array
    {
        $conditions = ['t.id_usuario = :u'];
        $params = ['u' => $userId];

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

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }
}
