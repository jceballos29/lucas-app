<?php

declare(strict_types=1);

/**
 * Corta la petición con un 403 si no viene del propio sitio. Se llama al principio de todo
 * procesamiento POST (create.php, edit.php y delete.php): es una capa extra contra peticiones
 * enviadas desde otras páginas, no sustituye al token CSRF de M6.
 *
 * Responde "¿quién pide?" antes que "¿qué pide?": una petición de origen desconocido no recibe
 * nada, ni siquiera la validación de lo que envía. Esta función SÍ lee $_SERVER y el entorno y
 * responde (a diferencia de las funciones puras de app/Security/origin.php, que son las que la sostienen).
 *
 * Si APP_ORIGIN falta o no es un origen válido es un error de configuración, no culpa del
 * visitante: responde 500 y lo registra, en lugar de un 403.
 */
function reject_foreign_origin(): void
{
    try {
        $allowed = is_allowed_origin(
            $_SERVER['HTTP_ORIGIN'] ?? null,
            $_SERVER['HTTP_REFERER'] ?? null,
            \App\Config\Environment::get('APP_ORIGIN'),
        );
    } catch (RuntimeException $error) {
        \App\Logging\Logger::exception('config', $error);
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Ocurrió un error inesperado. Inténtalo más tarde.\n";
        exit;
    }

    if (!$allowed) {
        \App\Http\Response::plain(403, "Origen no permitido. Abre la aplicación con la dirección configurada en APP_ORIGIN.\n");
    }
}
