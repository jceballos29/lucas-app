<?php

declare(strict_types=1);

use App\Domain\Cuenta;
use App\Domain\Presupuesto;
use App\Support\Dinero;
use App\Support\Mes;
use App\View\Fecha;
use App\View\Ui;

/**
 * Vista ejecutiva de reporte financiero lista para imprimir o guardar como PDF (Fase 19).
 *
 * @var int $mes
 * @var int $anio
 * @var int $meses
 * @var string $generadoEn
 * @var string $monedaBase
 * @var string $patrimonio
 * @var array<int, string|null> $porCuentaConsolidado
 * @var list<Cuenta> $cuentas
 * @var string $ingresos
 * @var string $gastos
 * @var string $balance
 * @var array{items: list<array{label: string, total: string, percent: float}>, total: string} $categorias
 * @var list<array{etiqueta: string, total: string, cantidad: int}> $etiquetasMes
 * @var list<array{mes: int, anio: int, ingresos: string, gastos: string}> $evolucion
 * @var list<Presupuesto> $presupuestos
 * @var string $promedio
 */
$periodo = Mes::etiqueta($mes, $anio);
$volverUrl = '/reportes.php?' . http_build_query(['mes' => $mes, 'anio' => $anio, 'meses' => $meses]);
$espacioNombre = is_array($currentUser ?? null) && ($currentUser['espacio_nombre'] ?? null) !== null
    ? 'Espacio compartido: ' . (string) $currentUser['espacio_nombre']
    : 'Espacio personal · ' . (string) ($currentUser['name'] ?? '');

$balanceCentavos = Dinero::aCentavos($balance);
$balanceTono = $balanceCentavos < 0 ? 'out' : ($balanceCentavos > 0 ? 'in' : 'plain');

$slices = array_map(static fn (array $i): array => ['label' => $i['label'], 'value' => $i['percent']], $categorias['items']);
$filasCategorias = array_map(static fn (array $i): array => [
    'categoria' => $i['label'],
    'gasto' => Ui::money($i['total'], 'out'),
    'porcentaje' => number_format($i['percent'], 1, ',', '') . ' %',
], $categorias['items']);

$filasEtiquetas = array_map(static fn (array $e): array => [
    'etiqueta' => '#' . $e['etiqueta'],
    'movimientos' => $e['cantidad'] . ($e['cantidad'] === 1 ? ' movimiento' : ' movimientos'),
    'gasto' => Ui::money($e['total'], 'out'),
], $etiquetasMes);

$filasCuentas = array_map(static function (Cuenta $c) use ($monedaBase, $porCuentaConsolidado): array {
    $monedaC = $c->monedaEfectiva($monedaBase);
    $equiv = $porCuentaConsolidado[$c->id] ?? null;
    $saldoTxt = Dinero::formatearEn($c->saldo, $monedaC);
    if ($monedaC !== $monedaBase && $equiv !== null) {
        $saldoTxt .= ' (≈ ' . Dinero::formatear($equiv) . ' ' . $monedaBase . ')';
    }

    return [
        'nombre' => $c->nombre,
        'tipo' => $c->tipoTexto(),
        'moneda' => $monedaC,
        'saldo' => $saldoTxt,
    ];
}, $cuentas);

$filasPresupuestos = array_map(static function (Presupuesto $p): array {
    $pr = $p->progreso();

    return [
        'categoria' => $p->categoriaNombre,
        'gastado' => Dinero::formatear($p->gastado),
        'limite' => Dinero::formatear($p->montoLimite),
        'consumo' => $pr['percent'] . ' %',
    ];
}, $presupuestos);

$barras = array_map(static fn (array $m): array => [
    'label' => Fecha::mesCorto($m['mes']),
    'value' => Dinero::aCentavos($m['gastos']),
    'display' => Dinero::abreviado($m['gastos']),
], $evolucion);

$filasEvolucion = array_map(static fn (array $m): array => [
    'mes' => Mes::etiqueta($m['mes'], $m['anio']),
    'ingresos' => Ui::money($m['ingresos'], Dinero::aCentavos($m['ingresos']) === 0 ? 'plain' : 'in'),
    'gastos' => Ui::money($m['gastos'], Dinero::aCentavos($m['gastos']) === 0 ? 'plain' : 'out'),
    'balance' => Ui::money(Dinero::sumar([$m['ingresos'], '-' . $m['gastos']])),
], array_reverse($evolucion));
?>
<div class="print-bar no-print">
    <?= Ui::button('Volver a Reportes', ['href' => $volverUrl, 'variant' => 'secondary', 'icon' => 'chevron-left']) ?>
    <?= Ui::button('Imprimir / Guardar PDF', ['icon' => 'printer', 'attrs' => ['data-print' => '1']]) ?>
</div>

<header class="print-header">
    <div>
        <div class="print-header__brand"><?= Ui::logo('/panel.php') ?></div>
        <h1>Reporte financiero · <?= escape($periodo) ?></h1>
        <p class="muted"><?= escape($espacioNombre) ?> · Moneda base: <?= escape($monedaBase) ?> · Generado el <?= escape(Fecha::simple($generadoEn)) ?></p>
    </div>
</header>

<div class="kpis mt-4">
    <?= Ui::stat('Patrimonio neto', Dinero::formatear($patrimonio), ['icon' => 'wallet', 'tone' => 'brand']) ?>
    <?= Ui::stat('Ingresos del mes', Ui::money($ingresos, 'in'), ['icon' => 'trend-up']) ?>
    <?= Ui::stat('Gastos del mes', Ui::money($gastos, 'out'), ['icon' => 'trend-down']) ?>
    <?= Ui::stat('Balance del mes', Ui::money($balance, $balanceTono), ['icon' => 'chart']) ?>
</div>

<?php if ($filasCuentas !== []): ?>
<div class="mt-4 print-section">
    <?= Ui::cardOpen(['title' => 'Cuentas activas', 'subtitle' => 'Patrimonio consolidado: ' . Dinero::formatear($patrimonio) . ' (' . $monedaBase . ')']) ?>
        <?= Ui::table([
            'caption' => 'Cuentas activas y saldos',
            'columns' => [
                ['key' => 'nombre', 'label' => 'Cuenta'],
                ['key' => 'tipo', 'label' => 'Tipo'],
                ['key' => 'moneda', 'label' => 'Moneda'],
                ['key' => 'saldo', 'label' => 'Saldo actual', 'align' => 'end'],
            ],
            'rows' => $filasCuentas,
        ]) ?>
    <?= Ui::cardClose() ?>
</div>
<?php endif; ?>

<div class="mt-4 print-section">
    <?= Ui::cardOpen(['title' => 'Gastos por categoría', 'subtitle' => $periodo]) ?>
        <?php if ($slices === []): ?>
            <p class="muted">No hay gastos registrados en <?= escape($periodo) ?>.</p>
        <?php else: ?>
            <?= Ui::donut($slices, 'Gráfico de gastos por categoría de ' . $periodo, Dinero::formatear($categorias['total']), 'en gastos') ?>
            <?= Ui::table([
                'caption' => 'Detalle de gastos por categoría de ' . $periodo,
                'columns' => [
                    ['key' => 'categoria', 'label' => 'Categoría'],
                    ['key' => 'gasto', 'label' => 'Gasto', 'align' => 'end'],
                    ['key' => 'porcentaje', 'label' => 'Del total', 'align' => 'end'],
                ],
                'rows' => $filasCategorias,
            ]) ?>
        <?php endif; ?>
    <?= Ui::cardClose() ?>
</div>

<?php if ($filasEtiquetas !== []): ?>
<div class="mt-4 print-section">
    <?= Ui::cardOpen(['title' => 'Gastos por etiqueta (#tag)', 'subtitle' => $periodo]) ?>
        <?= Ui::table([
            'caption' => 'Gastos por etiqueta de ' . $periodo,
            'columns' => [
                ['key' => 'etiqueta', 'label' => 'Etiqueta'],
                ['key' => 'movimientos', 'label' => 'Movimientos'],
                ['key' => 'gasto', 'label' => 'Total gastado', 'align' => 'end'],
            ],
            'rows' => $filasEtiquetas,
        ]) ?>
    <?= Ui::cardClose() ?>
</div>
<?php endif; ?>

<?php if ($filasPresupuestos !== []): ?>
<div class="mt-4 print-section">
    <?= Ui::cardOpen(['title' => 'Cumplimiento de presupuestos', 'subtitle' => $periodo]) ?>
        <?= Ui::table([
            'caption' => 'Presupuestos de ' . $periodo,
            'columns' => [
                ['key' => 'categoria', 'label' => 'Categoría'],
                ['key' => 'gastado', 'label' => 'Gastado', 'align' => 'end'],
                ['key' => 'limite', 'label' => 'Límite', 'align' => 'end'],
                ['key' => 'consumo', 'label' => 'Consumo', 'align' => 'end'],
            ],
            'rows' => $filasPresupuestos,
        ]) ?>
    <?= Ui::cardClose() ?>
</div>
<?php endif; ?>

<div class="mt-4 print-section">
    <?= Ui::cardOpen(['title' => 'Evolución mensual', 'subtitle' => 'Últimos ' . $meses . ' meses · Promedio mensual de gastos: ' . Dinero::formatear($promedio)]) ?>
        <?= Ui::bars($barras, 'Evolución de gastos de los últimos ' . $meses . ' meses') ?>
        <div class="mt-4">
            <?= Ui::table([
                'caption' => 'Ingresos, gastos y balance de los últimos ' . $meses . ' meses',
                'columns' => [
                    ['key' => 'mes', 'label' => 'Mes'],
                    ['key' => 'ingresos', 'label' => 'Ingresos', 'align' => 'end'],
                    ['key' => 'gastos', 'label' => 'Gastos', 'align' => 'end'],
                    ['key' => 'balance', 'label' => 'Balance', 'align' => 'end'],
                ],
                'rows' => $filasEvolucion,
            ]) ?>
        </div>
    <?= Ui::cardClose() ?>
</div>
