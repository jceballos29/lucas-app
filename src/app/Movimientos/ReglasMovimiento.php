<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Domain\Categoria;
use App\Support\Dinero;

/**
 * Reglas de D8 para guardar un movimiento, puras (sin BD): la cuenta y la categoría deben ser del usuario (o la
 * categoría, predeterminada), el tipo del movimiento debe coincidir con el de la categoría, la cuenta debe estar
 * activa y una cuenta que no sea tarjeta de crédito no puede quedar con saldo negativo. Quien las aplica
 * (GestionMovimientos) le pasa los datos ya leídos dentro de la transacción.
 */
final class ReglasMovimiento
{
    public const OK = 'ok';
    public const CUENTA_AJENA = 'cuenta_ajena';
    public const CATEGORIA_AJENA = 'categoria_ajena';
    public const TIPO_DISCORDANTE = 'tipo_discordante';
    public const CUENTA_INACTIVA = 'cuenta_inactiva';
    public const SALDO_INSUFICIENTE = 'saldo_insuficiente';

    /**
     * @param array{activa: bool, tipo?: string}|null $cuenta La cuenta del usuario (null si no existe o es de otra persona).
     * @param Categoria|null $categoria La categoría pedida (null si no existe).
     * @param string|null $saldoDisponible Saldo disponible de la cuenta antes de aplicar el nuevo movimiento (para un gasto).
     * @param string $monto Monto del nuevo movimiento («18000.00»).
     */
    public static function evaluar(
        ?array $cuenta,
        ?Categoria $categoria,
        int $userId,
        string $tipo,
        ?string $saldoDisponible = null,
        string $monto = '0.00',
    ): string {
        if ($cuenta === null) {
            return self::CUENTA_AJENA;
        }

        // Una categoría ajena se trata como inexistente: no se revela que existe (RNF-04).
        if ($categoria === null || (!$categoria->esPredeterminada() && $categoria->idUsuario !== $userId)) {
            return self::CATEGORIA_AJENA;
        }

        if ($categoria->tipo !== $tipo) {
            return self::TIPO_DISCORDANTE;
        }

        if (!$cuenta['activa']) {
            return self::CUENTA_INACTIVA;
        }

        $esTarjeta = ($cuenta['tipo'] ?? '') === 'tarjeta_credito';
        if ($tipo === 'gasto' && !$esTarjeta && $saldoDisponible !== null && Dinero::aCentavos($monto) > Dinero::aCentavos($saldoDisponible)) {
            return self::SALDO_INSUFICIENTE;
        }

        return self::OK;
    }
}
