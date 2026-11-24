<?php

declare(strict_types=1);

namespace App\View;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/** Fechas legibles en español. Las fechas de la BD están en UTC y aquí se muestran en la zona de la aplicación. */
final class Fecha
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** «Dic», «Ene»... para los ejes de los gráficos (1 a 12). */
    public static function mesCorto(int $mes): string
    {
        return ucfirst(substr(self::MESES[($mes - 1) % 12], 0, 3));
    }

    /** «noviembre 2026». */
    public static function mesAnio(DateTimeImmutable $fecha): string
    {
        return self::MESES[(int) $fecha->format('n') - 1] . ' ' . $fecha->format('Y');
    }

    /** «04 nov» (de una fecha UTC de la BD). Si no se puede leer, devuelve el texto original. */
    public static function dia(string $utc): string
    {
        $fecha = self::local($utc);

        return $fecha === null ? $utc : $fecha->format('d') . ' ' . strtolower(self::mesCorto((int) $fecha->format('n')));
    }

    /** «04/11/2026» (de una fecha UTC de la BD). */
    public static function numerica(string $utc): string
    {
        return self::local($utc)?->format('d/m/Y') ?? $utc;
    }

    /** «04/11/2026» de una fecha SIN hora (columna DATE, p. ej. la de un movimiento): no hay zona horaria que convertir. */
    public static function simple(string $ymd): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) === 1 ? "$m[3]/$m[2]/$m[1]" : $ymd;
    }

    private static function local(string $utc): ?DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } catch (Exception) {
            return null;
        }
    }
}
