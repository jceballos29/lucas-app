<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Enlaces de verificación de correo (A1). Solo se guarda el HASH del token: quien lea la BD no puede usar los enlaces. La caducidad la
 * decide MySQL (UTC), igual que en los restablecimientos de contraseña.
 */
final class VerificacionCorreoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Anula los enlaces pendientes del usuario y crea uno nuevo (solo el último enlace enviado sirve). */
    public function replaceFor(int $userId, string $correo, string $tokenHash, int $hours): void
    {
        $this->pdo->prepare('DELETE FROM verificaciones_correo WHERE id_usuario = :u AND usado_en IS NULL')->execute(['u' => $userId]);
        $this->pdo->prepare('INSERT INTO verificaciones_correo (id_usuario, correo, token_hash, expira_en) VALUES (:u, :c, :h, NOW() + INTERVAL :h2 HOUR)')
            ->execute(['u' => $userId, 'c' => $correo, 'h' => $tokenHash, 'h2' => $hours]);
    }

    public function isValid(string $tokenHash): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM verificaciones_correo WHERE token_hash = :h AND usado_en IS NULL AND expira_en > NOW()');
        $stmt->execute(['h' => $tokenHash]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Lee el enlace vigente BLOQUEANDO su fila (`FOR UPDATE`): dos peticiones con el mismo enlace no pueden usarlo a la vez.
     * Solo dentro de una transacción.
     *
     * @return array{id: int, userId: int, correo: string}|null null si no existe, ya se usó o caducó
     */
    public function lockValid(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_verificacion, id_usuario, correo FROM verificaciones_correo WHERE token_hash = :h AND usado_en IS NULL AND expira_en > NOW() FOR UPDATE');
        $stmt->execute(['h' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id_verificacion'], 'userId' => (int) $row['id_usuario'], 'correo' => (string) $row['correo']];
    }

    public function markUsed(int $id): void
    {
        $this->pdo->prepare('UPDATE verificaciones_correo SET usado_en = NOW() WHERE id_verificacion = :id')->execute(['id' => $id]);
    }

    /** Borra lo caducado o usado hace tiempo (mantenimiento). */
    public function purge(): void
    {
        $this->pdo->exec('DELETE FROM verificaciones_correo WHERE expira_en < (NOW() - INTERVAL 1 DAY)');
    }
}
