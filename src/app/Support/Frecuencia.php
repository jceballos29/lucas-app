<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Cálculo puro de fechas de vencimiento y proyección mensual para movimientos recurrentes (Fase 9).
 */
final class Frecuencia
{
    public const TIPOS = [
        'semanal' => 'Semanal (cada 7 días)',
        'quincenal' => 'Quincenal (cada 15 días)',
        'mensual' => 'Mensual (cada mes)',
        'anual' => 'Anual (cada año)',
    ];

    public const ETIQUETAS = [
        'semanal' => 'Semanal',
        'quincenal' => 'Quincenal',
        'mensual' => 'Mensual',
        'anual' => 'Anual',
    ];

    /**
     * Calcula la próxima fecha (`AAAA-MM-DD`) después de `$fechaActual` según la frecuencia.
     * En frecuencias mensuales y anuales, si el día original es 29, 30 o 31 y el mes destino tiene menos días (p. ej.
     * 31 de enero + 1 mes → 28/29 de febrero), se ajusta al último día real del mes destino sin desbordarse a marzo.
     */
    public static function siguiente(string $fechaActual, string $frecuencia): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fechaActual, $m) !== 1) {
            return $fechaActual;
        }

        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $base = new DateTimeImmutable($fechaActual);

        return match ($frecuencia) {
            'semanal' => $base->modify('+7 days')->format('Y-m-d'),
            'quincenal' => $base->modify('+15 days')->format('Y-m-d'),
            'mensual' => self::sumarMeses($y, $mo, $d, 1),
            'anual' => self::sumarMeses($y, $mo, $d, 12),
            default => $fechaActual,
        };
    }

    /**
     * Equivalente mensual estimado en centavos enteros (sin float) para el resumen de compromisos fijos del mes:
     *  - semanal: monto × 4
     *  - quincenal: monto × 2
     *  - mensual: monto × 1
     *  - anual: monto ÷ 12 (redondeo entero)
     */
    public static function equivalenteMensual(string $monto, string $frecuencia): string
    {
        $centavos = Dinero::aCentavos($monto);

        $mensual = match ($frecuencia) {
            'semanal' => $centavos * 4,
            'quincenal' => $centavos * 2,
            'anual' => intdiv($centavos + 6, 12),
            default => $centavos,
        };

        return Dinero::deCentavos($mensual);
    }

    private static function sumarMeses(int $y, int $mo, int $d, int $meses): string
    {
        $totalMeses = ($y * 12 + ($mo - 1)) + $meses;
        $ny = intdiv($totalMeses, 12);
        $nmo = ($totalMeses % 12) + 1;
        $ultimoDia = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $ny, $nmo)))->format('t');
        $nd = min($d, $ultimoDia);

        return sprintf('%04d-%02d-%02d', $ny, $nmo, $nd);
    }
}
