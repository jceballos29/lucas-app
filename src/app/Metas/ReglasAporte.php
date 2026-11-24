<?php

declare(strict_types=1);

namespace App\Metas;

use App\Support\Dinero;

/**
 * Reglas de un aporte a una meta (RF-19, D8), puras: la cuenta y la meta deben ser del usuario, la meta seguir en curso,
 * la cuenta estar activa y su saldo alcanzar para el aporte. Quien las aplica (GestionMetas) le pasa lo leído dentro de la
 * transacción, con la cuenta y la meta bloqueadas.
 */
final class ReglasAporte
{
    public const OK = 'ok';
    public const CUENTA_AJENA = 'cuenta_ajena';
    public const META_AJENA = 'meta_ajena';
    public const META_CERRADA = 'meta_cerrada';
    public const CUENTA_INACTIVA = 'cuenta_inactiva';
    public const SALDO_INSUFICIENTE = 'saldo_insuficiente';

    /**
     * @param array{activa: bool}|null $cuenta null si no existe o no es del usuario
     * @param array{estado: string}|null $meta null si no existe o no es del usuario
     * @param string $saldo Saldo actual de la cuenta («1500000.00»).
     * @param string $monto Monto del aporte, ya normalizado y positivo.
     */
    public static function evaluar(?array $cuenta, ?array $meta, string $saldo, string $monto): string
    {
        if ($cuenta === null) {
            return self::CUENTA_AJENA;
        }

        if ($meta === null) {
            return self::META_AJENA;
        }

        if ($meta['estado'] !== 'en_curso') {
            return self::META_CERRADA;
        }

        if (!$cuenta['activa']) {
            return self::CUENTA_INACTIVA;
        }

        return Dinero::aCentavos($monto) > Dinero::aCentavos($saldo) ? self::SALDO_INSUFICIENTE : self::OK;
    }
}
