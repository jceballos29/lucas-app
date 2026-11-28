<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** SQL del segundo factor (M7): secreto TOTP cifrado en `usuarios` y códigos de recuperación de un solo uso. */
final class DosPasosRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Secreto cifrado y último paso aceptado de un usuario con 2FA activo; null si no lo tiene.
     *
     * @return array{secreto: string, ultimo_paso: int|null}|null
     */
    public function datos(int $idUsuario): ?array
    {
        $stmt = $this->pdo->prepare('SELECT totp_secreto, totp_ultimo_paso FROM usuarios WHERE id_usuario = :u AND totp_activado_en IS NOT NULL AND totp_secreto IS NOT NULL');
        $stmt->execute(['u' => $idUsuario]);
        $fila = $stmt->fetch();

        return $fila === false ? null : ['secreto' => (string) $fila['totp_secreto'], 'ultimo_paso' => $fila['totp_ultimo_paso'] === null ? null : (int) $fila['totp_ultimo_paso']];
    }

    /** Activa el 2FA con ese secreto (ya cifrado); `$paso` es el del código que lo confirmó, para que no pueda repetirse. */
    public function activar(int $idUsuario, string $secretoCifrado, int $paso): void
    {
        $this->pdo->prepare('UPDATE usuarios SET totp_secreto = :s, totp_activado_en = NOW(), totp_ultimo_paso = :p WHERE id_usuario = :u')
            ->execute(['s' => $secretoCifrado, 'p' => $paso, 'u' => $idUsuario]);
    }

    public function desactivar(int $idUsuario): void
    {
        $this->pdo->prepare('UPDATE usuarios SET totp_secreto = NULL, totp_activado_en = NULL, totp_ultimo_paso = NULL WHERE id_usuario = :u')->execute(['u' => $idUsuario]);
        $this->pdo->prepare('DELETE FROM codigos_recuperacion WHERE id_usuario = :u')->execute(['u' => $idUsuario]);
    }

    /** Marca el paso como usado SOLO si es posterior al último aceptado (atómico: dos peticiones con el mismo código no pasan las dos). */
    public function consumirPaso(int $idUsuario, int $paso): bool
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET totp_ultimo_paso = :p WHERE id_usuario = :u AND (totp_ultimo_paso IS NULL OR totp_ultimo_paso < :p2)');
        $stmt->execute(['p' => $paso, 'p2' => $paso, 'u' => $idUsuario]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Sustituye todos los códigos de recuperación por estos (hashes).
     *
     * @param list<string> $hashes
     */
    public function reemplazarCodigos(int $idUsuario, array $hashes): void
    {
        $this->pdo->prepare('DELETE FROM codigos_recuperacion WHERE id_usuario = :u')->execute(['u' => $idUsuario]);
        $insert = $this->pdo->prepare('INSERT INTO codigos_recuperacion (id_usuario, codigo_hash) VALUES (:u, :h)');
        foreach ($hashes as $hash) {
            $insert->execute(['u' => $idUsuario, 'h' => $hash]);
        }
    }

    /** Gasta un código de recuperación (un solo uso). true si existía y no estaba usado. */
    public function consumirCodigo(int $idUsuario, string $hash): bool
    {
        $stmt = $this->pdo->prepare('UPDATE codigos_recuperacion SET usado_en = NOW() WHERE id_usuario = :u AND codigo_hash = :h AND usado_en IS NULL');
        $stmt->execute(['u' => $idUsuario, 'h' => $hash]);

        return $stmt->rowCount() === 1;
    }

    public function codigosDisponibles(int $idUsuario): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM codigos_recuperacion WHERE id_usuario = :u AND usado_en IS NULL');
        $stmt->execute(['u' => $idUsuario]);

        return (int) $stmt->fetchColumn();
    }
}
