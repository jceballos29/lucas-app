<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dinero;

/**
 * Una obligación o deuda financiera del usuario o de su espacio compartido (Fase 11).
 *
 * La tasa mensual se guarda en puntos básicos enteros (`tasa_mensual_bps`: 100 bps = 1,00 % M.V., 185 bps = 1,85 % M.V.),
 * eliminando el uso de `float` en todo el cálculo de intereses.
 */
final class Deuda
{
    public const ESTADOS = [
        'activa' => 'En curso',
        'pagada' => 'Pagada',
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $idUsuario,
        public readonly string $nombre,
        public readonly ?string $acreedor,
        public readonly string $montoInicial,
        public readonly int $tasaMensualBps,
        public readonly string $cuotaMinima,
        public readonly ?int $diaPago,
        public readonly string $estado,
        public readonly string $capitalAbonado = '0.00',
        public readonly string $interesesPagados = '0.00',
        public readonly int $numPagos = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_deuda'],
            (int) $row['id_usuario'],
            (string) $row['nombre'],
            $row['acreedor'] === null ? null : (string) $row['acreedor'],
            (string) $row['monto_inicial'],
            (int) $row['tasa_mensual_bps'],
            (string) $row['cuota_minima'],
            $row['dia_pago'] === null ? null : (int) $row['dia_pago'],
            (string) $row['estado'],
            (string) ($row['capital_abonado'] ?? '0.00'),
            (string) ($row['intereses_pagados'] ?? '0.00'),
            (int) ($row['num_pagos'] ?? 0),
        );
    }

    public function activa(): bool
    {
        return $this->estado === 'activa';
    }

    /**
     * Saldo de capital pendiente por pagar (`max(0, montoInicial - capitalAbonado)`).
     */
    public function saldoCapital(): string
    {
        $inicial = Dinero::aCentavos($this->montoInicial);
        $abonado = Dinero::aCentavos($this->capitalAbonado);

        return Dinero::deCentavos(max(0, $inicial - $abonado));
    }

    /**
     * Interés estimado de un mes sobre el saldo de capital pendiente actual, en centavos enteros redondeados:
     * `interes = round(saldo_capital * tasa_mensual_bps / 10000)`.
     */
    public function interesMensual(): string
    {
        return self::calcularInteres($this->saldoCapital(), $this->tasaMensualBps);
    }

    public static function calcularInteres(string $saldoCapital, int $tasaMensualBps): string
    {
        $centavos = max(0, Dinero::aCentavos($saldoCapital));
        if ($centavos === 0 || $tasaMensualBps <= 0) {
            return '0.00';
        }

        $interesCentavos = intdiv($centavos * $tasaMensualBps + 5_000, 10_000);

        return Dinero::deCentavos($interesCentavos);
    }

    /**
     * Tasa mensual formateada como texto («1,85 % M.V.» o «Sin intereses (0 % )»).
     */
    public function tasaTexto(): string
    {
        if ($this->tasaMensualBps <= 0) {
            return '0 % M.V.';
        }

        $entero = intdiv($this->tasaMensualBps, 100);
        $dec = $this->tasaMensualBps % 100;

        return $entero . ($dec > 0 ? ',' . rtrim(str_pad((string) $dec, 2, '0', STR_PAD_LEFT), '0') : '') . ' % M.V.';
    }

    /**
     * Para el campo de edición de tasa («1,85» o «0»).
     */
    public function tasaParaCampo(): string
    {
        if ($this->tasaMensualBps <= 0) {
            return '0';
        }

        $entero = intdiv($this->tasaMensualBps, 100);
        $dec = $this->tasaMensualBps % 100;

        return $entero . ($dec > 0 ? ',' . rtrim(str_pad((string) $dec, 2, '0', STR_PAD_LEFT), '0') : '');
    }

    /**
     * @return array{percent: int, saldo: string, abonado: string, pagada: bool}
     */
    public function progreso(): array
    {
        $inicial = max(1, Dinero::aCentavos($this->montoInicial));
        $abonado = min($inicial, max(0, Dinero::aCentavos($this->capitalAbonado)));
        $percent = (int) min(100, intdiv($abonado * 100, $inicial));
        $saldo = $this->saldoCapital();

        return [
            'percent' => $percent,
            'saldo' => $saldo,
            'abonado' => Dinero::deCentavos($abonado),
            'pagada' => $this->estado === 'pagada' || Dinero::aCentavos($saldo) === 0,
        ];
    }
}
