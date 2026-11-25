<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Cuenta;
use PDO;

/**
 * Único sitio con el SQL de `cuentas`. TODA consulta lleva el id del usuario de la SESIÓN (RNF-04): una cuenta ajena
 * simplemente «no existe» (null / false). El saldo no se guarda, se calcula: saldo inicial + ingresos − gastos − aportes a metas.
 */
final class CuentaRepository
{
    private const SELECT = "SELECT c.id_cuenta, c.id_usuario, c.nombre, c.tipo, c.saldo_inicial, c.activa,
            c.saldo_inicial
            + COALESCE((SELECT SUM(t.monto) FROM transacciones t WHERE t.id_cuenta = c.id_cuenta AND t.tipo = 'ingreso'), 0)
            - COALESCE((SELECT SUM(t.monto) FROM transacciones t WHERE t.id_cuenta = c.id_cuenta AND t.tipo = 'gasto'), 0)
            - COALESCE((SELECT SUM(a.monto) FROM aportes_meta a WHERE a.id_cuenta = c.id_cuenta), 0) AS saldo
        FROM cuentas c";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Las cuentas activas primero, por nombre. @return list<Cuenta> */
    public function listByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE c.id_usuario = :u ORDER BY c.activa DESC, c.nombre, c.id_cuenta');
        $stmt->execute(['u' => $userId]);

        return array_map([Cuenta::class, 'fromRow'], $stmt->fetchAll());
    }

    public function findOwned(int $userId, int $id): ?Cuenta
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE c.id_usuario = :u AND c.id_cuenta = :id');
        $stmt->execute(['u' => $userId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Cuenta::fromRow($row);
    }

    /** ¿Ya tiene el usuario una cuenta con ese nombre (sin distinguir mayúsculas ni tildes)? */
    public function nameTaken(int $userId, string $nombre, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cuentas WHERE id_usuario = :u AND nombre = :n AND id_cuenta <> :ex');
        $stmt->execute(['u' => $userId, 'n' => $nombre, 'ex' => $exceptId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(int $userId, string $nombre, string $tipo, string $saldoInicial): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO cuentas (id_usuario, nombre, tipo, saldo_inicial) VALUES (:u, :n, :t, :s)');
        $stmt->execute(['u' => $userId, 'n' => $nombre, 't' => $tipo, 's' => $saldoInicial]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return bool false si la cuenta no existe o no es del usuario */
    public function update(int $userId, int $id, string $nombre, string $tipo, string $saldoInicial): bool
    {
        $stmt = $this->pdo->prepare('UPDATE cuentas SET nombre = :n, tipo = :t, saldo_inicial = :s WHERE id_cuenta = :id AND id_usuario = :u');
        $stmt->execute(['n' => $nombre, 't' => $tipo, 's' => $saldoInicial, 'id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** Las cuentas no se borran: se desactivan y se conserva su historial (RF-06). @return bool false si no existe o no es del usuario */
    public function setActiva(int $userId, int $id, bool $activa): bool
    {
        $stmt = $this->pdo->prepare('UPDATE cuentas SET activa = :a WHERE id_cuenta = :id AND id_usuario = :u');
        $stmt->execute(['a' => $activa ? 1 : 0, 'id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Lee la cuenta del usuario BLOQUEANDO su fila hasta el final de la transacción (`FOR UPDATE`): mientras se guarda un
     * movimiento nadie puede desactivarla (D8). Solo dentro de una transacción.
     *
     * @return array{nombre: string, tipo: string, activa: bool}|null null si no existe o no es del usuario
     */
    public function lockForWrite(int $userId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT nombre, tipo, activa FROM cuentas WHERE id_cuenta = :id AND id_usuario = :u FOR UPDATE');
        $stmt->execute(['id' => $id, 'u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : ['nombre' => (string) $row['nombre'], 'tipo' => (string) $row['tipo'], 'activa' => (bool) $row['activa']];
    }
}
