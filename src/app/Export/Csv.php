<?php

declare(strict_types=1);

namespace App\Export;

/**
 * Escritura de archivos CSV pensada para abrirse en Excel en español (RF-23): separador «;», BOM UTF-8 (para las tildes) y
 * comillas dobles. Lo importante es la seguridad: una celda de texto que empiece por `=`, `+`, `-`, `@`, tabulador o retorno
 * de carro la interpretan Excel y LibreOffice como una FÓRMULA (inyección de fórmulas: `=HYPERLINK(...)`, `=cmd|...`), así que se
 * le antepone una comilla simple y pasa a ser texto. Todo es puro y se prueba en unit.php.
 */
final class Csv
{
    public const SEPARADOR = ';';
    public const BOM = "\xEF\xBB\xBF";

    /** Un texto de usuario (descripción, categoría, nombre de cuenta...) listo para ir en una celda. */
    public static function texto(string $valor): string
    {
        // Se mira el primer carácter que no sea un espacio (algunas hojas de cálculo ignoran los espacios iniciales); un
        // tabulador o un retorno de carro iniciales ya son peligrosos por sí mismos.
        $peligroso = preg_match('/^[ \x{00A0}]*[=+\-@\t\r]/u', $valor) === 1
            || (preg_last_error() !== PREG_NO_ERROR && str_contains("=+-@\t\r", $valor[0] ?? ''));   // UTF-8 inválido: se mira el primer byte

        if ($peligroso) {
            $valor = "'" . $valor;
        }

        return self::celda($valor);
    }

    /** Un valor que NO es texto de usuario (una fecha ISO, un número que genera el sistema): solo se entrecomilla si hace falta. */
    public static function celda(string $valor): string
    {
        // Se entrecomilla si trae el separador, comillas, saltos de línea o un tabulador; las comillas se duplican (RFC 4180).
        return preg_match('/[;"\r\n\t]/', $valor) === 1 ? '"' . str_replace('"', '""', $valor) . '"' : $valor;
    }

    /**
     * Una fila. Cada elemento es una celda ya preparada con {@see texto()}, {@see celda()} o {@see monto()}.
     *
     * @param list<string> $celdas
     */
    public static function fila(array $celdas): string
    {
        return implode(self::SEPARADOR, $celdas) . "\r\n";
    }

    /** Un monto («18000.50») para Excel en español: coma decimal y sin separador de miles («18000,50»). */
    public static function monto(string $monto): string
    {
        return str_replace('.', ',', $monto);
    }
}
