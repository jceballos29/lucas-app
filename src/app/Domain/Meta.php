<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Progreso;

/** Meta de ahorro con lo aportado hasta ahora (la suma de sus aportes, calculada al leer). */
final class Meta
{
    public const ESTADOS = ['en_curso' => 'En curso', 'lograda' => 'Lograda', 'cancelada' => 'Cancelada'];

    public function __construct(
        public readonly int $id,
        public readonly int $idUsuario,
        public readonly string $nombre,
        public readonly string $montoObjetivo,
        public readonly ?string $fechaLimite,
        public readonly string $estado,
        public readonly string $ahorrado,
        public readonly int $numAportes = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_meta'],
            (int) $row['id_usuario'],
            (string) $row['nombre'],
            (string) $row['monto_objetivo'],
            $row['fecha_limite'] === null ? null : (string) $row['fecha_limite'],
            (string) $row['estado'],
            (string) $row['ahorrado'],
            (int) ($row['num_aportes'] ?? 0),
        );
    }

    /** @return array{percent: int, faltante: string, lograda: bool} */
    public function progreso(): array
    {
        return Progreso::meta($this->ahorrado, $this->montoObjetivo);
    }

    public function enCurso(): bool
    {
        return $this->estado === 'en_curso';
    }

    /** Una meta en curso cuya fecha límite ya pasó (comparando con `$hoy`, `AAAA-MM-DD`). */
    public function vencida(string $hoy): bool
    {
        return $this->enCurso() && $this->fechaLimite !== null && $this->fechaLimite < $hoy;
    }
}
