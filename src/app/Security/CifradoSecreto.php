<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Cifra los secretos TOTP antes de guardarlos (libsodium, XSalsa20-Poly1305). Sin esto, quien leyera la base de datos (una copia de
 * seguridad, una inyección SQL) tendría el segundo factor de todos. La clave sale de `APP_KEY` (no está en la base): una cadena larga
 * al azar, p. ej. `openssl rand -base64 32`. Si cambia, los secretos guardados dejan de poder leerse (hay que reactivar el 2FA).
 */
final class CifradoSecreto
{
    private string $clave;

    public function __construct(string $appKey)
    {
        if (strlen($appKey) < 32) {
            throw new RuntimeException('APP_KEY debe tener al menos 32 caracteres (p. ej. «openssl rand -base64 32»).');
        }

        // Se deriva una clave de 32 bytes con BLAKE2b y una etiqueta propia: la misma APP_KEY no sirve para otros usos por accidente.
        $this->clave = sodium_crypto_generichash('lucas:totp:v1:' . $appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function cifrar(string $claro): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($claro, $nonce, $this->clave));
    }

    /** @throws RuntimeException Si el dato está alterado o la clave no es la que lo cifró. */
    public function descifrar(string $cifrado): string
    {
        $crudo = base64_decode($cifrado, true);
        if ($crudo === false || strlen($crudo) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Secreto ilegible.');
        }

        $claro = sodium_crypto_secretbox_open(
            substr($crudo, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($crudo, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->clave,
        );

        if ($claro === false) {
            throw new RuntimeException('Secreto ilegible (¿cambió APP_KEY?).');
        }

        return $claro;
    }
}
