<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Resultado de evaluar una sesión (R5): el usuario conectado (o null), si caducó por inactividad, si se le revocó el acceso, y qué debe
 * hacer quien llama (cerrar la sesión de PHP) y con qué moneda mostrar los montos. Inmutable y sin I/O.
 */
final class EstadoSesion
{
    /**
     * @param array{id: int, name: string, email: string, role: string, verified: bool, dos_pasos: bool, espacio_id: int|null, espacio_nombre: string|null, espacio_rol: string|null}|null $user
     */
    private function __construct(
        public readonly ?array $user,
        public readonly bool $caducada,
        public readonly bool $revocada,
        public readonly ?string $monedaActiva,
    ) {
    }

    /** Sin sesión iniciada (o sin cookie): no hay nada que cerrar. */
    public static function anonima(): self
    {
        return new self(null, false, false, null);
    }

    public static function caducada(): self
    {
        return new self(null, true, false, null);
    }

    public static function revocada(): self
    {
        return new self(null, false, true, null);
    }

    /** @param array{id: int, name: string, email: string, role: string, verified: bool, dos_pasos: bool, espacio_id: int|null, espacio_nombre: string|null, espacio_rol: string|null} $user */
    public static function activa(array $user, string $monedaActiva): self
    {
        return new self($user, false, false, $monedaActiva);
    }

    /** ¿Hay que destruir la sesión de PHP (y su registro)? Sí cuando caducó o se revocó. */
    public function cerrar(): bool
    {
        return $this->caducada || $this->revocada;
    }

    /** @return array{user: array<string, mixed>|null, expired: bool, revoked: bool} La forma que usa `resolve_user_session()`. */
    public function comoArreglo(): array
    {
        return ['user' => $this->user, 'expired' => $this->caducada, 'revoked' => $this->revocada];
    }
}
