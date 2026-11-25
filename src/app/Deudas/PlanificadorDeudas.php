<?php

declare(strict_types=1);

namespace App\Deudas;

use App\Domain\Deuda;
use App\Support\Dinero;

/**
 * Simulador y planificador de desendeudamiento (Fase 11). Puro (sin BD) y 100 % en centavos enteros.
 *
 * Compara tres estrategias sobre las deudas activas:
 *  1. Solo cuotas mínimas (sin reinvertir las cuotas liberadas ni aportar extra).
 *  2. Método Bola de Nieve (Snowball): ordena las deudas de MENOR a MAYOR saldo de capital. Paga las cuotas mínimas de todas
 *     y concentra todo el excedente (y las cuotas liberadas de las deudas ya pagadas) en la más pequeña primero.
 *  3. Método Avalancha (Avalanche): ordena las deudas de MAYOR a MENOR tasa de interés (`tasa_mensual_bps`). Concentra todo
 *     el excedente en la deuda más cara primero, minimizando el total de intereses pagados.
 */
final class PlanificadorDeudas
{
    public const MAX_MESES = 360; // 30 años como tope de seguridad

    /**
     * @param list<Deuda> $deudas
     * @param string $aporteExtraMensual Monto extra por encima de la suma de cuotas mínimas («100000.00»).
     * @return array{
     *   deudaTotal: string,
     *   cuotasMinimasTotal: string,
     *   interesMensualTotal: string,
     *   minimos: array{meses: int, intereses: string, totalPagado: string, orden: list<string>},
     *   bolaNieve: array{meses: int, intereses: string, totalPagado: string, ahorroIntereses: string, mesesAhorrados: int, orden: list<string>, primeraDeuda: string|null},
     *   avalancha: array{meses: int, intereses: string, totalPagado: string, ahorroIntereses: string, mesesAhorrados: int, orden: list<string>, primeraDeuda: string|null}
     * }
     */
    public static function comparar(array $deudas, string $aporteExtraMensual = '0.00'): array
    {
        $activas = array_values(array_filter(
            $deudas,
            static fn (Deuda $d): bool => $d->activa() && Dinero::aCentavos($d->saldoCapital()) > 0
        ));

        $deudaTotal = Dinero::sumar(array_map(static fn (Deuda $d): string => $d->saldoCapital(), $activas));
        $cuotasMinimas = Dinero::sumar(array_map(static fn (Deuda $d): string => $d->cuotaMinima, $activas));
        $interesMensual = Dinero::sumar(array_map(static fn (Deuda $d): string => $d->interesMensual(), $activas));

        if ($activas === []) {
            $vacio = ['meses' => 0, 'intereses' => '0.00', 'totalPagado' => '0.00', 'orden' => []];
            $vacioPlan = $vacio + ['ahorroIntereses' => '0.00', 'mesesAhorrados' => 0, 'primeraDeuda' => null];
            return [
                'deudaTotal' => '0.00',
                'cuotasMinimasTotal' => '0.00',
                'interesMensualTotal' => '0.00',
                'minimos' => $vacio,
                'bolaNieve' => $vacioPlan,
                'avalancha' => $vacioPlan,
            ];
        }

        // 1. Orden Bola de Nieve: menor saldo primero; a igual saldo, mayor tasa primero.
        $nieveList = $activas;
        usort($nieveList, static function (Deuda $a, Deuda $b): int {
            $cmp = Dinero::aCentavos($a->saldoCapital()) <=> Dinero::aCentavos($b->saldoCapital());
            return $cmp !== 0 ? $cmp : ($b->tasaMensualBps <=> $a->tasaMensualBps);
        });

        // 2. Orden Avalancha: mayor tasa primero; a igual tasa, menor saldo primero.
        $avalanchaList = $activas;
        usort($avalanchaList, static function (Deuda $a, Deuda $b): int {
            $cmp = $b->tasaMensualBps <=> $a->tasaMensualBps;
            return $cmp !== 0 ? $cmp : (Dinero::aCentavos($a->saldoCapital()) <=> Dinero::aCentavos($b->saldoCapital()));
        });

        $extraCentavos = max(0, Dinero::aCentavos($aporteExtraMensual));
        $minimos = self::simular($activas, 0, false);
        $nieve = self::simular($nieveList, $extraCentavos, true);
        $avalancha = self::simular($avalanchaList, $extraCentavos, true);

        $ahorroNieve = max(0, Dinero::aCentavos($minimos['intereses']) - Dinero::aCentavos($nieve['intereses']));
        $ahorroAvalancha = max(0, Dinero::aCentavos($minimos['intereses']) - Dinero::aCentavos($avalancha['intereses']));

        return [
            'deudaTotal' => $deudaTotal,
            'cuotasMinimasTotal' => $cuotasMinimas,
            'interesMensualTotal' => $interesMensual,
            'minimos' => $minimos,
            'bolaNieve' => $nieve + [
                'ahorroIntereses' => Dinero::deCentavos($ahorroNieve),
                'mesesAhorrados' => max(0, $minimos['meses'] - $nieve['meses']),
                'primeraDeuda' => $nieveList[0]->nombre ?? null,
            ],
            'avalancha' => $avalancha + [
                'ahorroIntereses' => Dinero::deCentavos($ahorroAvalancha),
                'mesesAhorrados' => max(0, $minimos['meses'] - $avalancha['meses']),
                'primeraDeuda' => $avalanchaList[0]->nombre ?? null,
            ],
        ];
    }

    /**
     * @param list<Deuda> $ordenadas
     * @return array{meses: int, intereses: string, totalPagado: string, orden: list<string>}
     */
    private static function simular(array $ordenadas, int $extraCentavos, bool $reinvertirCuotasLiberadas): array
    {
        $items = [];
        $presupuestoBase = $extraCentavos;

        foreach ($ordenadas as $d) {
            $cuota = max(1, Dinero::aCentavos($d->cuotaMinima));
            $items[] = [
                'nombre' => $d->nombre,
                'saldo' => max(0, Dinero::aCentavos($d->saldoCapital())),
                'tasa' => max(0, $d->tasaMensualBps),
                'cuota' => $cuota,
            ];
            $presupuestoBase += $cuota;
        }

        $meses = 0;
        $totalIntereses = 0;
        $totalCapital = array_sum(array_column($items, 'saldo'));

        while ($meses < self::MAX_MESES) {
            $pendiente = false;
            foreach ($items as $it) {
                if ($it['saldo'] > 0) {
                    $pendiente = true;
                    break;
                }
            }
            if (!$pendiente) {
                break;
            }

            $meses++;
            $disponibleExtra = $extraCentavos;

            // Paso 1: generar intereses del mes y pagar la cuota mínima de cada deuda activa.
            foreach ($items as &$it) {
                if ($it['saldo'] <= 0) {
                    if ($reinvertirCuotasLiberadas) {
                        $disponibleExtra += $it['cuota'];
                    }
                    continue;
                }

                $interes = $it['tasa'] > 0 ? intdiv($it['saldo'] * $it['tasa'] + 5_000, 10_000) : 0;
                $totalIntereses += $interes;

                // Si la cuota mínima configurada fuera menor que el interés del mes, en la simulación cubre al menos el interés + 1 centavo para no ciclar.
                $cuotaEfectiva = max($it['cuota'], $interes + 1);
                $exigible = $it['saldo'] + $interes;

                if ($cuotaEfectiva >= $exigible) {
                    $sobrante = $it['cuota'] > $exigible ? ($it['cuota'] - $exigible) : 0;
                    $it['saldo'] = 0;
                    if ($reinvertirCuotasLiberadas) {
                        $disponibleExtra += $sobrante;
                    }
                } else {
                    $abonoCapital = $cuotaEfectiva - $interes;
                    $it['saldo'] -= $abonoCapital;
                }
            }
            unset($it);

            // Paso 2: en Bola de Nieve y Avalancha, volcar todo el excedente sobre la primera deuda en orden de ataque.
            if ($reinvertirCuotasLiberadas && $disponibleExtra > 0) {
                foreach ($items as &$it) {
                    if ($it['saldo'] <= 0) {
                        continue;
                    }
                    $abono = min($it['saldo'], $disponibleExtra);
                    $it['saldo'] -= $abono;
                    $disponibleExtra -= $abono;
                    if ($disponibleExtra <= 0) {
                        break;
                    }
                }
                unset($it);
            }
        }

        return [
            'meses' => $meses,
            'intereses' => Dinero::deCentavos($totalIntereses),
            'totalPagado' => Dinero::deCentavos($totalCapital + $totalIntereses),
            'orden' => array_map(static fn (Deuda $d): string => $d->nombre, $ordenadas),
        ];
    }
}
