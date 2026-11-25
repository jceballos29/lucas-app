<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Http\Failure;
use App\Http\Response;
use App\Movimientos\Filtros;
use App\Reportes\Categorias;
use App\Support\Dinero;
use App\Support\Mes;
use App\View\Fecha;
use App\View\View;
use DateTimeImmutable;
use RuntimeException;

/** Panel principal del usuario (RF-21): saldo total, ingresos, gastos y balance del mes, gastos por categoría, últimos movimientos y presupuestos. */
final class PanelController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;
        $hoy = new DateTimeImmutable('now');
        ['mes' => $mes, 'anio' => $anio] = Mes::desde([], $hoy);

        try {
            $this->container->gestionRecurrentes()->procesarVencidos($user['id'], $hoy->format('Y-m-d'), $espacioId);
            $recurrentesVencidos = $this->container->recurrentes()->listDueByUser($user['id'], $hoy->format('Y-m-d'), $espacioId);
            $cuentas = array_values(array_filter($this->container->cuentas()->listByUser($user['id'], $espacioId), static fn (Cuenta $c): bool => $c->activa));
            $totales = $this->container->reportes()->totalesMes($user['id'], $mes, $anio, $espacioId);
            $categorias = Categorias::agrupar($this->container->reportes()->gastosPorCategoria($user['id'], $mes, $anio, $espacioId));
            $ultimos = $this->container->movimientos()->search($user['id'], Filtros::desde([]), 5, 0, $espacioId);
            $presupuestos = $this->container->presupuestos()->listByMonth($user['id'], $mes, $anio, $espacioId);
        } catch (RuntimeException $error) {
            [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
            Response::plain($status, $message . "\n");
        }

        // Los más cerca (o por encima) del límite primero: son los que importan en el panel.
        usort($presupuestos, static fn ($a, $b): int => $b->progreso()['percent'] <=> $a->progreso()['percent']);

        View::page('app/panel', [
            'nombre' => explode(' ', trim($user['name']))[0],
            'mesTexto' => Fecha::mesNombre($mes) . ' de ' . $anio,
            'mesCorto' => ucfirst(Fecha::mesNombre($mes)),
            'saldoTotal' => Dinero::sumar(array_map(static fn (Cuenta $c): string => $c->saldo, $cuentas)),
            'numCuentas' => count($cuentas),
            'ingresos' => $totales['ingresos'],
            'gastos' => $totales['gastos'],
            'balance' => Dinero::sumar([$totales['ingresos'], '-' . $totales['gastos']]),
            'categorias' => $categorias,
            'ultimos' => $ultimos,
            'presupuestos' => $presupuestos,
            'recurrentesVencidos' => $recurrentesVencidos,
        ], 'Panel', $user, 'app', 'panel');
    }
}
