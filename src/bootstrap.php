<?php

declare(strict_types=1);

/**
 * Arranque común de la aplicación: lo primero que carga cada página de public/ y cada script de consola.
 *
 * Carga el autoload de Composer (las clases de app/ por su namespace y las funciones de app/support.php y
 * app/Security/*.php). La carpeta vendor/ no se sube a git: la crea `composer install` (el contenedor php lo
 * ejecuta solo al arrancar si falta; ver docker/php/entrypoint.sh).
 */

$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    error_log('[config] Falta vendor/autoload.php: ejecuta «docker compose exec php composer install».');

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Ocurrió un error inesperado. Inténtalo más tarde.\n";
    } else {
        fwrite(STDERR, "Falta vendor/autoload.php: ejecuta «docker compose exec php composer install».\n");
    }

    exit(1);
}

require_once $autoload;
