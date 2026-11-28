<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Movimientos\Filtros;
use App\Reportes\Categorias;
use App\Support\ConversorMoneda;
use App\Support\Dinero;
use App\Support\Mes;
use App\View\Fecha;
use App\View\View;
use DateTimeImmutable;

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

        $monedaBase = Dinero::monedaActual();
        $tasas = $this->container->tasasCambio()->listMap($user['id'], $espacioId);
        $this->container->gestionRecurrentes()->procesarVencidos($user['id'], $hoy->format('Y-m-d'), $espacioId);
        $recurrentesVencidos = $this->container->recurrentes()->listDueByUser($user['id'], $hoy->format('Y-m-d'), $espacioId);
        $cuentas = array_values(array_filter($this->container->cuentas()->listByUser($user['id'], $espacioId), static fn (Cuenta $c): bool => $c->activa));
        $consolidado = ConversorMoneda::consolidarCuentas($cuentas, $monedaBase, $tasas);
        $totales = $this->container->reportes()->totalesMes($user['id'], $mes, $anio, $espacioId, $monedaBase, $tasas);
        $categorias = Categorias::agrupar($this->container->reportes()->gastosPorCategoria($user['id'], $mes, $anio, $espacioId, $monedaBase, $tasas));
        $ultimos = $this->container->movimientos()->search($user['id'], Filtros::desde([]), 5, 0, $espacioId);
        $presupuestos = $this->container->presupuestos()->listByMonth($user['id'], $mes, $anio, $espacioId, $monedaBase, $tasas);

        $tarjetasPorVencer = [];
        $hoyStr = $hoy->format('Y-m-d');
        foreach ($cuentas as $c) {
            if ($c->tipo === 'tarjeta_credito' && $c->diaPagoLimite !== null && Dinero::aCentavos($c->saldo) < 0) {
                $proxPago = \App\Tarjetas\CicloTarjeta::proximaFecha($c->diaPagoLimite, $hoyStr);
                if ($proxPago !== null && $proxPago['dias'] <= 5) {
                    $tarjetasPorVencer[] = [
                        'id' => $c->id,
                        'nombre' => $c->nombre,
                        'deuda' => ltrim($c->saldo, '-'),
                        'moneda' => $c->moneda,
                        'fecha' => $proxPago['fecha'],
                        'dias' => $proxPago['dias'],
                    ];
                }
            }
        }

        // Los más cerca (o por encima) del límite primero: son los que importan en el panel.
        usort($presupuestos, static fn ($a, $b): int => $b->progreso()['percent'] <=> $a->progreso()['percent']);

        View::page('app/panel', [
            'nombre' => explode(' ', trim($user['name']))[0],
            'mesTexto' => Fecha::mesNombre($mes) . ' de ' . $anio,
            'mesCorto' => ucfirst(Fecha::mesNombre($mes)),
            'saldoTotal' => $consolidado['total'],
            'monedasSinTasa' => $consolidado['sinTasa'],
            'monedaBase' => $monedaBase,
            'numCuentas' => count($cuentas),
            'ingresos' => $totales['ingresos'],
            'gastos' => $totales['gastos'],
            'balance' => Dinero::sumar([$totales['ingresos'], '-' . $totales['gastos']]),
            'categorias' => $categorias,
            'ultimos' => $ultimos,
            'presupuestos' => $presupuestos,
            'recurrentesVencidos' => $recurrentesVencidos,
            'tarjetasPorVencer' => $tarjetasPorVencer,
        ], 'Panel', $user, 'app', 'panel');
    }
}
