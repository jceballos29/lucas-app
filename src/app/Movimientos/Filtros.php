<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Validation\MovimientoValidator;

/**
 * Los filtros del listado de movimientos (RF-12) tal como llegan en la URL. Se limpian aquí: una fecha mal escrita o un id
 * que no es número se ignoran, no rompen la página. Que el id de cuenta/categoría sea del usuario no hace falta
 * comprobarlo: la consulta siempre filtra además por el usuario de la sesión.
 */
final class Filtros
{
    public const POR_PAGINA = 20;

    /**
     * @param array<string, mixed> $src Normalmente $_GET (desde, hasta, cuenta, categoria, tipo, etiqueta).
     * @return array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string, etiqueta: string}
     */
    public static function desde(array $src): array
    {
        $texto = static fn (string $k): string => is_string($src[$k] ?? null) ? trim($src[$k]) : '';
        $id = static fn (string $k): int => ($v = $texto($k)) !== '' && ctype_digit($v) && strlen($v) < 10 ? (int) $v : 0;
        $fecha = static fn (string $k): string => MovimientoValidator::validDate($texto($k)) ? $texto($k) : '';
        $tipo = $texto('tipo');
        $etiqueta = MovimientoValidator::normalizarEtiqueta($texto('etiqueta')) ?? '';

        return [
            'desde' => $fecha('desde'),
            'hasta' => $fecha('hasta'),
            'cuenta' => $id('cuenta'),
            'categoria' => $id('categoria'),
            'tipo' => in_array($tipo, ['ingreso', 'gasto'], true) ? $tipo : '',
            'etiqueta' => $etiqueta,
        ];
    }

    /**
     * Los filtros activos como parte de una URL (`desde=2026-11-01&tipo=gasto`), sin los vacíos; con `$pagina` > 1 la añade.
     *
     * @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string, etiqueta?: string} $f
     */
    public static function query(array $f, int $pagina = 1): string
    {
        $params = array_filter($f, static fn (string|int $v): bool => $v !== '' && $v !== 0);

        if ($pagina > 1) {
            $params['pagina'] = $pagina;
        }

        return http_build_query($params);
    }

    /** ¿Hay algún filtro puesto? @param array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string, etiqueta?: string} $f */
    public static function hayAlguno(array $f): bool
    {
        return self::query($f) !== '';
    }
}
