<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\ConversorMoneda;
use App\Support\Dinero;

/**
 * Reglas del formulario de transferencia entre cuentas. Puro: que las cuentas existan, pertenezcan al usuario, estén
 * activas y tengan saldo suficiente lo decide {@see \App\Transferencias\ReglasTransferencia} dentro de la transacción.
 */
final class TransferenciaValidator
{
    public const DESCRIPCION_MAX = 150;

    /**
     * @param array<string, mixed> $input Normalmente $_POST (id_cuenta_origen, id_cuenta_destino, monto, monto_destino, tasa_cambio, fecha, descripcion).
     * @return array{data: array{id_cuenta_origen: int, id_cuenta_destino: int, monto: string, monto_destino: string|null, tasa_micros: int|null, fecha: string, descripcion: string|null}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $montoTexto = trim(self::text($input['monto'] ?? ''));
        $montoDestinoTexto = trim(self::text($input['monto_destino'] ?? ''));
        $tasaTexto = trim(self::text($input['tasa_cambio'] ?? ''));
        $fecha = trim(self::text($input['fecha'] ?? ''));
        $descripcion = trim(preg_replace('/\s+/u', ' ', self::text($input['descripcion'] ?? '')) ?? '');
        $errors = [];

        $idOrigen = self::id($input['id_cuenta_origen'] ?? '');
        if ($idOrigen === 0) {
            $errors['id_cuenta_origen'] = 'Elige la cuenta de origen (de donde sale el dinero).';
        }

        $idDestino = self::id($input['id_cuenta_destino'] ?? '');
        if ($idDestino === 0) {
            $errors['id_cuenta_destino'] = 'Elige la cuenta de destino (a donde llega el dinero).';
        } elseif ($idOrigen > 0 && $idOrigen === $idDestino) {
            $errors['id_cuenta_destino'] = 'La cuenta de destino debe ser distinta de la cuenta de origen.';
        }

        $monto = Dinero::parse($montoTexto);
        if ($monto === null) {
            $errors['monto'] = 'Escribe un monto válido, por ejemplo 100.000 o 1.250.000,50.';
        } elseif ($monto === '0.00' || str_starts_with($monto, '-')) {
            $errors['monto'] = 'El monto a transferir debe ser mayor que cero.';
        }

        $montoDestino = null;
        if ($montoDestinoTexto !== '') {
            $parsedDest = Dinero::parse($montoDestinoTexto);
            if ($parsedDest === null || $parsedDest === '0.00' || str_starts_with($parsedDest, '-')) {
                $errors['monto_destino'] = 'Escribe un monto destino válido mayor que cero, o déjalo vacío para usar la tasa.';
            } else {
                $montoDestino = $parsedDest;
            }
        }

        $tasaMicros = null;
        if ($tasaTexto !== '') {
            $parsedTasa = ConversorMoneda::parseTasaMicros($tasaTexto);
            if ($parsedTasa === null) {
                $errors['tasa_cambio'] = 'Escribe una tasa de cambio válida mayor que cero, por ejemplo 4.200 o 0,925.';
            } else {
                $tasaMicros = $parsedTasa;
            }
        }

        if (!MovimientoValidator::validDate($fecha)) {
            $errors['fecha'] = 'Elige una fecha válida.';
        }

        if (mb_strlen($descripcion) > self::DESCRIPCION_MAX) {
            $errors['descripcion'] = 'La descripción admite hasta ' . self::DESCRIPCION_MAX . ' caracteres.';
        }

        return [
            'data' => [
                'id_cuenta_origen' => $idOrigen,
                'id_cuenta_destino' => $idDestino,
                'monto' => $monto ?? '0.00',
                'monto_destino' => $montoDestino,
                'tasa_micros' => $tasaMicros,
                'fecha' => $fecha,
                'descripcion' => $descripcion === '' ? null : $descripcion,
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
