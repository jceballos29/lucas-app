<?php

declare(strict_types=1);

namespace App\Domain;

/** Un usuario de la aplicación, hecho a partir de una fila de `usuarios`. Inmutable y sin I/O. */
final class Usuario
{
    public const ROL_USUARIO = 'usuario';
    public const ROL_ADMIN = 'admin';

    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly string $correo,
        public readonly string $contrasenaHash,
        public readonly string $moneda,
        public readonly string $rol,
        public readonly bool $activo,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_usuario'],
            (string) $row['nombre'],
            (string) $row['correo'],
            (string) $row['contrasena_hash'],
            (string) $row['moneda'],
            (string) $row['rol'],
            (bool) $row['activo'],
        );
    }

    public function esAdmin(): bool
    {
        return $this->rol === self::ROL_ADMIN;
    }
}
