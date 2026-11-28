<?php

declare(strict_types=1);

namespace App\Validation;

/** Reglas de una contraseña NUEVA (registro, restablecer y cambiar). Puro. */
final class ClaveValidator
{
    public const MIN = 10;

    /** Las contraseñas de 10 caracteres o más que más se repiten en las filtraciones (en minúsculas): se rechazan aunque cumplan el largo. */
    private const COMUNES = [
        '1234567890', '0123456789', '12345678910', '123456789012', '1234567890123', 'qwertyuiop', 'qwertyuiopasdf', 'asdfghjkl1', 'asdfghjklñ', 'qwerty1234',
        'qwerty12345', 'qwertyui12', '1q2w3e4r5t', '1qaz2wsx3e', 'zxcvbnm123', 'password123', 'password1234', 'password12345', 'contrasena1', 'contrasena12',
        'contrasena123', 'contraseña1', 'contraseña12', 'contraseña123', 'iloveyou12', 'iloveyou123', 'welcome123', 'welcome1234', 'letmein123', 'admin12345',
        'administrador', 'abcdefghij', 'abcd123456', 'abc1234567', 'aaaaaaaaaa', 'mypassword1', 'mypassword12', 'changeme123', 'cambiame123', 'bienvenido1',
        'bienvenido12', 'bienvenido123', 'colombia123', 'colombia2026', 'colombia2025', 'mexico12345', 'argentina12', 'santiago123', 'sebastian12', 'alejandro12',
        'passw0rd123', 'p@ssw0rd123', 'p@ssword123', 'football123', 'baseball123', 'superman123', 'princess123', 'monkey12345', 'dragon12345', 'master12345',
    ];

    /** bcrypt solo usa los primeros 72 BYTES: más largo daría una falsa sensación de seguridad. */
    public const MAX_BYTES = 72;

    /**
     * @param string $campo Clave del error de la contraseña; `$campo2`, la de su confirmación.
     * @return array<string, string> Vacío si es válida.
     */
    public static function errores(string $clave, string $clave2, string $campo = 'clave', string $campo2 = 'clave2', ?string $correo = null): array
    {
        if (mb_strlen($clave) < self::MIN) {
            return [$campo => 'La contraseña debe tener al menos ' . self::MIN . ' caracteres.'];
        }

        if (strlen($clave) > self::MAX_BYTES) {
            return [$campo => 'La contraseña es demasiado larga (máximo ' . self::MAX_BYTES . ' bytes).'];
        }

        if (self::esComun($clave, $correo)) {
            return [$campo => 'Esa contraseña es demasiado fácil de adivinar. Elige otra más larga o menos predecible.'];
        }

        return $clave !== $clave2 ? [$campo2 => 'Las contraseñas no coinciden.'] : [];
    }

    /** Una de las más usadas, un solo carácter repetido o una secuencia, o la parte del correo antes de la «@» (B6). */
    private static function esComun(string $clave, ?string $correo): bool
    {
        $minuscula = mb_strtolower($clave);

        if (in_array($minuscula, self::COMUNES, true) || count(array_unique(mb_str_split($minuscula))) < 4) {
            return true;
        }

        $local = $correo !== null ? mb_strtolower(strstr($correo, '@', true) ?: '') : '';

        return mb_strlen($local) >= 4 && str_contains($minuscula, $local);
    }
}
