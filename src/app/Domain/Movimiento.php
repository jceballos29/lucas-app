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
        public readonly int $numCuotas = 1,
        public readonly bool $conIntereses = true,
        public readonly int $tasaMensualBps = 0,
        public readonly ?string $cuentaMoneda = null,
        public readonly ?string $adjuntoRuta = null,
        public readonly ?string $adjuntoNombre = null,
        public readonly ?string $adjuntoMime = null,
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
            isset($row['num_cuotas']) ? max(1, (int) $row['num_cuotas']) : 1,
            isset($row['con_intereses']) ? (bool) $row['con_intereses'] : true,
            isset($row['tasa_mensual_bps']) ? max(0, (int) $row['tasa_mensual_bps']) : 0,
            isset($row['cuenta_moneda']) && is_string($row['cuenta_moneda']) && $row['cuenta_moneda'] !== '' ? $row['cuenta_moneda'] : null,
            isset($row['adjunto_ruta']) && is_string($row['adjunto_ruta']) && $row['adjunto_ruta'] !== '' ? $row['adjunto_ruta'] : null,
            isset($row['adjunto_nombre']) && is_string($row['adjunto_nombre']) && $row['adjunto_nombre'] !== '' ? $row['adjunto_nombre'] : null,
            isset($row['adjunto_mime']) && is_string($row['adjunto_mime']) && $row['adjunto_mime'] !== '' ? $row['adjunto_mime'] : null,
        );
    }

    public function tieneAdjunto(): bool
    {
        return $this->adjuntoRuta !== null && $this->adjuntoRuta !== '';
    }

    public function esIngreso(): bool
    {
        return $this->tipo === 'ingreso';
    }
}
