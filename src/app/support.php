<?php

declare(strict_types=1);

/**
 * Escapa un valor para su uso seguro en HTML.
 *
 * @param mixed $value El valor a escapar.
 * @return string El valor escapado.
 */
function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Convierte una fecha guardada en UTC (así la guarda la BD) a la zona horaria de la aplicación.
 *
 * Ejemplo (con date.timezone = America/Bogota):
 *     format_utc_datetime('2026-11-19 14:08:36');                     // '2026-11-19 09:08:36'
 *     format_utc_datetime('2026-11-19 14:08:36', 'Europe/Madrid');    // '2026-11-19 15:08:36'
 *
 * @param string $utc Fecha 'Y-m-d H:i:s' en UTC, tal como sale de la BD.
 * @param string|null $timezone Zona de destino; por defecto la de PHP (date.timezone).
 * @return string La fecha en la zona de destino, con el mismo formato. Si la fecha o la zona no
 *                son válidas devuelve el texto original en vez de romper la página.
 */
function format_utc_datetime(string $utc, ?string $timezone = null): string
{
    try {
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $zone = new DateTimeZone($timezone ?? date_default_timezone_get());
    } catch (Exception) {
        return $utc;
    }

    return $date->setTimezone($zone)->format('Y-m-d H:i:s');
}

/**
 * URL de un archivo estático de public/ con la fecha de su última modificación (`/css/app.css?v=1730000000`): al
 * cambiar el archivo cambia la URL y el navegador no sirve una copia vieja de su caché.
 *
 * @param string $path Ruta relativa a public/ (p. ej. 'css/app.css'); solo letras, números, / . _ -
 */
function asset(string $path): string
{
    $file = dirname(__DIR__) . '/public/' . $path;
    $version = preg_match('#^[A-Za-z0-9/._-]+$#', $path) === 1 && is_file($file) ? (int) filemtime($file) : 0;

    return '/' . $path . ($version > 0 ? '?v=' . $version : '');
}
