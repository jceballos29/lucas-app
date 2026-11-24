<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Support\Dinero;

/** Agrupa los gastos por categoría para el gráfico circular: las más grandes y, si sobran, «Otros». Puro. */
final class Categorias
{
    /**
     * @param list<array{nombre: string, total: string}> $filas Los gastos por categoría, de mayor a menor.
     * @param int $top Cuántas categorías se muestran sueltas; el resto se junta en «Otros» (solo si sobra más de una).
     * @return array{items: list<array{label: string, total: string, percent: float}>, total: string}
     */
    public static function agrupar(array $filas, int $top = 4): array
    {
        $total = array_sum(array_map(static fn (array $f): int => Dinero::aCentavos($f['total']), $filas));
        $items = [];

        // Si solo sobra UNA categoría, se muestra tal cual: agrupar una sola en «Otros» no ahorra nada.
        $visibles = count($filas) > $top + 1 ? array_slice($filas, 0, $top) : $filas;
        $resto = count($filas) > $top + 1 ? array_slice($filas, $top) : [];

        foreach ($visibles as $f) {
            $items[] = ['label' => $f['nombre'], 'total' => $f['total'], 'cents' => Dinero::aCentavos($f['total'])];
        }

        if ($resto !== []) {
            $suma = array_sum(array_map(static fn (array $f): int => Dinero::aCentavos($f['total']), $resto));
            $items[] = ['label' => 'Otros', 'total' => Dinero::deCentavos($suma), 'cents' => $suma];
        }

        return [
            'items' => array_map(static fn (array $i): array => [
                'label' => $i['label'],
                'total' => $i['total'],
                // Solo para dibujar y rotular: el dinero nunca pasa por aquí como float.
                'percent' => $total > 0 ? round($i['cents'] * 100 / $total, 1) : 0.0,
            ], $items),
            'total' => Dinero::deCentavos($total),
        ];
    }
}
