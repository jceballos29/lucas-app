<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\Dinero;

/**
 * Validación pura de los formularios de Deuda y Pago de deuda (Fase 11).
 */
final class DeudaValidator
{
    public const NOMBRE_MIN = 2;
    public const NOMBRE_MAX = 80;
    public const TASA_MAX_BPS = 2000; // 20,00 % M.V.
    public const DESCRIPCION_MAX = 150;

    /**
     * @param array<string, mixed> $input
     * @return array{data: array{nombre: string, acreedor: string|null, monto_inicial: string, tasa_mensual_bps: int, cuota_minima: string, dia_pago: int|null}, errors: array<string, string>}
     */
    public function validateDeuda(array $input): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', self::text($input['nombre'] ?? '')) ?? '');
        $acreedor = trim(preg_replace('/\s+/u', ' ', self::text($input['acreedor'] ?? '')) ?? '');
        $montoTexto = trim(self::text($input['monto_inicial'] ?? ''));
        $tasaTexto = trim(self::text($input['tasa_mensual'] ?? '0'));
        $cuotaTexto = trim(self::text($input['cuota_minima'] ?? ''));
        $diaTexto = trim(self::text($input['dia_pago'] ?? ''));
        $errors = [];

        $len = mb_strlen($nombre);
        if ($len < self::NOMBRE_MIN) {
            $errors['nombre'] = 'Escribe un nombre de al menos ' . self::NOMBRE_MIN . ' caracteres (ej.: Crédito Vehículo).';
        } elseif ($len > self::NOMBRE_MAX) {
            $errors['nombre'] = 'El nombre admite hasta ' . self::NOMBRE_MAX . ' caracteres.';
        }

        if (mb_strlen($acreedor) > self::NOMBRE_MAX) {
            $errors['acreedor'] = 'El acreedor o entidad admite hasta ' . self::NOMBRE_MAX . ' caracteres.';
        }

        $monto = Dinero::parse($montoTexto);
        if ($monto === null) {
            $errors['monto_inicial'] = 'Escribe un monto válido, por ejemplo 5.000.000.' . Dinero::pistaDeFormato($montoTexto);
        } elseif ($monto === '0.00' || str_starts_with($monto, '-')) {
            $errors['monto_inicial'] = 'El monto inicial de la deuda debe ser mayor que cero.';
        }

        $tasaBps = self::parseTasaBps($tasaTexto);
        if ($tasaBps === null || $tasaBps < 0 || $tasaBps > self::TASA_MAX_BPS) {
            $errors['tasa_mensual'] = 'Escribe una tasa mensual entre 0 y 20 % (por ejemplo: 1,85 o 0).';
        }

        $cuota = Dinero::parse($cuotaTexto);
        if ($cuota === null) {
            $errors['cuota_minima'] = 'Escribe una cuota mínima válida, por ejemplo 250.000.';
        } elseif ($cuota === '0.00' || str_starts_with($cuota, '-')) {
            $errors['cuota_minima'] = 'La cuota mínima mensual debe ser mayor que cero.';
        }

        $diaPago = null;
        if ($diaTexto !== '') {
            if (!ctype_digit($diaTexto) || (int) $diaTexto < 1 || (int) $diaTexto > 31) {
                $errors['dia_pago'] = 'El día de pago debe estar entre 1 y 31.';
            } else {
                $diaPago = (int) $diaTexto;
            }
        }

        return [
            'data' => [
                'nombre' => $nombre,
                'acreedor' => $acreedor === '' ? null : $acreedor,
                'monto_inicial' => $monto ?? '0.00',
                'tasa_mensual_bps' => $tasaBps ?? 0,
                'cuota_minima' => $cuota ?? '0.00',
                'dia_pago' => $diaPago,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array{id_cuenta: int, monto_total: string, modo_pago: string, fecha: string, descripcion: string|null}, errors: array<string, string>}
     */
    public function validatePago(array $input): array
    {
        $idCuenta = self::id($input['id_cuenta'] ?? '');
        $montoTexto = trim(self::text($input['monto_total'] ?? ''));
        $modoPago = self::text($input['modo_pago'] ?? 'cuota');
        $fecha = trim(self::text($input['fecha'] ?? ''));
        $descripcion = trim(preg_replace('/\s+/u', ' ', self::text($input['descripcion'] ?? '')) ?? '');
        $errors = [];

        if ($idCuenta === 0) {
            $errors['id_cuenta'] = 'Elige la cuenta desde la que realizas el pago.';
        }

        $monto = Dinero::parse($montoTexto);
        if ($monto === null) {
            $errors['monto_total'] = 'Escribe un monto válido.';
        } elseif ($monto === '0.00' || str_starts_with($monto, '-')) {
            $errors['monto_total'] = 'El monto del pago debe ser mayor que cero.';
        }

        if (!in_array($modoPago, ['cuota', 'capital'], true)) {
            $errors['modo_pago'] = 'Elige el tipo de abono.';
        }

        if (!MovimientoValidator::validDate($fecha)) {
            $errors['fecha'] = 'Elige una fecha válida.';
        }

        if (mb_strlen($descripcion) > self::DESCRIPCION_MAX) {
            $errors['descripcion'] = 'La descripción admite hasta ' . self::DESCRIPCION_MAX . ' caracteres.';
        }

        return [
            'data' => [
                'id_cuenta' => $idCuenta,
                'monto_total' => $monto ?? '0.00',
                'modo_pago' => $modoPago,
                'fecha' => $fecha,
                'descripcion' => $descripcion === '' ? null : $descripcion,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Convierte una tasa escrita como porcentaje mensual («1,85», «1.85», «0», «2») a puntos básicos enteros (185, 0, 200).
     */
    public static function parseTasaBps(string $texto): ?int
    {
        $limpio = trim(str_replace(['%', ' '], '', $texto));
        if ($limpio === '') {
            return 0;
        }

        if (preg_match('/^(\d{1,2})(?:[.,](\d{1,2}))?$/', $limpio, $m) !== 1) {
            return null;
        }

        $entero = (int) $m[1];
        $dec = isset($m[2]) ? (int) str_pad($m[2], 2, '0', STR_PAD_RIGHT) : 0;

        return $entero * 100 + $dec;
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
