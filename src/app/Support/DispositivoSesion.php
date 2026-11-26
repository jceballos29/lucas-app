<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Traduce una cadena `User-Agent` a una descripción legible del navegador y sistema operativo (Fase 17).
 * Puro y sin dependencias externas.
 */
final class DispositivoSesion
{
    public static function describir(string $userAgent): string
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return 'Dispositivo desconocido';
        }

        $navegador = match (true) {
            str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'CriOS/') || str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') && str_contains($ua, 'Version/') => 'Safari',
            str_contains($ua, 'curl/') => 'cURL / CLI',
            default => 'Navegador web',
        };

        $so = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return $so !== '' ? "$navegador en $so" : $navegador;
    }

    /**
     * Icono de `Ui::icon()` representativo del dispositivo.
     */
    public static function icono(string $userAgent): string
    {
        return 'laptop';
    }
}
