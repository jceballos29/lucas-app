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
        public readonly ?int $diaCorte = null,
        public readonly ?int $diaPagoLimite = null,
        public readonly int $tasaMensualBps = 0,
        public readonly ?string $moneda = null,
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
            isset($row['dia_corte']) && $row['dia_corte'] !== null ? (int) $row['dia_corte'] : null,
            isset($row['dia_pago_limite']) && $row['dia_pago_limite'] !== null ? (int) $row['dia_pago_limite'] : null,
            isset($row['tasa_mensual_bps']) ? (int) $row['tasa_mensual_bps'] : 0,
            isset($row['moneda']) && is_string($row['moneda']) && $row['moneda'] !== '' ? $row['moneda'] : null,
        );
    }

    /** Código ISO de la moneda de esta cuenta (si es null, hereda la moneda base activa). */
    public function monedaEfectiva(?string $monedaBase = null): string
    {
        return $this->moneda ?? $monedaBase ?? \App\Support\Dinero::monedaActual();
    }

    public function tasaTexto(): string
    {
        if ($this->tasaMensualBps <= 0) {
            return '0 % M.V.';
        }
        $entero = intdiv($this->tasaMensualBps, 100);
        $dec = $this->tasaMensualBps % 100;

        return $dec === 0
            ? $entero . ' % M.V.'
            : $entero . ',' . rtrim(sprintf('%02d', $dec), '0') . ' % M.V.';
    }

    public function tasaParaCampo(): string
    {
        if ($this->tasaMensualBps <= 0) {
            return '';
        }
        $entero = intdiv($this->tasaMensualBps, 100);
        $dec = $this->tasaMensualBps % 100;

        return $dec === 0 ? (string) $entero : $entero . ',' . rtrim(sprintf('%02d', $dec), '0');
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
