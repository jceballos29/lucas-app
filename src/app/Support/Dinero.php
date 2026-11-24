<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dinero sin `float` (RNF-16). Un monto es una CADENA decimal normalizada con dos decimales («1234.50», «-80.00»),
 * igual que sale de MySQL (DECIMAL(12,2)); para sumar se pasa a centavos enteros. Todo es puro y se prueba en unit.php.
 */
final class Dinero
{
    /** Máximo de un DECIMAL(12,2): 9.999.999.999,99 (en centavos). */
    public const MAX_CENTAVOS = 999_999_999_999;

    /**
     * Lee un monto escrito por una persona, con formato colombiano: punto para los miles y coma para los decimales
     * («1.250.000», «1.250.000,50», «$ 80.000», «−500,5»); también «1234.5» (punto decimal) y «1234».
     * Ante la duda manda el punto de miles: «1.234» es mil doscientos treinta y cuatro. «1,234.56» (formato inglés) no se acepta.
     *
     * @return string|null El monto normalizado («1250000.50»), o null si no es un monto válido o se sale del rango.
     */
    public static function parse(string $texto): ?string
    {
        $t = preg_replace('/[\s$]/u', '', $texto) ?? '';

        if (preg_match('/^([-−])?(.+)$/u', $t, $m) !== 1) {
            return null;
        }

        $negativo = $m[1] !== '';
        $resto = $m[2];

        if (preg_match('/^(\d{1,3}(?:\.\d{3})+)(?:,(\d{1,2}))?$/', $resto, $p) === 1) {
            $entero = str_replace('.', '', $p[1]);
        } elseif (preg_match('/^(\d+)(?:[,.](\d{1,2}))?$/', $resto, $p) === 1) {
            $entero = $p[1];
        } else {
            return null;
        }

        $entero = ltrim($entero, '0');
        $entero = $entero === '' ? '0' : $entero;

        if (strlen($entero) > 10) {
            return null;
        }

        $decimales = str_pad($p[2] ?? '', 2, '0');
        $centavos = (int) $entero * 100 + (int) $decimales;

        if ($centavos > self::MAX_CENTAVOS) {
            return null;
        }

        return ($negativo && $centavos > 0 ? '-' : '') . $entero . '.' . $decimales;
    }

    /** «-1234.50» → -123450. Acepta lo que devuelve MySQL (con o sin decimales). */
    public static function aCentavos(string $monto): int
    {
        $negativo = str_starts_with($monto, '-');
        [$entero, $decimales] = array_pad(explode('.', ltrim($monto, '-+'), 2), 2, '');
        $centavos = (int) $entero * 100 + (int) str_pad(substr($decimales, 0, 2), 2, '0');

        return $negativo ? -$centavos : $centavos;
    }

    public static function deCentavos(int $centavos): string
    {
        $abs = abs($centavos);

        return ($centavos < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Suma de montos exacta (en centavos enteros). @param list<string> $montos */
    public static function sumar(array $montos): string
    {
        return self::deCentavos(array_sum(array_map([self::class, 'aCentavos'], $montos)));
    }

    /** Para mostrar: «$1.950.000», «−$80.000», «$1.250,50» (los decimales solo si los hay). */
    public static function formatear(string $monto): string
    {
        $centavos = self::aCentavos($monto);
        $abs = abs($centavos);
        $texto = '$' . number_format(intdiv($abs, 100), 0, ',', '.') . ($abs % 100 !== 0 ? ',' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT) : '');

        return ($centavos < 0 ? '−' : '') . $texto;
    }

    /** Para poner en un campo de texto: «1950000» o «1950000,50» (sin separador de miles). */
    public static function paraCampo(string $monto): string
    {
        $centavos = self::aCentavos($monto);
        $abs = abs($centavos);

        return ($centavos < 0 ? '-' : '') . intdiv($abs, 100) . ($abs % 100 !== 0 ? ',' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT) : '');
    }

    /**
     * Para el eje de un gráfico, corto: «$1,1 M» (millones con un decimal), «$850 mil» o «$500». El redondeo es entero
     * (centavos), sin float, y 999.500 ya cuenta como «$1 M» en vez de «$1000 mil».
     */
    public static function abreviado(string $monto): string
    {
        $centavos = self::aCentavos($monto);
        $abs = abs($centavos);

        if ($abs >= 99_950_000) {
            $decimas = intdiv($abs + 5_000_000, 10_000_000);
            $texto = '$' . intdiv($decimas, 10) . ($decimas % 10 !== 0 ? ',' . $decimas % 10 : '') . ' M';
        } elseif ($abs >= 100_000) {
            $texto = '$' . intdiv($abs + 50_000, 100_000) . ' mil';
        } else {
            $texto = '$' . intdiv($abs + 50, 100);
        }

        return ($centavos < 0 ? '−' : '') . $texto;
    }
}
