<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Http\Failure;
use App\Http\Response;
use App\Reportes\Categorias;
use App\Support\Dinero;
use App\Support\Mes;
use App\View\View;
use DateTimeImmutable;
use RuntimeException;

/** Reportes (RF-22): gastos por categoría de un mes y evolución mensual de ingresos y gastos. */
final class ReporteController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;
        ['mes' => $mes, 'anio' => $anio] = Mes::desde($_GET, new DateTimeImmutable('now'));
        $meses = ($_GET['meses'] ?? '') === '12' ? 12 : 6;

        try {
            $reportes = $this->container->reportes();
            $categorias = Categorias::agrupar($reportes->gastosPorCategoria($user['id'], $mes, $anio, $espacioId));
            $etiquetasMes = $reportes->gastosPorEtiqueta($user['id'], $mes, $anio, $espacioId);
            $evolucion = $reportes->evolucion($user['id'], $mes, $anio, $meses, $espacioId);
        } catch (RuntimeException $error) {
            [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
            Response::plain($status, $message . "\n");
        }

        $gastos = array_map(static fn (array $m): string => $m['gastos'], $evolucion);

        View::page('app/reportes', [
            'mes' => $mes,
            'anio' => $anio,
            'anterior' => Mes::anterior($mes, $anio),
            'siguiente' => Mes::siguiente($mes, $anio),
            'meses' => $meses,
            'categorias' => $categorias,
            'etiquetasMes' => $etiquetasMes,
            'evolucion' => $evolucion,
            // Promedio mensual de gastos en la ventana, en centavos enteros.
            'promedio' => Dinero::deCentavos(intdiv(array_sum(array_map([Dinero::class, 'aCentavos'], $gastos)), max(1, count($gastos)))),
        ], 'Reportes', $user, 'app', 'reportes');
    }
}
