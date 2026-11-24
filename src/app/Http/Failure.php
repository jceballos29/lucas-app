<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\DatabaseConnectionException;
use App\Logging\Logger;
use PDOException;
use Throwable;

/**
 * Traduce un fallo de la capa de datos o de la configuración en un código HTTP y un aviso para el visitante,
 * y lo registra en el log. Como DatabaseConnectionException y PDOException extienden RuntimeException, a cada
 * controlador le basta un solo `catch (RuntimeException)`.
 */
final class Failure
{
    /**
     *  - DatabaseConnectionException (sin conexión)       -> 503, etiqueta [connection]
     *  - PDOException (la consulta falló)                 -> 500, etiqueta [query], con $queryMessage
     *  - cualquier otra RuntimeException (configuración)  -> 500, etiqueta [config]
     *
     * El aviso nunca lleva detalles técnicos: el detalle va solo al log.
     *
     * @param Throwable $error El fallo capturado.
     * @param string $queryMessage Aviso para el caso de un error de consulta (cambia según la página).
     * @return array{0: int, 1: string} [código HTTP, aviso para el visitante]
     */
    public static function describe(Throwable $error, string $queryMessage): array
    {
        if ($error instanceof DatabaseConnectionException) {
            Logger::exception('connection', $error);

            return [503, 'No se pudo conectar a la base de datos. Inténtalo más tarde.'];
        }

        if ($error instanceof PDOException) {
            Logger::exception('query', $error);

            return [500, $queryMessage];
        }

        Logger::exception('config', $error);

        return [500, 'Ocurrió un error inesperado. Inténtalo más tarde.'];
    }
}
