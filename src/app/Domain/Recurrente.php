<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Frecuencia;

/**
 * Un ingreso o gasto recurrente / fijo programado (Fase 9).
 */
final class Recurrente
{
    public function __construct(
        public readonly int $id,
        public readonly int $idUsuario,
        public readonly int $idCuenta,
        public readonly int $idCategoria,
        public readonly string $tipo,
        public readonly string $monto,
        public readonly string $frecuencia,
        public readonly string $proximaFecha,
        public readonly ?string $descripcion,
        public readonly bool $autoAplicar,
        public readonly bool $activo,
        public readonly string $cuentaNombre = '',
        public readonly string $categoriaNombre = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_recurrente'],
            (int) $row['id_usuario'],
            (int) $row['id_cuenta'],
            (int) $row['id_categoria'],
            (string) $row['tipo'],
            (string) $row['monto'],
            (string) $row['frecuencia'],
            (string) $row['proxima_fecha'],
            $row['descripcion'] === null ? null : (string) $row['descripcion'],
            (bool) $row['auto_aplicar'],
            (bool) $row['activo'],
            (string) ($row['cuenta_nombre'] ?? ''),
            (string) ($row['categoria_nombre'] ?? ''),
        );
    }

    public function esIngreso(): bool
    {
        return $this->tipo === 'ingreso';
    }

    public function frecuenciaTexto(): string
    {
        return Frecuencia::ETIQUETAS[$this->frecuencia] ?? $this->frecuencia;
    }

    public function vencido(string $hoy): bool
    {
        return $this->activo && $this->proximaFecha <= $hoy;
    }

    public function nombreVisible(): string
    {
        return $this->descripcion ?? $this->categoriaNombre;
    }
}
