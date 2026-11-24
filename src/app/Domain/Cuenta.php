<?php

declare(strict_types=1);

namespace App\Domain;

/** Una cuenta del usuario con su saldo ACTUAL ya calculado (no se guarda: se calcula, ver CuentaRepository). */
final class Cuenta
{
    /** Tipo → texto para mostrar. */
    public const TIPOS = [
        'efectivo' => 'Efectivo',
        'bancaria' => 'Bancaria',
        'tarjeta_credito' => 'Tarjeta de crédito',
        'ahorro' => 'Ahorro',
    ];

    /** Tipo → icono de Ui::icon(). */
    public const ICONOS = ['efectivo' => 'wallet', 'bancaria' => 'bank', 'tarjeta_credito' => 'card', 'ahorro' => 'coins'];

    public function __construct(
        public readonly int $id,
        public readonly int $idUsuario,
        public readonly string $nombre,
        public readonly string $tipo,
        public readonly string $saldoInicial,
        public readonly bool $activa,
        public readonly string $saldo,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_cuenta'],
            (int) $row['id_usuario'],
            (string) $row['nombre'],
            (string) $row['tipo'],
            (string) $row['saldo_inicial'],
            (bool) $row['activa'],
            (string) $row['saldo'],
        );
    }

    public function tipoTexto(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function icono(): string
    {
        return self::ICONOS[$this->tipo] ?? 'wallet';
    }
}
