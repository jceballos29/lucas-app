<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Avance de un presupuesto (RF-16, RF-17) y de una meta de ahorro (RF-20). Puro y exacto: compara en centavos enteros,
 * nunca con float, así que un 79,99 % nunca cuenta como 80 % ni un peso de más pasa inadvertido.
 */
final class Progreso
{
    public const AVISO = 80;

    /**
     * Estado de un presupuesto:
     *  - `ok`: menos del 80 %; `cerca`: desde el 80 % hasta antes del límite;
     *  - `limite`: gastado EXACTAMENTE lo presupuestado; `excedido`: más que el límite.
     * `tone` es el tono de la barra (ok, warn, over) y `percent` el porcentaje entero (hacia abajo; puede pasar de 100).
     *
     * @return array{percent: int, estado: string, tone: string, restante: string}
     */
    public static function presupuesto(string $gastado, string $limite): array
    {
        $g = max(0, Dinero::aCentavos($gastado));
        $l = Dinero::aCentavos($limite);

        if ($l <= 0) {
            return ['percent' => 0, 'estado' => 'ok', 'tone' => 'ok', 'restante' => Dinero::deCentavos($l - $g)];
        }

        $estado = match (true) {
            $g > $l => 'excedido',
            $g === $l => 'limite',
            $g * 100 >= self::AVISO * $l => 'cerca',
            default => 'ok',
        };

        return [
            'percent' => intdiv($g * 100, $l),
            'estado' => $estado,
            'tone' => match ($estado) {
                'ok' => 'ok',
                'cerca' => 'warn',
                default => 'over',
            },
            'restante' => Dinero::deCentavos($l - $g),
        ];
    }

    /**
     * Avance de una meta: porcentaje entero (hacia abajo), lo que falta (nunca negativo) y si ya se alcanzó el objetivo.
     *
     * @return array{percent: int, faltante: string, lograda: bool}
     */
    public static function meta(string $ahorrado, string $objetivo): array
    {
        $a = max(0, Dinero::aCentavos($ahorrado));
        $o = Dinero::aCentavos($objetivo);

        return [
            'percent' => $o > 0 ? intdiv($a * 100, $o) : 0,
            'faltante' => Dinero::deCentavos(max(0, $o - $a)),
            'lograda' => $o > 0 && $a >= $o,
        ];
    }
}
