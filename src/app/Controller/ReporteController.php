<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Http\ManejadorErrores;
use App\Reportes\Categorias;
use App\Support\ConversorMoneda;
use App\Support\Dinero;
use App\Support\Mes;
use App\View\View;
use DateTimeImmutable;

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

        $monedaBase = Dinero::monedaActual();
        $tasas = $this->container->tasasCambio()->listMap($user['id'], $espacioId);
        $reportes = $this->container->reportes();
        $categorias = Categorias::agrupar($reportes->gastosPorCategoria($user['id'], $mes, $anio, $espacioId, $monedaBase, $tasas));
        $etiquetasMes = $reportes->gastosPorEtiqueta($user['id'], $mes, $anio, $espacioId, $monedaBase, $tasas);
        $evolucion = $reportes->evolucion($user['id'], $mes, $anio, $meses, $espacioId, $monedaBase, $tasas);

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

    /** Reporte financiero ejecutivo listo para imprimir o guardar como PDF desde el navegador (Fase 19). */
    public function imprimir(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;
        $hoy = new DateTimeImmutable('now');
        ['mes' => $mes, 'anio' => $anio] = Mes::desde($_GET, $hoy);
        $meses = ($_GET['meses'] ?? '') === '12' ? 12 : 6;

        ManejadorErrores::mensajeDeConsulta('No se pudo generar el reporte imprimible. Inténtalo más tarde.');

        $monedaBase = Dinero::monedaActual();
        $tasas = $this->container->tasasCambio()->listMap($user['id'], $espacioId);
        $reportes = $this->container->reportes();
        $totales = $reportes->totalesMes($user['id'], $mes, $anio, $espacioId, $monedaBase, $tasas);
        $categorias = Categorias::agrupar($reportes->gastosPorCategoria($user['id'], $mes, $anio, $espacioId, $monedaBase, $tasas));
        $etiquetasMes = $reportes->gastosPorEtiqueta($user['id'], $mes, $anio, $espacioId, $monedaBase, $tasas);
        $evolucion = $reportes->evolucion($user['id'], $mes, $anio, $meses, $espacioId, $monedaBase, $tasas);
        $presupuestos = $this->container->presupuestos()->listByMonth($user['id'], $mes, $anio, $espacioId, $monedaBase, $tasas);
        $cuentas = array_values(array_filter(
            $this->container->cuentas()->listByUser($user['id'], $espacioId),
            static fn (Cuenta $c): bool => $c->activa,
        ));
        $consolidado = ConversorMoneda::consolidarCuentas($cuentas, $monedaBase, $tasas);

        $gastos = array_map(static fn (array $m): string => $m['gastos'], $evolucion);

        View::page('app/reporte-imprimir', [
            'mes' => $mes,
            'anio' => $anio,
            'meses' => $meses,
            'generadoEn' => $hoy->format('Y-m-d'),
            'monedaBase' => $monedaBase,
            'patrimonio' => $consolidado['total'],
            'porCuentaConsolidado' => $consolidado['porCuenta'],
            'cuentas' => $cuentas,
            'ingresos' => $totales['ingresos'],
            'gastos' => $totales['gastos'],
            'balance' => Dinero::sumar([$totales['ingresos'], '-' . $totales['gastos']]),
            'categorias' => $categorias,
            'etiquetasMes' => $etiquetasMes,
            'evolucion' => $evolucion,
            'presupuestos' => $presupuestos,
            'promedio' => Dinero::deCentavos(intdiv(array_sum(array_map([Dinero::class, 'aCentavos'], $gastos)), max(1, count($gastos)))),
        ], 'Reporte financiero · ' . Mes::etiqueta($mes, $anio), $user, 'plain');
    }
}
