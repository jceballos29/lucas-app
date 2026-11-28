<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Único sitio con el SQL de `sesiones_usuario` (Fase 17).
 *
 * `id_sesion` guarda SIEMPRE el hash SHA-256 (64 hex) del `session_id()` de PHP, nunca el id de sesión en claro:
 * aunque alguien leyera la tabla, no podría construir una cookie `lucas_session` válida.
 */
final class SesionRepository
{
    /** M5: sesiones simultáneas por usuario; al abrir una más se cierra la menos usada. */
    public const MAX_SESIONES = 10;
    /** M5: vida máxima de una sesión aunque haya actividad continua (hay que volver a iniciar sesión). */
    public const VIDA_MAXIMA_SEGUNDOS = 43200;
    /** M5: `ultimo_uso` se escribe como máximo una vez por este intervalo, no en cada petición. */
    public const REFRESCO_SEGUNDOS = 60;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function hashId(string $rawSessionId): string
    {
        return hash('sha256', $rawSessionId);
    }

    /**
     * Registra o actualiza la sesión activa del usuario y limpia sesiones expiradas por inactividad.
     */
    public function registrar(int $userId, string $sessionHash, string $ip, string $userAgent, int $ttlSeconds = 1800): void
    {
        $ipLimpia = mb_substr(trim($ip) ?: '0.0.0.0', 0, 45);
        $uaLimpio = mb_substr(trim($userAgent) ?: 'Desconocido', 0, 255);

        $this->purgarExpiradas($userId, $ttlSeconds);

        $stmt = $this->pdo->prepare(
            'INSERT INTO sesiones_usuario (id_sesion, id_usuario, ip, user_agent, creado_en, ultimo_uso)
             VALUES (:id, :u, :ip, :ua, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE ip = VALUES(ip), user_agent = VALUES(user_agent), ultimo_uso = CURRENT_TIMESTAMP',
        );
        $stmt->execute([
            'id' => $sessionHash,
            'u' => $userId,
            'ip' => $ipLimpia,
            'ua' => $uaLimpio,
        ]);

        $this->limitarSesiones($userId, $sessionHash);
    }

    /** Deja como máximo MAX_SESIONES sesiones del usuario: cierra las menos usadas (nunca la que acaba de abrirse). */
    private function limitarSesiones(int $userId, string $actualHash): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_sesion FROM sesiones_usuario WHERE id_usuario = :u AND id_sesion <> :actual
             ORDER BY ultimo_uso DESC, creado_en DESC LIMIT 18446744073709551615 OFFSET ' . (self::MAX_SESIONES - 1),
        );
        $stmt->execute(['u' => $userId, 'actual' => $actualHash]);
        $sobran = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($sobran === []) {
            return;
        }

        $marcas = implode(',', array_fill(0, count($sobran), '?'));
        $this->pdo->prepare("DELETE FROM sesiones_usuario WHERE id_usuario = ? AND id_sesion IN ($marcas)")
            ->execute([$userId, ...$sobran]);
    }

    /**
     * Comprueba si la sesión sigue activa en la tabla para ese usuario y no superó su vida máxima (M5).
     * Una consulta por petición; `ultimo_uso` solo se escribe si pasó más de REFRESCO_SEGUNDOS desde la última vez.
     */
    public function validarYRenovar(int $userId, string $sessionHash): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT creado_en > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :vida SECOND) AS vigente,
                    ultimo_uso < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :refresco SECOND) AS refrescar
             FROM sesiones_usuario WHERE id_sesion = :id AND id_usuario = :u',
        );
        $stmt->bindValue('vida', self::VIDA_MAXIMA_SEGUNDOS, PDO::PARAM_INT);
        $stmt->bindValue('refresco', self::REFRESCO_SEGUNDOS, PDO::PARAM_INT);
        $stmt->bindValue('id', $sessionHash);
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $fila = $stmt->fetch();
        if ($fila === false) {
            return false;
        }

        if ((int) $fila['vigente'] !== 1) {
            $this->eliminarPorHash($sessionHash);

            return false;
        }

        if ((int) $fila['refrescar'] === 1) {
            $this->pdo->prepare(
                'UPDATE sesiones_usuario SET ultimo_uso = CURRENT_TIMESTAMP WHERE id_sesion = :id AND id_usuario = :u',
            )->execute(['id' => $sessionHash, 'u' => $userId]);
        }

        return true;
    }

    /**
     * Reemplaza el hash de sesión cuando la sesión actual rota su ID (`session_regenerate_id(true)` al cambiar contraseña).
     */
    public function rotarId(int $userId, string $oldHash, string $newHash, string $ip, string $userAgent): void
    {
        $this->eliminarPorHash($oldHash);
        $this->registrar($userId, $newHash, $ip, $userAgent);
    }

    /**
     * Lista las sesiones vigentes del usuario (las más recientes primero).
     *
     * @return list<array{id_sesion: string, ip: string, user_agent: string, creado_en: string, ultimo_uso: string}>
     */
    public function listByUser(int $userId, int $ttlSeconds = 1800): array
    {
        $this->purgarExpiradas($userId, $ttlSeconds);

        $stmt = $this->pdo->prepare(
            'SELECT id_sesion, ip, user_agent, creado_en, ultimo_uso
             FROM sesiones_usuario
             WHERE id_usuario = :u
             ORDER BY ultimo_uso DESC, creado_en DESC',
        );
        $stmt->execute(['u' => $userId]);

        return array_map(static fn (array $r): array => [
            'id_sesion' => (string) $r['id_sesion'],
            'ip' => (string) $r['ip'],
            'user_agent' => (string) $r['user_agent'],
            'creado_en' => (string) $r['creado_en'],
            'ultimo_uso' => (string) $r['ultimo_uso'],
        ], $stmt->fetchAll());
    }

    /**
     * Cierra una sesión específica del usuario (verifica propiedad `id_usuario = :u`).
     */
    public function eliminarDeUsuario(int $userId, string $sessionHash): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM sesiones_usuario WHERE id_sesion = :id AND id_usuario = :u',
        );
        $stmt->execute(['id' => $sessionHash, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Cierra todas las sesiones del usuario excepto la actual (`$exceptHash`).
     */
    public function eliminarOtrasDeUsuario(int $userId, string $exceptHash): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM sesiones_usuario WHERE id_usuario = :u AND id_sesion <> :actual',
        );
        $stmt->execute(['u' => $userId, 'actual' => $exceptHash]);

        return $stmt->rowCount();
    }

    public function eliminarPorHash(string $sessionHash): void
    {
        $this->pdo->prepare('DELETE FROM sesiones_usuario WHERE id_sesion = :id')->execute(['id' => $sessionHash]);
    }

    public function purgarExpiradas(int $userId, int $ttlSeconds = 1800): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM sesiones_usuario WHERE id_usuario = :u AND ultimo_uso < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :ttl SECOND)',
        );
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        $stmt->bindValue('ttl', max(60, $ttlSeconds), PDO::PARAM_INT);
        $stmt->execute();
    }
}
