<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Domain\Categoria;

/**
 * Reglas de D8 para guardar un movimiento, puras (sin BD): la cuenta y la categoría deben ser del usuario (o la
 * categoría, predeterminada), el tipo del movimiento debe coincidir con el de la categoría y la cuenta debe estar
 * activa. Quien las aplica (GestionMovimientos) le pasa los datos ya leídos dentro de la transacción.
 */
final class ReglasMovimiento
{
    public const OK = 'ok';
    public const CUENTA_AJENA = 'cuenta_ajena';
    public const CATEGORIA_AJENA = 'categoria_ajena';
    public const TIPO_DISCORDANTE = 'tipo_discordante';
    public const CUENTA_INACTIVA = 'cuenta_inactiva';

    /**
     * @param array{activa: bool}|null $cuenta La cuenta del usuario (null si no existe o es de otra persona).
     * @param Categoria|null $categoria La categoría pedida (null si no existe).
     */
    public static function evaluar(?array $cuenta, ?Categoria $categoria, int $userId, string $tipo): string
    {
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

        return $cuenta['activa'] ? self::OK : self::CUENTA_INACTIVA;
    }
}
