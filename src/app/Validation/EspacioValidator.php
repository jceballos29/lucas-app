<?php

declare(strict_types=1);

namespace App\Validation;

use App\Domain\Espacio;
use App\Support\Dinero;

/**
 * Validación pura de formularios de espacios compartidos (crear/editar espacio e invitar miembro por correo).
 */
final class EspacioValidator
{
    public const NOMBRE_MIN = 2;
    public const NOMBRE_MAX = 80;
    public const CORREO_MAX = 120;

    /**
     * @param array<string, mixed> $input
     * @return array{data: array{nombre: string, moneda: string}, errors: array<string, string>}
     */
    public function validateEspacio(array $input): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', self::text($input['nombre'] ?? '')) ?? '');
        $moneda = strtoupper(trim(self::text($input['moneda'] ?? 'COP')));
        $errors = [];

        $len = mb_strlen($nombre);
        if ($len < self::NOMBRE_MIN) {
            $errors['nombre'] = 'Escribe un nombre de al menos ' . self::NOMBRE_MIN . ' caracteres (ej.: Hogar Familia Restrepo).';
        } elseif ($len > self::NOMBRE_MAX) {
            $errors['nombre'] = 'El nombre admite hasta ' . self::NOMBRE_MAX . ' caracteres.';
        }

        if (!array_key_exists($moneda, Dinero::MONEDAS)) {
            $errors['moneda'] = 'Elige una moneda válida.';
        }

        return [
            'data' => [
                'nombre' => $nombre,
                'moneda' => $moneda,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array{correo: string, rol: string}, errors: array<string, string>}
     */
    public function validateInvitacion(array $input): array
    {
        $correo = strtolower(trim(self::text($input['correo'] ?? '')));
        $rol = self::text($input['rol'] ?? 'editor');
        $errors = [];

        if ($correo === '' || strlen($correo) > self::CORREO_MAX || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $errors['correo'] = 'Escribe un correo electrónico válido.';
        }

        if (!array_key_exists($rol, Espacio::ROLES_INVITACION)) {
            $errors['rol'] = 'Elige el rol para la persona invitada.';
        }

        return [
            'data' => [
                'correo' => $correo,
                'rol' => $rol,
            ],
            'errors' => $errors,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
