<?php

declare(strict_types=1);

namespace App\Logging;

use Throwable;

/**
 * Registro de errores. Va al log de PHP (docker compose logs php), nunca a la pantalla del visitante.
 */
final class Logger
{
    /**
     * Registra una excepción: `[paso] ruta: (código) detalle`, usando la excepción original si la hay
     * (getPrevious()): una DatabaseConnectionException envuelve al PDOException que dice qué pasó de verdad.
     *
     * @param string $step El paso en el que ocurrió (connection, query, config...).
     * @param Throwable $error La excepción a registrar.
     */
    public static function format(string $step, string $requestUri, Throwable $error): string
    {
        $targetError = $error->getPrevious() ?? $error;
        $code = self::sanitize((string) $targetError->getCode());
        $uri = self::sanitize($requestUri);
        $detail = self::sanitize($targetError->getMessage());

        // En errores de consulta getCode() es el SQLSTATE como TEXTO ('42S02'), por eso se compara con '' y '0'.
        return $code !== '0' && $code !== ''
            ? sprintf('[%s] %s: (%s) %s', self::sanitize($step), $uri, $code, $detail)
            : sprintf('[%s] %s: %s', self::sanitize($step), $uri, $detail);
    }

    public static function exception(string $step, Throwable $error): void
    {
        error_log(self::format($step, (string) ($_SERVER['REQUEST_URI'] ?? 'CLI'), $error));
    }

    /** Elimina saltos de línea y caracteres de control para evitar inyección de logs o secuencias ANSI (CWE-117). */
    public static function sanitize(string $text): string
    {
        return preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';
    }
}
