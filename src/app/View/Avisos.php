<?php

declare(strict_types=1);

namespace App\View;

use App\Domain\Presupuesto;
use App\Support\Dinero;

/** Los avisos de presupuesto (RF-17), iguales en el listado de presupuestos y en el panel. */
final class Avisos
{
    /** El aviso de un presupuesto, o null si está en orden (menos del 80 %). */
    public static function presupuesto(Presupuesto $p): ?Html
    {
        $pr = $p->progreso();
        $nombre = '<b>' . escape($p->categoriaNombre) . '</b>';

        $texto = match ($pr['estado']) {
            'cerca' => '<b>Aviso:</b> llevas el <b>' . $pr['percent'] . ' %</b> del presupuesto de ' . $nombre . '; te quedan ' . escape(Dinero::formatear($pr['restante'])) . '.',
            'limite' => '<b>Aviso:</b> llegaste al límite del presupuesto de ' . $nombre . ' (' . escape(Dinero::formatear($p->montoLimite)) . ').',
            'excedido' => '<b>Aviso:</b> superaste el presupuesto de ' . $nombre . ' en ' . escape(Dinero::formatear(ltrim($pr['restante'], '-'))) . '. Revisa tus gastos de esta categoría.',
            default => null,
        };

        return $texto === null ? null : Ui::notice(new Html($texto), 'warn');
    }
}
