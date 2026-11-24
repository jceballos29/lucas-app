<?php

declare(strict_types=1);

namespace App\Validation;

use App\Domain\Categoria;

/** Reglas del formulario de categoría (RF-09, RF-34). Puro: la unicidad del nombre la comprueba el controlador. */
final class CategoriaValidator
{
    public const NOMBRE_MIN = 2;
    public const NOMBRE_MAX = 50;
    public const COLOR_POR_DEFECTO = '#8DA0AB';

    /**
     * @param array<string, mixed> $input Normalmente $_POST (nombre, tipo, color).
     * @param bool $conTipo Al CREAR se valida el tipo; al editar no (el tipo no se cambia).
     * @return array{data: array{nombre: string, tipo: string, color: string}, errors: array<string, string>}
     */
    public function validate(array $input, bool $conTipo): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', self::text($input['nombre'] ?? '')) ?? '');
        $tipo = self::text($input['tipo'] ?? '');
        $color = strtoupper(trim(self::text($input['color'] ?? '')));
        $errors = [];

        $largo = mb_strlen($nombre);
        if ($largo < self::NOMBRE_MIN || $largo > self::NOMBRE_MAX) {
            $errors['nombre'] = 'Escribe un nombre (entre ' . self::NOMBRE_MIN . ' y ' . self::NOMBRE_MAX . ' caracteres).';
        }

        if ($conTipo && !array_key_exists($tipo, Categoria::TIPOS)) {
            $errors['tipo'] = 'Elige si es de gastos o de ingresos.';
        }

        if ($color === '') {
            $color = self::COLOR_POR_DEFECTO;
        } elseif (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
            $errors['color'] = 'El color debe tener el formato #RRGGBB.';
        }

        return ['data' => ['nombre' => $nombre, 'tipo' => $tipo, 'color' => $color], 'errors' => $errors];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
