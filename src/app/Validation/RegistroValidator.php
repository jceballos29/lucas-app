<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Reglas del formulario de registro (RF-01). Es puro: no consulta la BD (la unicidad del correo la comprueba el
 * controlador y, en última instancia, la clave UNIQUE). Nunca lee un rol: el registro no lo admite (RF-25).
 */
final class RegistroValidator
{
    public const NOMBRE_MIN = 2;
    public const NOMBRE_MAX = 80;
    public const CORREO_MAX = 120;
    public const CLAVE_MIN = ClaveValidator::MIN;
    public const CLAVE_MAX_BYTES = ClaveValidator::MAX_BYTES;

    /**
     * @param array<string, mixed> $input Normalmente $_POST (campos nombre, correo, clave, clave2).
     * @return array{data: array{nombre: string, correo: string, clave: string}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', self::text($input['nombre'] ?? '')) ?? '');
        $correo = mb_strtolower(trim(self::text($input['correo'] ?? '')));
        $clave = self::text($input['clave'] ?? '');
        $clave2 = self::text($input['clave2'] ?? '');
        $errors = [];

        $largo = mb_strlen($nombre);
        if ($largo < self::NOMBRE_MIN || $largo > self::NOMBRE_MAX) {
            $errors['nombre'] = 'Escribe tu nombre (entre ' . self::NOMBRE_MIN . ' y ' . self::NOMBRE_MAX . ' caracteres).';
        }

        if ($correo === '' || mb_strlen($correo) > self::CORREO_MAX || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $errors['correo'] = 'Escribe un correo válido (máximo ' . self::CORREO_MAX . ' caracteres).';
        }

        $errors += ClaveValidator::errores($clave, $clave2, 'clave', 'clave2', $correo);

        return ['data' => ['nombre' => $nombre, 'correo' => $correo, 'clave' => $clave], 'errors' => $errors];
    }

    /** Un campo que no es texto (p. ej. `nombre[]=x` en el POST) cuenta como vacío. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
