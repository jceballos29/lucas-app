<?php

declare(strict_types=1);

namespace App\Presupuestos;

use App\Domain\Categoria;

/** Reglas para crear un presupuesto (D8), puras: la categoría debe ser visible para el usuario y de GASTOS. */
final class ReglasPresupuesto
{
    public const OK = 'ok';
    public const CATEGORIA_AJENA = 'categoria_ajena';
    public const NO_ES_GASTO = 'no_es_gasto';

    public static function evaluar(?Categoria $categoria, int $userId): string
    {
        // Una categoría ajena se trata como inexistente: no se revela que existe (RNF-04).
        if ($categoria === null || (!$categoria->esPredeterminada() && $categoria->idUsuario !== $userId)) {
            return self::CATEGORIA_AJENA;
        }

        return $categoria->tipo === 'gasto' ? self::OK : self::NO_ES_GASTO;
    }
}
