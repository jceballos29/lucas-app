<?php

declare(strict_types=1);

namespace App\Domain;

/** Un ingreso o un gasto. Los campos de la cuenta y la categoría vienen del JOIN del listado y sirven para mostrarlo. */
final class Movimiento
{
    public function __construct(
        public readonly int $id,
        public readonly int $idUsuario,
        public readonly int $idCuenta,
        public readonly int $idCategoria,
        public readonly string $tipo,
        public readonly string $monto,
        public readonly string $fecha,
        public readonly ?string $descripcion,
        public readonly string $cuentaNombre = '',
        public readonly string $categoriaNombre = '',
        public readonly string $categoriaColor = '#8DA0AB',
        public readonly string $autorNombre = '',
        public readonly ?string $etiqueta = null,
        public readonly ?string $grupoSplit = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_transaccion'],
            (int) $row['id_usuario'],
            (int) $row['id_cuenta'],
            (int) $row['id_categoria'],
            (string) $row['tipo'],
            (string) $row['monto'],
            (string) $row['fecha'],
            $row['descripcion'] === null ? null : (string) $row['descripcion'],
            (string) ($row['cuenta_nombre'] ?? ''),
            (string) ($row['categoria_nombre'] ?? ''),
            (string) ($row['categoria_color'] ?? '#8DA0AB'),
            (string) ($row['autor_nombre'] ?? ''),
            isset($row['etiqueta']) && $row['etiqueta'] !== '' ? (string) $row['etiqueta'] : null,
            isset($row['grupo_split']) && $row['grupo_split'] !== '' ? (string) $row['grupo_split'] : null,
        );
    }

    public function esIngreso(): bool
    {
        return $this->tipo === 'ingreso';
    }
}
