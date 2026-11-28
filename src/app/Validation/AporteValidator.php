<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\Dinero;

/** Reglas del formulario de aporte a una meta (RF-19). Puro: el saldo y los permisos los decide ReglasAporte. */
final class AporteValidator
{
    /**
     * @param array<string, mixed> $input Normalmente $_POST (id_cuenta, monto, fecha).
     * @return array{data: array{id_cuenta: int, monto: string, fecha: string}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $texto = static fn (string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';
        $errors = [];
        $cuenta = $texto('id_cuenta');
        $idCuenta = ctype_digit($cuenta) && strlen($cuenta) < 10 && (int) $cuenta > 0 ? (int) $cuenta : 0;

        if ($idCuenta === 0) {
            $errors['id_cuenta'] = 'Elige la cuenta de donde sale el dinero.';
        }

        $monto = Dinero::parse($texto('monto'));
        if ($monto === null) {
            $errors['monto'] = 'Escribe un monto válido, por ejemplo 100.000.' . Dinero::pistaDeFormato($texto('monto'));
        } elseif ($monto === '0.00' || str_starts_with($monto, '-')) {
            $errors['monto'] = 'El aporte debe ser mayor que cero.';
        }

        $fecha = $texto('fecha');
        if (!MovimientoValidator::validDate($fecha)) {
            $errors['fecha'] = 'Elige una fecha válida.';
        }

        return ['data' => ['id_cuenta' => $idCuenta, 'monto' => $monto ?? '0.00', 'fecha' => $fecha], 'errors' => $errors];
    }
}
