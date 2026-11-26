<?php

declare(strict_types=1);

namespace App\Validation;

use App\Domain\Cuenta;
use App\Support\Dinero;

/** Reglas del formulario de cuenta (RF-05, RF-06). Puro: la unicidad del nombre la comprueba el controlador. */
final class CuentaValidator
{
    public const NOMBRE_MIN = 2;
    public const NOMBRE_MAX = 60;

    /**
     * @param array<string, mixed> $input Normalmente $_POST (nombre, tipo, saldo_inicial, dia_corte, dia_pago_limite).
     * @return array{data: array{nombre: string, tipo: string, saldo_inicial: string, dia_corte: int|null, dia_pago_limite: int|null}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', self::text($input['nombre'] ?? '')) ?? '');
        $tipo = self::text($input['tipo'] ?? '');
        $saldoTexto = trim(self::text($input['saldo_inicial'] ?? ''));
        $diaCorteTexto = trim(self::text($input['dia_corte'] ?? ''));
        $diaPagoTexto = trim(self::text($input['dia_pago_limite'] ?? ''));
        $errors = [];

        $largo = mb_strlen($nombre);
        if ($largo < self::NOMBRE_MIN || $largo > self::NOMBRE_MAX) {
            $errors['nombre'] = 'Escribe un nombre para la cuenta (entre ' . self::NOMBRE_MIN . ' y ' . self::NOMBRE_MAX . ' caracteres).';
        }

        if (!array_key_exists($tipo, Cuenta::TIPOS)) {
            $errors['tipo'] = 'Elige el tipo de cuenta.';
        }

        // Vacío = 0. Solo una tarjeta de crédito puede empezar en negativo (lo que se debe).
        $saldo = $saldoTexto === '' ? '0.00' : Dinero::parse($saldoTexto);
        if ($saldo === null) {
            $errors['saldo_inicial'] = 'Escribe un monto válido, por ejemplo 1.250.000 o 1.250.000,50.';
        } elseif (str_starts_with($saldo, '-') && $tipo !== 'tarjeta_credito') {
            $errors['saldo_inicial'] = 'El saldo inicial no puede ser negativo (solo una tarjeta de crédito puede empezar debiendo).';
        }

        $diaCorte = null;
        $diaPagoLimite = null;

        if ($tipo === 'tarjeta_credito') {
            if ($diaCorteTexto !== '') {
                if (!ctype_digit($diaCorteTexto) || (int) $diaCorteTexto < 1 || (int) $diaCorteTexto > 31) {
                    $errors['dia_corte'] = 'El día de corte debe ser un número entre 1 y 31.';
                } else {
                    $diaCorte = (int) $diaCorteTexto;
                }
            }

            if ($diaPagoTexto !== '') {
                if (!ctype_digit($diaPagoTexto) || (int) $diaPagoTexto < 1 || (int) $diaPagoTexto > 31) {
                    $errors['dia_pago_limite'] = 'El día límite de pago debe ser un número entre 1 y 31.';
                } else {
                    $diaPagoLimite = (int) $diaPagoTexto;
                }
            }
        }

        return [
            'data' => [
                'nombre' => $nombre,
                'tipo' => $tipo,
                'saldo_inicial' => $saldo ?? '0.00',
                'dia_corte' => $diaCorte,
                'dia_pago_limite' => $diaPagoLimite,
            ],
            'errors' => $errors,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
