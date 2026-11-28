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

    /** Solo pruebas: comprueba si ya se registró el envío de una notificación con esa clave. */
    public function existe(string $clave): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM notificaciones_enviadas WHERE clave_dedup = :c');
        $stmt->execute(['c' => $clave]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Cuántas claves que empiezan por `$prefijo` se reservaron en los últimos `$segundos` (el reloj lo pone MySQL, UTC). Con `$userId`
     * solo cuentan las de ese usuario. Sirve de contador para los límites anti-abuso (p. ej. invitaciones por correo).
     */
    public function contarPorPrefijo(string $prefijo, int $segundos, ?int $userId = null): int
    {
        $sql = "SELECT COUNT(*) FROM notificaciones_enviadas WHERE clave_dedup LIKE :p ESCAPE '!' AND enviado_en >= (NOW() - INTERVAL :s SECOND)" . ($userId !== null ? ' AND id_usuario = :u' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('p', str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefijo) . '%');
        $stmt->bindValue('s', $segundos, PDO::PARAM_INT);
        if ($userId !== null) {
            $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
