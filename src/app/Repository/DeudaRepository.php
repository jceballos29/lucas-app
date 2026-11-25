<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Deuda;
use PDO;

/**
 * Único sitio con el SQL de `deudas` y `pagos_deuda` (Fase 11).
 * TODA consulta filtra por el usuario o el espacio compartido de la sesión (RNF-04).
 */
final class DeudaRepository
{
    private const SELECT = 'SELECT d.id_deuda, d.id_usuario, d.nombre, d.acreedor, d.monto_inicial,
            d.tasa_mensual_bps, d.cuota_minima, d.dia_pago, d.estado,
            COALESCE((SELECT SUM(pd.abono_capital) FROM pagos_deuda pd WHERE pd.id_deuda = d.id_deuda), 0) AS capital_abonado,
            COALESCE((SELECT SUM(pd.pago_interes) FROM pagos_deuda pd WHERE pd.id_deuda = d.id_deuda), 0) AS intereses_pagados,
            (SELECT COUNT(*) FROM pagos_deuda pd WHERE pd.id_deuda = d.id_deuda) AS num_pagos
        FROM deudas d';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Activas primero, ordenadas por tasa de interés descendente y luego por nombre.
     *
     * @return list<Deuda>
     */
    public function listByUser(int $userId, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'd');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope ORDER BY FIELD(d.estado, 'activa', 'pagada'), d.tasa_mensual_bps DESC, d.nombre ASC, d.id_deuda ASC");
        $stmt->execute($params);

        return array_map([Deuda::class, 'fromRow'], $stmt->fetchAll());
    }

    public function findOwned(int $userId, int $id, ?int $espacioId = null): ?Deuda
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'd');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope AND d.id_deuda = :id");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Deuda::fromRow($row);
    }

    public function lockOwned(int $userId, int $id, ?int $espacioId = null): ?Deuda
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'd');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope AND d.id_deuda = :id FOR UPDATE");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Deuda::fromRow($row);
    }

    /**
     * @param array{nombre: string, acreedor: string|null, monto_inicial: string, tasa_mensual_bps: int, cuota_minima: string, dia_pago: int|null} $d
     */
    public function create(int $userId, array $d, ?int $espacioId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO deudas (id_usuario, id_espacio, nombre, acreedor, monto_inicial, tasa_mensual_bps, cuota_minima, dia_pago)
             VALUES (:u, :e, :n, :a, :m, :t, :c, :d)'
        );
        $stmt->execute([
            'u' => $userId,
            'e' => $espacioId,
            'n' => $d['nombre'],
            'a' => $d['acreedor'],
            'm' => $d['monto_inicial'],
            't' => $d['tasa_mensual_bps'],
            'c' => $d['cuota_minima'],
            'd' => $d['dia_pago'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{nombre: string, acreedor: string|null, monto_inicial: string, tasa_mensual_bps: int, cuota_minima: string, dia_pago: int|null} $d
     */
    public function update(int $userId, int $id, array $d, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "UPDATE deudas
             SET nombre = :n, acreedor = :a, monto_inicial = :m, tasa_mensual_bps = :t, cuota_minima = :c, dia_pago = :d
             WHERE id_deuda = :id AND $scope"
        );
        $stmt->execute($params + [
            'n' => $d['nombre'],
            'a' => $d['acreedor'],
            'm' => $d['monto_inicial'],
            't' => $d['tasa_mensual_bps'],
            'c' => $d['cuota_minima'],
            'd' => $d['dia_pago'],
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function setEstado(int $userId, int $id, string $estado, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("UPDATE deudas SET estado = :est WHERE id_deuda = :id AND $scope");
        $stmt->execute($params + ['est' => $estado, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("DELETE FROM deudas WHERE id_deuda = :id AND $scope");
        $stmt->execute($params + ['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function insertPago(
        int $deudaId,
        int $cuentaId,
        int $userId,
        string $montoTotal,
        string $abonoCapital,
        string $pagoInteres,
        string $fecha,
        ?string $descripcion,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pagos_deuda (id_deuda, id_cuenta, id_usuario, monto_total, abono_capital, pago_interes, fecha, descripcion)
             VALUES (:d, :c, :u, :mt, :ac, :pi, :f, :desc)'
        );
        $stmt->execute([
            'd' => $deudaId,
            'c' => $cuentaId,
            'u' => $userId,
            'mt' => $montoTotal,
            'ac' => $abonoCapital,
            'pi' => $pagoInteres,
            'f' => $fecha,
            'desc' => $descripcion,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array{id: int, fecha: string, monto_total: string, abono_capital: string, pago_interes: string, cuenta: string, descripcion: string|null, autor: string}>
     */
    public function listPagos(int $userId, int $deudaId, int $limit = 30, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'd');
        $stmt = $this->pdo->prepare(
            "SELECT pd.id_pago, pd.fecha, pd.monto_total, pd.abono_capital, pd.pago_interes,
                    pd.descripcion, c.nombre AS cuenta, u.nombre AS autor
             FROM pagos_deuda pd
             JOIN deudas d ON d.id_deuda = pd.id_deuda
             JOIN cuentas c ON c.id_cuenta = pd.id_cuenta
             JOIN usuarios u ON u.id_usuario = pd.id_usuario
             WHERE $scope AND pd.id_deuda = :id
             ORDER BY pd.fecha DESC, pd.id_pago DESC
             LIMIT :limit"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue('id', $deudaId, PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id_pago'],
            'fecha' => (string) $r['fecha'],
            'monto_total' => (string) $r['monto_total'],
            'abono_capital' => (string) $r['abono_capital'],
            'pago_interes' => (string) $r['pago_interes'],
            'cuenta' => (string) $r['cuenta'],
            'descripcion' => $r['descripcion'] === null ? null : (string) $r['descripcion'],
            'autor' => (string) $r['autor'],
        ], $stmt->fetchAll());
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
