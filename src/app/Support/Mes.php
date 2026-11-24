<?php

declare(strict_types=1);

namespace App\Support;

use App\View\Fecha;
use DateTimeImmutable;

/** Un mes y un año (los presupuestos son por mes). Todo es puro: la fecha de «hoy» llega de fuera. */
final class Mes
{
    public const ANIO_MIN = 2000;
    public const ANIO_MAX = 2100;

    /**
     * El mes pedido en la URL (`mes`, `anio`); si falta o no es válido, el mes de $hoy.
     *
     * @param array<string, mixed> $src Normalmente $_GET.
     * @return array{mes: int, anio: int}
     */
    public static function desde(array $src, DateTimeImmutable $hoy): array
    {
        $mes = self::entero($src['mes'] ?? null);
        $anio = self::entero($src['anio'] ?? null);

        if ($mes === null || $anio === null || !self::valido($mes, $anio)) {
            return ['mes' => (int) $hoy->format('n'), 'anio' => (int) $hoy->format('Y')];
        }

        return ['mes' => $mes, 'anio' => $anio];
    }

    public static function valido(int $mes, int $anio): bool
    {
        return $mes >= 1 && $mes <= 12 && $anio >= self::ANIO_MIN && $anio <= self::ANIO_MAX;
    }

    /** @return array{mes: int, anio: int} */
    public static function anterior(int $mes, int $anio): array
    {
        return $mes === 1 ? ['mes' => 12, 'anio' => max(self::ANIO_MIN, $anio - 1)] : ['mes' => $mes - 1, 'anio' => $anio];
    }

    /** @return array{mes: int, anio: int} */
    public static function siguiente(int $mes, int $anio): array
    {
        return $mes === 12 ? ['mes' => 1, 'anio' => min(self::ANIO_MAX, $anio + 1)] : ['mes' => $mes + 1, 'anio' => $anio];
    }

    /**
     * Primer día del mes y primer día del siguiente (`fecha >= desde AND fecha < hasta`: usa el índice por fecha).
     *
     * @return array{0: string, 1: string}
     */
    public static function limites(int $mes, int $anio): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);

        return [$desde, (new DateTimeImmutable($desde))->modify('+1 month')->format('Y-m-d')];
    }

    /** «Noviembre 2026». */
    public static function etiqueta(int $mes, int $anio): string
    {
        return ucfirst(Fecha::mesNombre($mes)) . ' ' . $anio;
    }

    /**
     * El mes de una fecha `AAAA-MM-DD`, o null si no es una fecha.
     *
     * @return array{mes: int, anio: int}|null
     */
    public static function deFecha(string $ymd): ?array
    {
        return preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $ymd, $m) === 1 && self::valido((int) $m[2], (int) $m[1])
            ? ['mes' => (int) $m[2], 'anio' => (int) $m[1]]
            : null;
    }

    private static function entero(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && strlen($value) <= 4 ? (int) $value : null;
    }
}
