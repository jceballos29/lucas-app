<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Usuario;

/**
 * Comprueba correo y contraseña (RF-02, RNF-01). No dice POR QUÉ falló: correo inexistente, clave incorrecta y
 * usuario inactivo devuelven lo mismo (null), y los tres hacen el mismo trabajo (un password_verify), para no
 * revelar qué correos existen ni por el mensaje ni por el tiempo de respuesta.
 */
final class Autenticador
{
    /** Hash bcrypt (coste 12) de una clave al azar que nadie conoce: se verifica cuando el correo no existe. */
    private const HASH_SENUELO = '$2y$12$a0aIfatZHIqMC6.Tb./B5.jL0XspR/6BfzvBaiy4mgejWPfROlHty';

    public function __construct(private readonly CredentialSource $usuarios)
    {
    }

    public function authenticate(string $correo, string $clave): ?Usuario
    {
        $usuario = $this->usuarios->findByEmail(mb_strtolower(trim($correo)));
        $hash = $usuario?->contrasenaHash ?? self::HASH_SENUELO;
        $correcta = password_verify($clave, $hash);

        if ($usuario === null || !$correcta || !$usuario->activo) {
            return null;
        }

        if (password_needs_rehash($usuario->contrasenaHash, PASSWORD_DEFAULT)) {
            $this->usuarios->updatePasswordHash($usuario->id, password_hash($clave, PASSWORD_DEFAULT));
        }

        return $usuario;
    }
}
