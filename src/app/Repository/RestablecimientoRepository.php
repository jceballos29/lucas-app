<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Enlaces de restablecimiento de contraseña (RF-04). Solo se guarda el HASH del token: quien lea la BD no puede usar los
 * enlaces. La caducidad la decide MySQL (UTC), igual que los intentos de acceso.
 */
final class RestablecimientoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Anula los enlaces pendientes del usuario y crea uno nuevo (solo el último enlace pedido sirve). */
    public function replaceFor(int $userId, string $tokenHash, int $minutes): void
    {
        $this->pdo->prepare('DELETE FROM restablecimientos WHERE id_usuario = :u AND usado_en IS NULL')->execute(['u' => $userId]);
        $this->pdo->prepare('INSERT INTO restablecimientos (id_usuario, token_hash, expira_en) VALUES (:u, :h, NOW() + INTERVAL :m MINUTE)')
            ->execute(['u' => $userId, 'h' => $tokenHash, 'm' => $minutes]);
    }

    /** ¿Existe un enlace vigente (sin usar y sin caducar) con ese hash? */
    public function isValid(string $tokenHash): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM restablecimientos WHERE token_hash = :h AND usado_en IS NULL AND expira_en > NOW()');
        $stmt->execute(['h' => $tokenHash]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Lee el enlace vigente BLOQUEANDO su fila (`FOR UPDATE`): dos peticiones con el mismo enlace no pueden usarlo a la vez.
     * Solo dentro de una transacción.
     *
     * @return array{id: int, userId: int}|null null si no existe, ya se usó o caducó
     */
    public function lockValid(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_restablecimiento, id_usuario FROM restablecimientos WHERE token_hash = :h AND usado_en IS NULL AND expira_en > NOW() FOR UPDATE');
        $stmt->execute(['h' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id_restablecimiento'], 'userId' => (int) $row['id_usuario']];
    }

    public function markUsed(int $id): void
    {
        $this->pdo->prepare('UPDATE restablecimientos SET usado_en = NOW() WHERE id_restablecimiento = :id')->execute(['id' => $id]);
    }

    /** Borra todos los enlaces del usuario menos el indicado (al usar uno, los demás dejan de valer). */
    public function deleteOthers(int $userId, int $exceptId): void
    {
        $this->pdo->prepare('DELETE FROM restablecimientos WHERE id_usuario = :u AND id_restablecimiento <> :id')->execute(['u' => $userId, 'id' => $exceptId]);
    }

    /** Borra lo caducado o usado hace tiempo (mantenimiento). */
    public function purge(): void
    {
        $this->pdo->exec('DELETE FROM restablecimientos WHERE expira_en < (NOW() - INTERVAL 1 DAY)');
    }
}
