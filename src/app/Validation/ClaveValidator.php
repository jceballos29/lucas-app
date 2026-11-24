<?php

declare(strict_types=1);

namespace App\Validation;

/** Reglas de una contraseña NUEVA (registro, restablecer y cambiar). Puro. */
final class ClaveValidator
{
    public const MIN = 8;
    /** bcrypt solo usa los primeros 72 BYTES: más largo daría una falsa sensación de seguridad. */
    public const MAX_BYTES = 72;

    /**
     * @param string $campo Clave del error de la contraseña; `$campo2`, la de su confirmación.
     * @return array<string, string> Vacío si es válida.
     */
    public static function errores(string $clave, string $clave2, string $campo = 'clave', string $campo2 = 'clave2'): array
    {
        if (mb_strlen($clave) < self::MIN) {
            return [$campo => 'La contraseña debe tener al menos ' . self::MIN . ' caracteres.'];
        }

        if (strlen($clave) > self::MAX_BYTES) {
            return [$campo => 'La contraseña es demasiado larga (máximo ' . self::MAX_BYTES . ' bytes).'];
        }

        return $clave !== $clave2 ? [$campo2 => 'Las contraseñas no coinciden.'] : [];
    }
}
