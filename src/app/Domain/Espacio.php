<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Un espacio financiero compartido / cuenta familiar (Fase 10).
 * Permite a varios usuarios compartir cuentas, movimientos, transferencias, presupuestos, metas y recurrentes.
 */
final class Espacio
{
    public const ROLES = [
        'propietario' => 'Propietario',
        'editor' => 'Editor',
        'lector' => 'Lector (solo lectura)',
    ];

    public const ROLES_INVITACION = [
        'editor' => 'Editor (puede registrar cuentas, gastos y presupuestos)',
        'lector' => 'Lector (solo puede ver el panel y los movimientos)',
    ];

    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly int $idCreador,
        public readonly string $moneda,
        public readonly string $rolUsuario = 'editor',
        public readonly int $numMiembros = 1,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_espacio'],
            (string) $row['nombre'],
            (int) $row['id_creador'],
            (string) $row['moneda'],
            (string) ($row['rol_usuario'] ?? 'editor'),
            (int) ($row['num_miembros'] ?? 1),
        );
    }

    public function esPropietario(): bool
    {
        return $this->rolUsuario === 'propietario';
    }

    public function puedeEscribir(): bool
    {
        return in_array($this->rolUsuario, ['propietario', 'editor'], true);
    }

    public function rolTexto(): string
    {
        return self::ROLES[$this->rolUsuario] ?? $this->rolUsuario;
    }
}
