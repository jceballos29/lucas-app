<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Repository\PresupuestoRepository;
use App\Repository\TasaCambioRepository;
use App\Support\Dinero;
use App\Support\Mes;

/**
 * El aviso de RF-17 para un gasto de una categoría y fecha: cerca del límite, en el límite o por encima del presupuesto del mes.
 * Se calcula al momento, no se guarda. (Antes estaba copiado en MovimientoController y RecurrenteController, y la copia de recurrentes
 * no convertía monedas; R1 de docs/AUDITORIA.md.)
 */
final class AvisoPresupuesto
{
    public function __construct(
        private readonly PresupuestoRepository $presupuestos,
        private readonly TasaCambioRepository $tasas,
    ) {
    }

    /** @return string|null El aviso, o null si no hay presupuesto o está en orden (menos del 80 %). */
    public function para(int $userId, int $categoriaId, string $fecha, ?int $espacioId = null): ?string
    {
        $mes = Mes::deFecha($fecha);
        if ($mes === null) {
            return null;
        }

        $presupuesto = $this->presupuestos->findForCategory(
            $userId,
            $categoriaId,
            $mes['mes'],
            $mes['anio'],
            $espacioId,
            Dinero::monedaActual(),
            $this->tasas->listMap($userId, $espacioId),
        );

        if ($presupuesto === null) {
            return null;
        }

        $pr = $presupuesto->progreso();
        $gastado = Dinero::formatear($presupuesto->gastado);
        $limite = Dinero::formatear($presupuesto->montoLimite);

        return match ($pr['estado']) {
            'cerca' => "Aviso: llevas el {$pr['percent']} % del presupuesto de «{$presupuesto->categoriaNombre}» ($gastado de $limite).",
            'limite' => "Aviso: llegaste al límite del presupuesto de «{$presupuesto->categoriaNombre}» ($limite).",
            'excedido' => "Aviso: superaste el presupuesto de «{$presupuesto->categoriaNombre}» ($gastado de $limite).",
            default => null,
        };
    }
}
