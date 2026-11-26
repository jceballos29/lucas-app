<?php

declare(strict_types=1);

namespace App\Tarjetas;

use DateTimeImmutable;

/**
 * Cálculo puro de las próximas fechas de corte y límite de pago de una tarjeta de crédito (Fase 15).
 */
final class CicloTarjeta
{
    /**
     * Dada una fecha de referencia (`$hoy`, formato `Y-m-d`) y el día del mes configurado (`1..31`), devuelve la próxima
     * fecha (`Y-m-d`) en que ocurre ese día (si hoy es ese día, devuelve hoy) y cuántos días faltan (`0..31`).
     *
     * @return array{fecha: string, dias: int}|null
     */
    public static function proximaFecha(?int $diaMes, string $hoy): ?array
    {
        if ($diaMes === null || $diaMes < 1 || $diaMes > 31) {
            return null;
        }

        $dtHoy = DateTimeImmutable::createFromFormat('!Y-m-d', $hoy);
        if ($dtHoy === false) {
            return null;
        }

        $anio = (int) $dtHoy->format('Y');
        $mes = (int) $dtHoy->format('n');

        $candidataStr = PlanCuotas::fechaEnMes($anio, $mes, $diaMes);
        if ($candidataStr < $hoy) {
            $mes++;
            if ($mes > 12) {
                $mes = 1;
                $anio++;
            }
            $candidataStr = PlanCuotas::fechaEnMes($anio, $mes, $diaMes);
        }

        $dtCandidata = DateTimeImmutable::createFromFormat('!Y-m-d', $candidataStr);
        $dias = $dtCandidata !== false ? (int) $dtHoy->diff($dtCandidata)->days : 0;

        return ['fecha' => $candidataStr, 'dias' => $dias];
    }

    /**
     * Ventana `[desde, hasta]` (ambos inclusive, `Y-m-d`) del ciclo de facturación actual que cierra en el próximo corte.
     * Si no hay `diaCorte`, devuelve el primer y último día del mes de `$hoy`.
     *
     * @return array{0: string, 1: string}
     */
    public static function ventanaCicloActual(?int $diaCorte, string $hoy): array
    {
        $dtHoy = DateTimeImmutable::createFromFormat('!Y-m-d', $hoy) ?: new DateTimeImmutable('today');
        $proximoCorte = self::proximaFecha($diaCorte, $hoy);

        if ($proximoCorte === null || $diaCorte === null) {
            $y = (int) $dtHoy->format('Y');
            $m = (int) $dtHoy->format('n');
            return [
                sprintf('%04d-%02d-01', $y, $m),
                PlanCuotas::fechaEnMes($y, $m, 31),
            ];
        }

        $hasta = $proximoCorte['fecha'];
        $dtHasta = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta) ?: $dtHoy;
        $anioHasta = (int) $dtHasta->format('Y');
        $mesPrevio = (int) $dtHasta->format('n') - 1;
        $anioPrevio = $anioHasta;
        if ($mesPrevio < 1) {
            $mesPrevio = 12;
            $anioPrevio--;
        }

        $cortePrevio = PlanCuotas::fechaEnMes($anioPrevio, $mesPrevio, $diaCorte);
        $dtCortePrevio = DateTimeImmutable::createFromFormat('!Y-m-d', $cortePrevio);
        $desde = $dtCortePrevio !== false ? $dtCortePrevio->modify('+1 day')->format('Y-m-d') : $hasta;

        return [$desde, $hasta];
    }
}
