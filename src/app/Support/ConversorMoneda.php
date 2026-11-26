<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Cuenta;

/**
 * Conversión de monedas y consolidación de patrimonio 100 % en enteros (RNF-16, Fase 18).
 *
 * Las tasas se representan en millonésimas enteras (`tasa_micros`): `1 USD = 4.200,50 COP` es `4_200_500_000`.
 * No se utiliza `float` en ningún cálculo: la aritmética usa centavos enteros y millonésimas con redondeo mitad-arriba.
 */
final class ConversorMoneda
{
    /** 1,000000 en millonésimas. */
    public const ESCALA = 1_000_000;

    /** Tasa máxima admitida: 1.000.000,000000 unidades destino por 1 unidad origen. */
    public const MAX_TASA_MICROS = 1_000_000_000_000;

    /**
     * Parsea una tasa de cambio escrita por una persona a millonésimas enteras (`> 0`).
     *
     * Acepta formato colombiano (`4.200,50`, `4.200`, `0,925`, `1,085`) y formato decimal con punto (`4200.5`,
     * `0.925`, `0.000238`, `1.08`). Ante la duda de un único punto con exactamente 3 dígitos después y parte entera >= 1
     * (`4.200`), se interpreta como separador de miles igual que {@see Dinero::parse()}.
     */
    public static function parseTasaMicros(string $texto): ?int
    {
        $t = preg_replace('/\s+/u', '', $texto) ?? '';
        if ($t === '' || str_starts_with($t, '-') || str_starts_with($t, '−')) {
            return null;
        }

        $entero = '';
        $dec = '';

        if (preg_match('/^([1-9]\d{0,2}(?:\.\d{3})+)(?:,(\d{1,6}))?$/', $t, $m) === 1) {
            $entero = str_replace('.', '', $m[1]);
            $dec = $m[2] ?? '';
        } elseif (preg_match('/^(\d+)(?:[,.](\d{1,6}))?$/', $t, $m) === 1) {
            $entero = $m[1];
            $dec = $m[2] ?? '';
        } else {
            return null;
        }

        $entero = ltrim($entero, '0');
        $entero = $entero === '' ? '0' : $entero;
        if (strlen($entero) > 7) {
            return null;
        }

        $micros = (int) $entero * self::ESCALA + (int) str_pad($dec, 6, '0');
        if ($micros <= 0 || $micros > self::MAX_TASA_MICROS) {
            return null;
        }

        return $micros;
    }

    /** Formatea `tasa_micros` para mostrar o prellenar un campo (`4_200_500_000` → «4.200,5»). */
    public static function formatearTasa(int $micros): string
    {
        if ($micros <= 0) {
            return '0';
        }

        $entero = intdiv($micros, self::ESCALA);
        $frac = $micros % self::ESCALA;
        $enteroFmt = number_format($entero, 0, ',', '.');

        if ($frac === 0) {
            return $enteroFmt;
        }

        $dec = rtrim(str_pad((string) $frac, 6, '0', STR_PAD_LEFT), '0');

        return $enteroFmt . ',' . $dec;
    }

    /**
     * Convierte un monto multiplicando por `tasa_micros` (1 origen = tasa destino).
     * Divide la tasa en parte entera y fraccionaria para evitar desbordamientos en int64.
     */
    public static function convertirConTasa(string $monto, int $tasaMicros): string
    {
        if ($tasaMicros <= 0) {
            return '0.00';
        }

        $centavos = Dinero::aCentavos($monto);
        if ($centavos === 0) {
            return '0.00';
        }

        $negativo = $centavos < 0;
        $abs = abs($centavos);
        $entero = intdiv($tasaMicros, self::ESCALA);
        $frac = $tasaMicros % self::ESCALA;

        $parteFracc = intdiv($abs * $frac + intdiv(self::ESCALA, 2), self::ESCALA);
        $resultado = $abs * $entero + $parteFracc;

        if ($resultado > Dinero::MAX_CENTAVOS) {
            $resultado = Dinero::MAX_CENTAVOS;
        }

        return Dinero::deCentavos($negativo ? -$resultado : $resultado);
    }

    /**
     * Convierte un monto dividiendo por la tasa inversa `tasaInversaMicros` (1 destino = tasa origen).
     */
    public static function convertirInversa(string $monto, int $tasaInversaMicros): string
    {
        if ($tasaInversaMicros <= 0) {
            return '0.00';
        }

        $centavos = Dinero::aCentavos($monto);
        if ($centavos === 0) {
            return '0.00';
        }

        $negativo = $centavos < 0;
        $abs = abs($centavos);
        $resultado = intdiv($abs * self::ESCALA + intdiv($tasaInversaMicros, 2), $tasaInversaMicros);

        if ($resultado > Dinero::MAX_CENTAVOS) {
            $resultado = Dinero::MAX_CENTAVOS;
        }

        return Dinero::deCentavos($negativo ? -$resultado : $resultado);
    }

    /**
     * Calcula la tasa implícita en millonésimas (`montoDestino / montoOrigen`), o null si algún monto es `<= 0`.
     */
    public static function tasaImplicita(string $montoOrigen, string $montoDestino): ?int
    {
        $orig = Dinero::aCentavos($montoOrigen);
        $dest = Dinero::aCentavos($montoDestino);
        if ($orig <= 0 || $dest <= 0) {
            return null;
        }

        $tasa = intdiv($dest * self::ESCALA + intdiv($orig, 2), $orig);

        return ($tasa > 0 && $tasa <= self::MAX_TASA_MICROS) ? $tasa : null;
    }

    /**
     * Convierte un monto de `$desde` a `$hacia` usando el mapa de tasas (`"ORIG:DEST" => tasa_micros`).
     * Busca primero la tasa directa y, si no existe, la inversa. Devuelve `null` si no hay tasa registrada.
     *
     * @param array<string, int> $tasas
     */
    public static function convertir(string $monto, string $desde, string $hacia, array $tasas): ?string
    {
        if ($desde === $hacia) {
            return Dinero::deCentavos(Dinero::aCentavos($monto));
        }

        $directa = $tasas["$desde:$hacia"] ?? null;
        if ($directa !== null && $directa > 0) {
            return self::convertirConTasa($monto, $directa);
        }

        $inversa = $tasas["$hacia:$desde"] ?? null;
        if ($inversa !== null && $inversa > 0) {
            return self::convertirInversa($monto, $inversa);
        }

        return null;
    }

    /**
     * Consolida el patrimonio de una lista de cuentas en `$monedaBase` (solo cuentas activas suman al total).
     *
     * @param list<Cuenta> $cuentas
     * @param array<string, int> $tasas
     * @return array{total: string, sinTasa: list<string>, porCuenta: array<int, string|null>}
     */
    public static function consolidarCuentas(array $cuentas, string $monedaBase, array $tasas): array
    {
        $totalCentavos = 0;
        $sinTasa = [];
        $porCuenta = [];

        foreach ($cuentas as $c) {
            $monedaCuenta = $c->monedaEfectiva($monedaBase);
            $convertido = self::convertir($c->saldo, $monedaCuenta, $monedaBase, $tasas);
            $porCuenta[$c->id] = $convertido;

            if (!$c->activa) {
                continue;
            }

            if ($convertido !== null) {
                $totalCentavos += Dinero::aCentavos($convertido);
            } elseif (!in_array($monedaCuenta, $sinTasa, true)) {
                $sinTasa[] = $monedaCuenta;
            }
        }

        return [
            'total' => Dinero::deCentavos($totalCentavos),
            'sinTasa' => $sinTasa,
            'porCuenta' => $porCuenta,
        ];
    }
}
