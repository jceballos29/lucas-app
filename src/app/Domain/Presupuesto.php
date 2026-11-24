<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Progreso;

/** Límite de gasto de una categoría en un mes, con lo gastado hasta ahora (se calcula al leer, no se guarda). */
final class Presupuesto
{
    public function __construct(
        public readonly int $id,
        public readonly int $idUsuario,
        public readonly int $idCategoria,
        public readonly int $mes,
        public readonly int $anio,
        public readonly string $montoLimite,
        public readonly string $gastado,
        public readonly string $categoriaNombre = '',
        public readonly string $categoriaColor = '#8DA0AB',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_presupuesto'],
            (int) $row['id_usuario'],
            (int) $row['id_categoria'],
            (int) $row['mes'],
            (int) $row['anio'],
            (string) $row['monto_limite'],
            (string) $row['gastado'],
            (string) ($row['categoria_nombre'] ?? ''),
            (string) ($row['categoria_color'] ?? '#8DA0AB'),
        );
    }

    /** @return array{percent: int, estado: string, tone: string, restante: string} */
    public function progreso(): array
    {
        return Progreso::presupuesto($this->gastado, $this->montoLimite);
    }

    /** ¿Pide un aviso (RF-17)? Sí desde el 80 %. */
    public function requiereAviso(): bool
    {
        return $this->progreso()['estado'] !== 'ok';
    }
}
