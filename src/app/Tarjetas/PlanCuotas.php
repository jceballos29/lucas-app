<?php

declare(strict_types=1);

namespace App\Tarjetas;

use App\Support\Dinero;
use DateTimeImmutable;

/**
 * Motor puro de compras diferidas a cuotas con tarjeta de crédito (Fase 15).
 *
 * Reparte el monto total en N cuotas (1 a 36) usando exclusivamente centavos enteros:
 *  - Cada cuota recibe floor(centavos / N) y las primeras `centavos % N` cuotas reciben 1 centavo adicional.
 *  - Así la suma de `monto_capital` de todas las cuotas cuadra siempre al centavo con el monto de la compra.
 *  - Calcula la `fecha_cobro` de cada cuota según el `dia_corte` de la tarjeta:
 *      · Si la compra ocurre en o antes del día de corte del mes de compra, la cuota 1 entra en ese mismo corte.
 *      · Si ocurre después del día de corte, la cuota 1 entra en el corte del mes siguiente.
 *      · Si la tarjeta no tiene `dia_corte` configurado, se usa el mismo día de la fecha de compra mes a mes.
 */
final class PlanCuotas
{
    public const MIN_CUOTAS = 1;
    public const MAX_CUOTAS = 36;

    /**
     * @return list<array{numero: int, fecha_cobro: string, monto_capital: string, monto_interes: string, monto_total: string}>
     */
    public static function generar(string $montoTotal, int $numCuotas, string $fechaCompra, ?int $diaCorte = null): array
    {
        $n = max(self::MIN_CUOTAS, min(self::MAX_CUOTAS, $numCuotas));
        $totalCentavos = max(0, Dinero::aCentavos($montoTotal));

        $baseCentavos = intdiv($totalCentavos, $n);
        $residuo = $totalCentavos % $n;

        [$anioBase, $mesBase] = self::primerMesCobro($fechaCompra, $diaCorte);
        $diaObjetivo = $diaCorte !== null && $diaCorte >= 1 && $diaCorte <= 31
            ? $diaCorte
            : (int) substr($fechaCompra, 8, 2);

        $cuotas = [];
        $anio = $anioBase;
        $mes = $mesBase;

        for ($i = 1; $i <= $n; $i++) {
            $capCentavos = $baseCentavos + ($i <= $residuo ? 1 : 0);
            $montoCap = Dinero::deCentavos($capCentavos);

            $cuotas[] = [
                'numero' => $i,
                'fecha_cobro' => self::fechaEnMes($anio, $mes, $diaObjetivo),
                'monto_capital' => $montoCap,
                'monto_interes' => '0.00',
                'monto_total' => $montoCap,
            ];

            $mes++;
            if ($mes > 12) {
                $mes = 1;
                $anio++;
            }
        }

        return $cuotas;
    }

    /**
     * Valor aproximado de la primera cuota para mostrar en resúmenes («6 cuotas de $50.000»).
     */
    public static function primeraCuota(string $montoTotal, int $numCuotas): string
    {
        $n = max(self::MIN_CUOTAS, min(self::MAX_CUOTAS, $numCuotas));
        $totalCentavos = max(0, Dinero::aCentavos($montoTotal));
        $base = intdiv($totalCentavos, $n) + ($totalCentavos % $n > 0 ? 1 : 0);

        return Dinero::deCentavos($base);
    }

    /**
     * @return array{0: int, 1: int} [anio, mes] del primer corte donde entra la compra
     */
    public static function primerMesCobro(string $fechaCompra, ?int $diaCorte): array
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaCompra) ?: new DateTimeImmutable('today');
        $anio = (int) $dt->format('Y');
        $mes = (int) $dt->format('n');
        $diaCompra = (int) $dt->format('j');

        if ($diaCorte === null || $diaCorte < 1 || $diaCorte > 31) {
            return [$anio, $mes];
        }

        $diaCorteRealMes = min($diaCorte, self::diasEnMes($anio, $mes));
        if ($diaCompra > $diaCorteRealMes) {
            $mes++;
            if ($mes > 12) {
                $mes = 1;
                $anio++;
            }
        }

        return [$anio, $mes];
    }

    public static function fechaEnMes(int $anio, int $mes, int $diaObjetivo): string
    {
        $dia = max(1, min($diaObjetivo, self::diasEnMes($anio, $mes)));

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    public static function diasEnMes(int $anio, int $mes): int
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-n-j', "$anio-$mes-1");

        return $dt !== false ? (int) $dt->format('t') : 30;
    }
}
