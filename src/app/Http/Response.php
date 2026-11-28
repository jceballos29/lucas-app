<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Respuestas HTTP sencillas que cortan la petición (`exit`): una redirección o un texto plano con su código.
 */
final class Response
{
    /** Redirige (302) y termina. */
    public static function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Responde con texto plano y termina.
     *
     * @param array<string> $headers Cabeceras extra (p. ej. ['Allow: POST']).
     */
    public static function plain(int $status, string $text, array $headers = []): never
    {
        // E3/U5: a quien navega (el navegador pide text/html) un error le sale como página con la marca y un botón «Volver»; las
        // herramientas (curl, scripts, fetch sin Accept) siguen recibiendo texto plano, que es lo que esperan.
        if ($status >= 400 && str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
            self::pagina($status, trim($text), $headers);
        }

        http_response_code($status);

        foreach ($headers as $header) {
            header($header);
        }

        header('Content-Type: text/plain; charset=UTF-8');
        echo $text;
        exit;
    }

    /**
     * Página de error con la marca de Lucas (sin sesión ni base de datos: debe funcionar justo cuando algo falla).
     *
     * @param array<string> $headers
     */
    public static function pagina(int $status, string $mensaje, array $headers = []): never
    {
        http_response_code($status);

        foreach ($headers as $header) {
            header($header);
        }

        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');

        $titulo = match (true) {
            $status === 400 => 'Solicitud no válida',
            $status === 403 => 'Acceso denegado',
            $status === 404 => 'No encontrado',
            $status === 405 => 'Método no permitido',
            $status === 413 => 'Archivo demasiado grande',
            $status === 429 => 'Demasiados intentos',
            $status >= 500 => 'Algo salió mal',
            default => 'No se pudo completar',
        };

        try {
            \App\View\View::page('errors/error', ['estado' => $status, 'titulo' => $titulo, 'mensaje' => $mensaje, 'volver' => self::paginaAnterior()], $titulo, null, 'plain');
        } catch (\Throwable) {
            echo $titulo . "\n" . $mensaje . "\n"; // si ni la página de error se puede pintar, al menos el texto
        }

        exit;
    }

    /** La página de la que venía, si es de este mismo sitio; si no, el inicio. */
    private static function paginaAnterior(): string
    {
        $ref = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if (is_array($ref) && ($ref['host'] ?? '') !== '' && ($ref['host'] . (isset($ref['port']) ? ':' . $ref['port'] : '')) === $host) {
            $ruta = ($ref['path'] ?? '/') . (isset($ref['query']) ? '?' . $ref['query'] : '');

            // Solo una ruta de ESTE sitio: una sola «/» inicial y a continuación nada de «/» ni «\» (`//otro.com` o `/\otro.com` serían una
            // redirección abierta: el navegador los toma por un sitio ajeno).
            if (preg_match('#^/(?![/\\\\])#', $ruta) === 1) {
                return $ruta;
            }
        }

        return '/';
    }

    /** 405 con `Allow: POST` si la petición no es un POST. Para las páginas que solo actúan por POST. */
    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::plain(405, "Método no permitido. Usa POST.\n", ['Allow: POST']);
        }
    }
}
