<?php

declare(strict_types=1);

namespace App\Transferencias;

use App\Support\Dinero;

/**
 * Reglas puras para una transferencia entre cuentas:
 *  - ambas cuentas deben existir y pertenecer al usuario;
 *  - deben ser dos cuentas distintas y ambas estar activas;
 *  - si la cuenta de origen no es una tarjeta de crédito, su saldo disponible debe alcanzar para el monto;
 *  - si la cuenta de destino no es una tarjeta de crédito, su saldo final tampoco puede quedar en negativo.
 */
final class ReglasTransferencia
{
    public const OK = 'ok';
    public const CUENTA_AJENA = 'cuenta_ajena';
    public const MISMA_CUENTA = 'misma_cuenta';
    public const ORIGEN_INACTIVA = 'origen_inactiva';
    public const DESTINO_INACTIVA = 'destino_inactiva';
    public const SALDO_INSUFICIENTE = 'saldo_insuficiente';

    /**
     * @param array{nombre?: string, tipo?: string, activa: bool}|null $origen
     * @param array{nombre?: string, tipo?: string, activa: bool}|null $destino
     */
    public static function evaluar(
        ?array $origen,
        ?array $destino,
        int $origenId,
        int $destinoId,
        string $saldoDisponibleOrigen,
        string $monto,
    ): string {
        if ($origen === null || $destino === null) {
            return self::CUENTA_AJENA;
        }

        if ($origenId === $destinoId) {
            return self::MISMA_CUENTA;
        }

        if (!$origen['activa']) {
            return self::ORIGEN_INACTIVA;
        }

        if (!$destino['activa']) {
            return self::DESTINO_INACTIVA;
        }

        $esTarjetaOrigen = ($origen['tipo'] ?? '') === 'tarjeta_credito';
        if (!$esTarjetaOrigen && Dinero::aCentavos($monto) > Dinero::aCentavos($saldoDisponibleOrigen)) {
            return self::SALDO_INSUFICIENTE;
        }

        return self::OK;
    }
}
