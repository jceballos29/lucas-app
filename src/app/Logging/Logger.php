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
    public static function exception(string $step, Throwable $error): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? 'CLI';
        $targetError = $error->getPrevious() ?? $error;
        $code = $targetError->getCode();
        $detail = $targetError->getMessage();

        // En errores de consulta getCode() es el SQLSTATE como TEXTO ('42S02'), por eso se compara con '' y 0.
        $formattedLog = $code !== 0 && $code !== ''
            ? sprintf('[%s] %s: (%s) %s', $step, $requestUri, $code, $detail)
            : sprintf('[%s] %s: %s', $step, $requestUri, $detail);

        error_log($formattedLog);
    }
}
