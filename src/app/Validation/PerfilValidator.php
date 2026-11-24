<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\Dinero;

/** Reglas del formulario de datos del perfil (RF-03): nombre y moneda. */
final class PerfilValidator
{
    /**
     * @param array<string, mixed> $input Normalmente $_POST (nombre, moneda).
     * @return array{data: array{nombre: string, moneda: string}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', is_string($input['nombre'] ?? null) ? $input['nombre'] : '') ?? '');
        $moneda = is_string($input['moneda'] ?? null) ? $input['moneda'] : '';
        $errors = [];

        $largo = mb_strlen($nombre);
        if ($largo < RegistroValidator::NOMBRE_MIN || $largo > RegistroValidator::NOMBRE_MAX) {
            $errors['nombre'] = 'Escribe tu nombre (entre ' . RegistroValidator::NOMBRE_MIN . ' y ' . RegistroValidator::NOMBRE_MAX . ' caracteres).';
        }

        if (!array_key_exists($moneda, Dinero::MONEDAS)) {
            $errors['moneda'] = 'Elige una moneda de la lista.';
        }

        return ['data' => ['nombre' => $nombre, 'moneda' => $moneda], 'errors' => $errors];
    }
}
