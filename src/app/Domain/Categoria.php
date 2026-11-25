<?php

declare(strict_types=1);

namespace App\Domain;

/** Una categoría de ingresos o gastos: predeterminada (sin dueño) o propia de un usuario. */
final class Categoria
{
    public const TIPOS = ['gasto' => 'Gastos', 'ingreso' => 'Ingresos'];

    public function __construct(
        public readonly int $id,
        public readonly ?int $idUsuario,
        public readonly string $nombre,
        public readonly string $tipo,
        public readonly string $color,
        public readonly ?int $idEspacio = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_categoria'],
            $row['id_usuario'] === null ? null : (int) $row['id_usuario'],
            (string) $row['nombre'],
            (string) $row['tipo'],
            (string) $row['color'],
            ($row['id_espacio'] ?? null) === null ? null : (int) $row['id_espacio'],
        );
    }

    public function esPredeterminada(): bool
    {
        return $this->idUsuario === null && $this->idEspacio === null;
    }
}
