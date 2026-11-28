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

    public static function esPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }
}
