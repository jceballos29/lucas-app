<?php

declare(strict_types=1);

namespace App\Deudas;

use App\Domain\Deuda;
use App\Support\Dinero;

/**
 * Reglas puras para registrar un pago o abono a una deuda (Fase 11).
 *
 * Calcula también el desglose exacto entre `pago_interes` y `abono_capital` en centavos enteros:
 *  - Modo `cuota` (pago normal de cuota): cubre primero los intereses mensuales generados sobre el saldo actual de la deuda
 *    y destina el resto a amortizar el capital.
 *  - Modo `capital` (abono extraordinario a capital): el 100 % del pago va a reducir el saldo de capital.
 */
final class ReglasPagoDeuda
{
    public const OK = 'ok';
    public const CUENTA_AJENA = 'cuenta_ajena';
    public const DEUDA_AJENA = 'deuda_ajena';
    public const DEUDA_PAGADA = 'deuda_pagada';
    public const CUENTA_INACTIVA = 'cuenta_inactiva';
    public const SALDO_INSUFICIENTE = 'saldo_insuficiente';
    public const SUPERA_SALDO_DEUDA = 'supera_saldo_deuda';

    /**
     * @param array{activa: bool, tipo?: string}|null $cuenta
     * @param array{estado: string, saldo_capital: string, tasa_mensual_bps: int}|null $deuda
     * @return array{codigo: string, abono_capital: string, pago_interes: string, nuevo_saldo_capital: string}
     */
    public static function evaluar(
        ?array $cuenta,
        ?array $deuda,
        string $saldoCuenta,
        string $montoTotal,
        string $modoPago = 'cuota',
    ): array {
        $vacio = ['abono_capital' => '0.00', 'pago_interes' => '0.00', 'nuevo_saldo_capital' => '0.00'];

        if ($cuenta === null) {
            return ['codigo' => self::CUENTA_AJENA] + $vacio;
        }

        if ($deuda === null) {
            return ['codigo' => self::DEUDA_AJENA] + $vacio;
        }

        $saldoCapitalCentavos = Dinero::aCentavos($deuda['saldo_capital']);
        if ($deuda['estado'] !== 'activa' || $saldoCapitalCentavos <= 0) {
            return ['codigo' => self::DEUDA_PAGADA] + $vacio;
        }

        if (!$cuenta['activa']) {
            return ['codigo' => self::CUENTA_INACTIVA] + $vacio;
        }

        $esTarjeta = ($cuenta['tipo'] ?? '') === 'tarjeta_credito';
        $montoCentavos = Dinero::aCentavos($montoTotal);
        if (!$esTarjeta && $montoCentavos > Dinero::aCentavos($saldoCuenta)) {
            return ['codigo' => self::SALDO_INSUFICIENTE] + $vacio;
        }

        $interesMesCentavos = $modoPago === 'capital'
            ? 0
            : Dinero::aCentavos(Deuda::calcularInteres($deuda['saldo_capital'], $deuda['tasa_mensual_bps']));

        $pagoInteresCentavos = min($montoCentavos, $interesMesCentavos);
        $abonoCapitalCentavos = $montoCentavos - $pagoInteresCentavos;

        if ($abonoCapitalCentavos > $saldoCapitalCentavos) {
            return ['codigo' => self::SUPERA_SALDO_DEUDA] + $vacio;
        }

        return [
            'codigo' => self::OK,
            'abono_capital' => Dinero::deCentavos($abonoCapitalCentavos),
            'pago_interes' => Dinero::deCentavos($pagoInteresCentavos),
            'nuevo_saldo_capital' => Dinero::deCentavos($saldoCapitalCentavos - $abonoCapitalCentavos),
        ];
    }
}
