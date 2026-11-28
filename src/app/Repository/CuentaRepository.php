<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Cuenta;
use PDO;

/**
 * Único sitio con el SQL de `cuentas`. TODA consulta filtra por el usuario (o su espacio compartido activo) de la SESIÓN
 * (RNF-04): una cuenta ajena simplemente «no existe» (null / false). El saldo no se guarda, se calcula.
 */
final class CuentaRepository
{
    private const SELECT = "SELECT c.id_cuenta, c.id_usuario, c.nombre, c.tipo, c.moneda, c.saldo_inicial, c.activa,
            c.dia_corte, c.dia_pago_limite, c.tasa_mensual_bps,
            c.saldo_inicial
            + COALESCE((SELECT SUM(t.monto) FROM transacciones t WHERE t.id_cuenta = c.id_cuenta AND t.tipo = 'ingreso'), 0)
            - COALESCE((SELECT SUM(t.monto) FROM transacciones t WHERE t.id_cuenta = c.id_cuenta AND t.tipo = 'gasto'), 0)
            - COALESCE((SELECT SUM(a.monto) FROM aportes_meta a WHERE a.id_cuenta = c.id_cuenta), 0)
            - COALESCE((SELECT SUM(pd.monto_total) FROM pagos_deuda pd WHERE pd.id_cuenta = c.id_cuenta), 0)
            - COALESCE((SELECT SUM(tr.monto) FROM transferencias tr WHERE tr.id_cuenta_origen = c.id_cuenta), 0)
            + COALESCE((SELECT SUM(COALESCE(tr.monto_destino, tr.monto)) FROM transferencias tr WHERE tr.id_cuenta_destino = c.id_cuenta), 0) AS saldo
        FROM cuentas c";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Las cuentas activas primero, por nombre.
     *
     * @return list<Cuenta>
     */
    public function listByUser(int $userId, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'c');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope ORDER BY c.activa DESC, c.nombre, c.id_cuenta");
        $stmt->execute($params);

        return array_map([Cuenta::class, 'fromRow'], $stmt->fetchAll());
    }

    public function findOwned(int $userId, int $id, ?int $espacioId = null): ?Cuenta
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'c');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope AND c.id_cuenta = :id");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Cuenta::fromRow($row);
    }

    /** ¿Ya existe en este espacio/usuario una cuenta con ese nombre (sin distinguir mayúsculas ni tildes)? */
    public function nameTaken(int $userId, string $nombre, ?int $exceptId = null, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cuentas WHERE $scope AND nombre = :n AND id_cuenta <> :ex");
        $stmt->execute($params + ['n' => $nombre, 'ex' => $exceptId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(
        int $userId,
        string $nombre,
        string $tipo,
        string $saldoInicial,
        ?int $espacioId = null,
        ?int $diaCorte = null,
        ?int $diaPagoLimite = null,
        int $tasaMensualBps = 0,
        ?string $moneda = null,
    ): int {
        $esTarjeta = $tipo === 'tarjeta_credito';
        $stmt = $this->pdo->prepare(
            'INSERT INTO cuentas (id_usuario, id_espacio, nombre, tipo, moneda, saldo_inicial, dia_corte, dia_pago_limite, tasa_mensual_bps)
             VALUES (:u, :e, :n, :t, :m, :s, :dc, :dp, :tasa)',
        );
        $stmt->execute([
            'u' => $userId,
            'e' => $espacioId,
            'n' => $nombre,
            't' => $tipo,
            'm' => $moneda,
            's' => $saldoInicial,
            'dc' => $esTarjeta ? $diaCorte : null,
            'dp' => $esTarjeta ? $diaPagoLimite : null,
            'tasa' => $esTarjeta ? max(0, min(2000, $tasaMensualBps)) : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return bool false si la cuenta no existe o no pertenece al contexto actual */
    public function update(
        int $userId,
        int $id,
        string $nombre,
        string $tipo,
        string $saldoInicial,
        ?int $espacioId = null,
        ?int $diaCorte = null,
        ?int $diaPagoLimite = null,
        int $tasaMensualBps = 0,
        ?string $moneda = null,
    ): bool {
        $esTarjeta = $tipo === 'tarjeta_credito';
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "UPDATE cuentas SET nombre = :n, tipo = :t, moneda = :m, saldo_inicial = :s, dia_corte = :dc, dia_pago_limite = :dp, tasa_mensual_bps = :tasa
             WHERE id_cuenta = :id AND $scope",
        );
        $stmt->execute($params + [
            'n' => $nombre,
            't' => $tipo,
            'm' => $moneda,
            's' => $saldoInicial,
            'dc' => $esTarjeta ? $diaCorte : null,
            'dp' => $esTarjeta ? $diaPagoLimite : null,
            'tasa' => $esTarjeta ? max(0, min(2000, $tasaMensualBps)) : 0,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** Las cuentas no se borran: se desactivan y se conserva su historial (RF-06). */
    public function setActiva(int $userId, int $id, bool $activa, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("UPDATE cuentas SET activa = :a WHERE id_cuenta = :id AND $scope");
        $stmt->execute($params + ['a' => $activa ? 1 : 0, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Lee la cuenta BLOQUEANDO su fila hasta el final de la transacción (`FOR UPDATE`). Solo dentro de una transacción.
     *
     * @return array{nombre: string, tipo: string, moneda: string|null, activa: bool, dia_corte: int|null, dia_pago_limite: int|null, tasa_mensual_bps: int}|null
     */
    public function lockForWrite(int $userId, int $id, ?int $espacioId = null): ?array
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("SELECT nombre, tipo, moneda, activa, dia_corte, dia_pago_limite, tasa_mensual_bps FROM cuentas WHERE id_cuenta = :id AND $scope FOR UPDATE");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'nombre' => (string) $row['nombre'],
            'tipo' => (string) $row['tipo'],
            'moneda' => isset($row['moneda']) && is_string($row['moneda']) && $row['moneda'] !== '' ? $row['moneda'] : null,
            'activa' => (bool) $row['activa'],
            'dia_corte' => $row['dia_corte'] !== null ? (int) $row['dia_corte'] : null,
            'dia_pago_limite' => $row['dia_pago_limite'] !== null ? (int) $row['dia_pago_limite'] : null,
            'tasa_mensual_bps' => isset($row['tasa_mensual_bps']) ? (int) $row['tasa_mensual_bps'] : 0,
        ];
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
