<?php

declare(strict_types=1);

namespace App\Http;

use App\Logging\Logger;
use RuntimeException;
use Throwable;

/**
 * El manejador central de errores de las páginas (R6 de docs/AUDITORIA.md). Antes cada controlador envolvía su trabajo en un
 * `try { … } catch (RuntimeException $error) { self::fail($error); }` idéntico (más de 30 veces). Ahora basta con dejar que la excepción suba:
 * {@see self::registrar()} (la llama `bootstrap.php`) instala un `set_exception_handler` que traduce los fallos de la capa de datos y de
 * configuración con {@see Failure} (503 sin conexión, 500 en una consulta o en la configuración) y responde con texto plano.
 *
 * Cualquier OTRA excepción o error (un fallo de programación, un `TypeError`) también termina en una respuesta 500 con un aviso genérico y
 * queda en el log, en vez de una página en blanco. El detalle técnico nunca llega al visitante.
 *
 * Lo que sigue necesitando un `catch` propio son las pantallas que repintan su formulario con el aviso (login, registro, recuperar contraseña)
 * y la exportación en curso (ya se enviaron cabeceras).
 */
final class ManejadorErrores
{
    public const MENSAJE_POR_DEFECTO = 'No se pudo completar la operación. Inténtalo más tarde.';
    private const MENSAJE_INESPERADO = 'Ocurrió un error inesperado. Inténtalo más tarde.';

    private static string $mensajeDeConsulta = self::MENSAJE_POR_DEFECTO;

    /** Instala el manejador. En consola no se instala: allí un fallo debe verse como excepción (y terminar con código distinto de 0). */
    public static function registrar(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        set_exception_handler(static function (Throwable $error): void {
            self::manejar($error);
        });
    }

    /** Cambia el aviso para un fallo de consulta en ESTA petición (p. ej. «No se pudo exportar…»). */
    public static function mensajeDeConsulta(string $mensaje): void
    {
        self::$mensajeDeConsulta = $mensaje;
    }

    /** Vuelve al aviso por defecto (para las pruebas). */
    public static function reiniciar(): void
    {
        self::$mensajeDeConsulta = self::MENSAJE_POR_DEFECTO;
    }

    /**
     * Código HTTP y aviso para el visitante, y deja el detalle en el log. Puro respecto a la respuesta (no imprime nada): lo usan
     * {@see self::manejar()} y las pruebas.
     *
     * @return array{0: int, 1: string}
     */
    public static function traducir(Throwable $error): array
    {
        if ($error instanceof RuntimeException) {
            return Failure::describe($error, self::$mensajeDeConsulta);
        }

        Logger::exception('error', $error);

        return [500, self::MENSAJE_INESPERADO];
    }

    public static function manejar(Throwable $error): void
    {
        [$estado, $mensaje] = self::traducir($error);

        // Con la respuesta ya empezada no se puede cambiar el código ni añadir cabeceras: el detalle ya quedó en el log.
        if (headers_sent()) {
            return;
        }

        Response::plain($estado, $mensaje . "\n", ['Cache-Control: no-store']);
    }
}
