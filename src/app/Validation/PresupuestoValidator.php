<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\Dinero;
use App\Support\Mes;

/** Reglas del formulario de presupuesto (RF-15). Puro: que la categoría sea de gasto y del usuario lo decide ReglasPresupuesto. */
final class PresupuestoValidator
{
    /**
     * @param array<string, mixed> $input Normalmente $_POST (id_categoria, mes, anio, monto_limite).
     * @return array{data: array{id_categoria: int, mes: int, anio: int, monto_limite: string}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $idCategoria = self::id($input['id_categoria'] ?? '');
        $mes = self::id($input['mes'] ?? '');
        $anio = self::id($input['anio'] ?? '');
        $texto = trim(is_string($input['monto_limite'] ?? null) ? $input['monto_limite'] : '');

        if ($idCategoria === 0) {
            $errors['id_categoria'] = 'Elige la categoría.';
        }

        if (!Mes::valido($mes, $anio)) {
            $errors['mes'] = 'Elige un mes y un año válidos.';
        }

        $limite = Dinero::parse($texto);
        if ($limite === null) {
            $errors['monto_limite'] = 'Escribe un monto válido, por ejemplo 400.000 o 1.250.000,50.' . Dinero::pistaDeFormato($texto);
        } elseif ($limite === '0.00' || str_starts_with($limite, '-')) {
            $errors['monto_limite'] = 'El límite debe ser mayor que cero.';
        }

        return ['data' => ['id_categoria' => $idCategoria, 'mes' => $mes, 'anio' => $anio, 'monto_limite' => $limite ?? '0.00'], 'errors' => $errors];
    }

    private static function id(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) && strlen($value) < 10 && (int) $value > 0 ? (int) $value : 0;
    }
}
