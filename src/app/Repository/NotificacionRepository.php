<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Único sitio con el SQL de `notificaciones_enviadas`: historial y deduplicación de los correos proactivos (Fase 20). */
final class NotificacionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Reserva la clave de deduplicación. La clave UNIQUE decide, también ante dos peticiones simultáneas.
     *
     * @return bool true si esta llamada la reservó (hay que enviar el correo); false si ya existía (ya se envió).
     */
    public function reservar(int $userId, ?int $espacioId, string $clave): bool
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO notificaciones_enviadas (id_usuario, id_espacio, clave_dedup) VALUES (:u, :e, :c)');
        $stmt->execute(['u' => $userId, 'e' => $espacioId, 'c' => $clave]);

        return $stmt->rowCount() === 1;
    }

    /** Libera una clave cuando el correo no pudo enviarse, para que se reintente en el próximo gasto o ejecución. */
    public function liberar(string $clave): void
    {
        $this->pdo->prepare('DELETE FROM notificaciones_enviadas WHERE clave_dedup = :c')->execute(['c' => $clave]);
    }

    public function existe(string $clave): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM notificaciones_enviadas WHERE clave_dedup = :c');
        $stmt->execute(['c' => $clave]);

        return $stmt->fetchColumn() !== false;
    }
}
