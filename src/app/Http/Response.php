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
        http_response_code($status);

        foreach ($headers as $header) {
            header($header);
        }

        header('Content-Type: text/plain; charset=UTF-8');
        echo $text;
        exit;
    }

    /** 405 con `Allow: POST` si la petición no es un POST. Para las páginas que solo actúan por POST. */
    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::plain(405, "Método no permitido. Usa POST.\n", ['Allow: POST']);
        }
    }
}
