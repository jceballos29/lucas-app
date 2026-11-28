<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Lectura segura de lo que llega en la petición (R4 de docs/AUDITORIA.md). Los controladores repetían decenas de veces
 * `is_string($_POST['x'] ?? null) ? trim($_POST['x']) : ''`; olvidar el `is_string` deja pasar un array (`x[]=1`) donde se espera un texto.
 * Aquí ningún método devuelve nada que no sea del tipo prometido: un campo ausente o con forma rara da el valor por defecto.
 * Lee las superglobales en el momento de la llamada (las pruebas las rellenan a mano).
 */
final class Peticion
{
    /** Texto de un campo de formulario (POST), sin espacios en los extremos. */
    public static function post(string $campo, string $porDefecto = ''): string
    {
        $v = $_POST[$campo] ?? null;

        return is_string($v) ? trim($v) : $porDefecto;
    }

    /** Texto de un campo de formulario (POST) tal cual llegó: contraseñas, acciones, contenido que no debe recortarse. */
    public static function postSinRecortar(string $campo, string $porDefecto = ''): string
    {
        $v = $_POST[$campo] ?? null;

        return is_string($v) ? $v : $porDefecto;
    }

    /** Entero positivo de un campo (POST): solo dígitos. Cualquier otra cosa (vacío, «-1», «1e3», un array) da el valor por defecto. */
    public static function postEntero(string $campo, int $porDefecto = 0): int
    {
        $v = $_POST[$campo] ?? null;

        return is_string($v) && ctype_digit($v) && strlen($v) < 19 ? (int) $v : $porDefecto;
    }

    /** Texto de un parámetro de la URL (GET), sin espacios en los extremos. */
    public static function get(string $campo, string $porDefecto = ''): string
    {
        $v = $_GET[$campo] ?? null;

        return is_string($v) ? trim($v) : $porDefecto;
    }

    /** Entero positivo de un parámetro de la URL (GET). */
    public static function getEntero(string $campo, int $porDefecto = 0): int
    {
        $v = $_GET[$campo] ?? null;

        return is_string($v) && ctype_digit($v) && strlen($v) < 19 ? (int) $v : $porDefecto;
    }

    /** «6M», «512K», «1G» o un número de bytes (el formato de los valores de php.ini) → bytes. 0 o -1 = sin límite. */
    public static function enBytes(string $tamano): int
    {
        $tamano = trim($tamano);
        $n = (int) $tamano;

        return match (strtoupper(substr($tamano, -1))) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
    }

    /** E4: ¿el cuerpo de esta petición superó `post_max_size` (y por eso PHP dejó `$_POST` vacío)? */
    public static function cuerpoExcedido(): bool
    {
        $limite = self::enBytes((string) ini_get('post_max_size'));

        return $limite > 0 && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limite;
    }

    public static function esPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }
}
