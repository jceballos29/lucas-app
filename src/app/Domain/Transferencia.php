<?php

declare(strict_types=1);

namespace App\Domain;

/** Una transferencia entre dos cuentas del usuario (o un abono a una tarjeta de crédito). No es ingreso ni gasto. */
final class Transferencia
{
    public function __construct(
        public readonly int $id,
        public readonly int $idUsuario,
        public readonly int $idCuentaOrigen,
        public readonly int $idCuentaDestino,
        public readonly string $monto,
        public readonly string $fecha,
        public readonly ?string $descripcion,
        public readonly string $cuentaOrigenNombre = '',
        public readonly string $cuentaDestinoNombre = '',
        public readonly string $autorNombre = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_transferencia'],
            (int) $row['id_usuario'],
            (int) $row['id_cuenta_origen'],
            (int) $row['id_cuenta_destino'],
            (string) $row['monto'],
            (string) $row['fecha'],
            $row['descripcion'] === null ? null : (string) $row['descripcion'],
            (string) ($row['cuenta_origen_nombre'] ?? ''),
            (string) ($row['cuenta_destino_nombre'] ?? ''),
            (string) ($row['autor_nombre'] ?? ''),
        );
    }
}
