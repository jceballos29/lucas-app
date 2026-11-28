<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * TOTP (RFC 6238) con HMAC-SHA1, 6 dígitos y pasos de 30 s: lo que entienden Google Authenticator, Aegis, 2FAS, Authy, etc.
 * Lógica pura (el reloj y el azar se pasan o se piden aquí), sin base de datos.
 */
final class Totp
{
    public const PASO_SEGUNDOS = 30;
    public const DIGITOS = 6;
    /** Pasos de tolerancia hacia cada lado por relojes algo desajustados (±30 s). */
    public const VENTANA = 1;

    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo: 160 bits al azar, en base32 (32 caracteres). */
    public static function generarSecreto(): string
    {
        return self::base32Codificar(random_bytes(20));
    }

    public static function paso(int $ahora): int
    {
        return intdiv($ahora, self::PASO_SEGUNDOS);
    }

    /** Código de 6 dígitos del secreto (base32) en el paso dado. */
    public static function codigo(string $secretoBase32, int $paso): string
    {
        $clave = self::base32Decodificar($secretoBase32);
        $hmac = hash_hmac('sha1', pack('J', $paso), $clave, true);
        $offset = ord($hmac[19]) & 0x0F;
        $binario = ((ord($hmac[$offset]) & 0x7F) << 24) | (ord($hmac[$offset + 1]) << 16) | (ord($hmac[$offset + 2]) << 8) | ord($hmac[$offset + 3]);

        return str_pad((string) ($binario % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * ¿Es válido el código en este momento? Devuelve el PASO que coincidió (para que el llamador impida reutilizarlo) o null.
     * Se comparan todos los pasos de la ventana sin cortar antes, para no filtrar cuál acertó por el tiempo.
     */
    public static function verificar(string $secretoBase32, string $entrada, int $ahora): ?int
    {
        $entrada = preg_replace('/\s+/', '', $entrada) ?? '';
        if (preg_match('/^\d{6}$/', $entrada) !== 1) {
            return null;
        }

        $acierto = null;
        $actual = self::paso($ahora);
        for ($p = $actual - self::VENTANA; $p <= $actual + self::VENTANA; $p++) {
            if (hash_equals(self::codigo($secretoBase32, $p), $entrada)) {
                $acierto = $p;
            }
        }

        return $acierto;
    }

    /** URI `otpauth://` que las apps de autenticación aceptan (abrir el enlace o escanear su QR). */
    public static function uri(string $secretoBase32, string $correo, string $emisor = 'Lucas'): string
    {
        return 'otpauth://totp/' . rawurlencode($emisor . ':' . $correo)
            . '?secret=' . $secretoBase32 . '&issuer=' . rawurlencode($emisor)
            . '&algorithm=SHA1&digits=' . self::DIGITOS . '&period=' . self::PASO_SEGUNDOS;
    }

    /** El secreto en grupos de 4 para leerlo y teclearlo con comodidad. */
    public static function agrupar(string $secretoBase32): string
    {
        return trim(chunk_split($secretoBase32, 4, ' '));
    }

    public static function base32Codificar(string $binario): string
    {
        $bits = '';
        foreach (str_split($binario) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $salida = '';
        foreach (str_split($bits, 5) as $trozo) {
            $salida .= self::ALFABETO[(int) bindec(str_pad($trozo, 5, '0'))];
        }

        return $salida;
    }

    public static function base32Decodificar(string $texto): string
    {
        $texto = strtoupper(preg_replace('/[\s=-]+/', '', $texto) ?? '');
        $bits = '';
        foreach (str_split($texto) as $caracter) {
            $valor = strpos(self::ALFABETO, $caracter);
            if ($valor === false) {
                continue;
            }
            $bits .= str_pad(decbin($valor), 5, '0', STR_PAD_LEFT);
        }

        $binario = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binario .= chr((int) bindec($byte));
            }
        }

        return $binario;
    }
}
