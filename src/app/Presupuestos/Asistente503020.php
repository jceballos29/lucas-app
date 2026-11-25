<?php

declare(strict_types=1);

namespace App\Presupuestos;

use App\Domain\Categoria;
use App\Support\Dinero;

/**
 * Calculadora pura de la regla financiera 50/30/20 (Fase 12).
 *
 * Divide el ingreso mensual disponible en tres bloques en centavos enteros sin pérdida por redondeo:
 *  - 50 % Necesidades básicas (Vivienda, Alimentación, Servicios, Transporte, Salud).
 *  - 30 % Estilo de vida y gustos (Educación, Entretenimiento, Otros gastos y categorías propias).
 *  - 20 % Ahorro y pago de deudas (destinado a Metas de ahorro y Planificador de Deudas).
 */
final class Asistente503020
{
    private const NECESIDADES = ['vivienda', 'alimentación', 'alimentacion', 'servicios', 'transporte', 'salud'];

    /**
     * @param list<Categoria> $categoriasGasto
     * @return array{
     *   ingreso: string,
     *   necesidades: string,
     *   estiloVida: string,
     *   ahorroDeudas: string,
     *   sugerencias: list<array{categoria: Categoria, grupo: string, monto: string}>
     * }
     */
    public static function distribuir(string $ingresoMensual, array $categoriasGasto): array
    {
        $totalCentavos = max(0, Dinero::aCentavos($ingresoMensual));
        $necesidadesCentavos = intdiv($totalCentavos * 50, 100);
        $estiloVidaCentavos = intdiv($totalCentavos * 30, 100);
        $ahorroDeudasCentavos = max(0, $totalCentavos - $necesidadesCentavos - $estiloVidaCentavos);

        $catNecesidades = [];
        $catEstiloVida = [];

        foreach ($categoriasGasto as $c) {
            if ($c->tipo !== 'gasto') {
                continue;
            }
            if (self::esNecesidad($c->nombre)) {
                $catNecesidades[] = $c;
            } else {
                $catEstiloVida[] = $c;
            }
        }

        $sugerencias = [];
        foreach (self::repartirBloque($necesidadesCentavos, $catNecesidades) as $item) {
            $sugerencias[] = ['categoria' => $item['categoria'], 'grupo' => 'necesidades', 'monto' => $item['monto']];
        }
        foreach (self::repartirBloque($estiloVidaCentavos, $catEstiloVida) as $item) {
            $sugerencias[] = ['categoria' => $item['categoria'], 'grupo' => 'estilo_vida', 'monto' => $item['monto']];
        }

        return [
            'ingreso' => Dinero::deCentavos($totalCentavos),
            'necesidades' => Dinero::deCentavos($necesidadesCentavos),
            'estiloVida' => Dinero::deCentavos($estiloVidaCentavos),
            'ahorroDeudas' => Dinero::deCentavos($ahorroDeudasCentavos),
            'sugerencias' => $sugerencias,
        ];
    }

    public static function esNecesidad(string $nombreCategoria): bool
    {
        $norm = mb_strtolower(trim($nombreCategoria), 'UTF-8');

        return in_array($norm, self::NECESIDADES, true);
    }

    /**
     * Reparte `$bloqueCentavos` entre las categorías del grupo en partes enteras exactas.
     *
     * @param list<Categoria> $categorias
     * @return list<array{categoria: Categoria, monto: string}>
     */
    private static function repartirBloque(int $bloqueCentavos, array $categorias): array
    {
        $n = count($categorias);
        if ($n === 0) {
            return [];
        }

        $base = intdiv($bloqueCentavos, $n);
        $resto = $bloqueCentavos - ($base * $n);
        $out = [];

        foreach ($categorias as $i => $cat) {
            $montoCat = $base + ($i === 0 ? $resto : 0);
            $out[] = [
                'categoria' => $cat,
                'monto' => Dinero::deCentavos($montoCat),
            ];
        }

        return $out;
    }
}
