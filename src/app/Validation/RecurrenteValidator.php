<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\Dinero;
use App\Support\Frecuencia;

/**
 * Reglas del formulario de movimiento recurrente / gasto fijo (Fase 9). Puro.
 */
final class RecurrenteValidator
{
    public const DESCRIPCION_MAX = 150;

    /**
     * @param array<string, mixed> $input Normalmente $_POST (tipo, monto, frecuencia, proxima_fecha, id_cuenta, id_categoria, descripcion, auto_aplicar).
     * @return array{data: array{tipo: string, monto: string, frecuencia: string, proxima_fecha: string, id_cuenta: int, id_categoria: int, descripcion: string|null, auto_aplicar: bool}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $tipo = self::text($input['tipo'] ?? '');
        $montoTexto = trim(self::text($input['monto'] ?? ''));
        $frecuencia = self::text($input['frecuencia'] ?? '');
        $fecha = trim(self::text($input['proxima_fecha'] ?? ''));
        $descripcion = trim(preg_replace('/\s+/u', ' ', self::text($input['descripcion'] ?? '')) ?? '');
        $autoAplicar = self::text($input['auto_aplicar'] ?? '1') !== '0';
        $errors = [];

        if (!in_array($tipo, ['ingreso', 'gasto'], true)) {
            $errors['tipo'] = 'Elige si es un gasto fijo o un ingreso fijo.';
        }

        $monto = Dinero::parse($montoTexto);
        if ($monto === null) {
            $errors['monto'] = 'Escribe un monto válido, por ejemplo 80.000 o 1.250.000,50.';
        } elseif ($monto === '0.00' || str_starts_with($monto, '-')) {
            $errors['monto'] = 'El monto debe ser mayor que cero.';
        }

        if (!array_key_exists($frecuencia, Frecuencia::TIPOS)) {
            $errors['frecuencia'] = 'Elige la frecuencia de repetición.';
        }

        if (!MovimientoValidator::validDate($fecha)) {
            $errors['proxima_fecha'] = 'Elige una próxima fecha válida.';
        }

        $idCuenta = self::id($input['id_cuenta'] ?? '');
        if ($idCuenta === 0) {
            $errors['id_cuenta'] = 'Elige la cuenta.';
        }

        $idCategoria = self::id($input['id_categoria'] ?? '');
        if ($idCategoria === 0) {
            $errors['id_categoria'] = 'Elige la categoría.';
        }

        if (mb_strlen($descripcion) > self::DESCRIPCION_MAX) {
            $errors['descripcion'] = 'La descripción admite hasta ' . self::DESCRIPCION_MAX . ' caracteres.';
        }

        return [
            'data' => [
                'tipo' => $tipo,
                'monto' => $monto ?? '0.00',
                'frecuencia' => $frecuencia,
                'proxima_fecha' => $fecha,
                'id_cuenta' => $idCuenta,
                'id_categoria' => $idCategoria,
                'descripcion' => $descripcion === '' ? null : $descripcion,
                'auto_aplicar' => $autoAplicar,
            ],
            'errors' => $errors,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function id(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) && strlen($value) < 10 && (int) $value > 0 ? (int) $value : 0;
    }
}
