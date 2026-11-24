<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\Dinero;
use DateTimeImmutable;

/**
 * Reglas del formulario de movimiento (RF-10, RNF-16). Puro: que la cuenta y la categoría existan, sean del usuario y
 * encajen con el tipo lo decide {@see \App\Movimientos\ReglasMovimiento} dentro de la transacción (D8).
 */
final class MovimientoValidator
{
    public const DESCRIPCION_MAX = 150;
    public const ANIO_MIN = 2000;
    public const ANIO_MAX = 2100;

    /**
     * @param array<string, mixed> $input Normalmente $_POST (tipo, monto, fecha, id_cuenta, id_categoria, descripcion).
     * @return array{data: array{tipo: string, monto: string, fecha: string, id_cuenta: int, id_categoria: int, descripcion: string|null}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $tipo = self::text($input['tipo'] ?? '');
        $montoTexto = trim(self::text($input['monto'] ?? ''));
        $fecha = trim(self::text($input['fecha'] ?? ''));
        $descripcion = trim(preg_replace('/\s+/u', ' ', self::text($input['descripcion'] ?? '')) ?? '');
        $errors = [];

        if (!in_array($tipo, ['ingreso', 'gasto'], true)) {
            $errors['tipo'] = 'Elige si es un ingreso o un gasto.';
        }

        $monto = Dinero::parse($montoTexto);
        if ($monto === null) {
            $errors['monto'] = 'Escribe un monto válido, por ejemplo 18.000 o 1.250.000,50.';
        } elseif ($monto === '0.00' || str_starts_with($monto, '-')) {
            $errors['monto'] = 'El monto debe ser mayor que cero.';
        }

        if (!self::validDate($fecha)) {
            $errors['fecha'] = 'Elige una fecha válida.';
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
                'fecha' => $fecha,
                'id_cuenta' => $idCuenta,
                'id_categoria' => $idCategoria,
                'descripcion' => $descripcion === '' ? null : $descripcion,
            ],
            'errors' => $errors,
        ];
    }

    /** Una fecha `AAAA-MM-DD` real (no el 31 de febrero) dentro de un rango razonable. */
    public static function validDate(string $fecha): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m) !== 1) {
            return false;
        }

        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return $y >= self::ANIO_MIN && $y <= self::ANIO_MAX && checkdate($mo, $d, $y)
            && DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) !== false;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** Un id positivo; cualquier otra cosa (vacío, texto, arreglo, «0», «-3») es 0. */
    private static function id(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : 0;
    }
}
