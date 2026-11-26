<?php

declare(strict_types=1);

use App\Support\Dinero;
use App\Support\Mes;
use App\View\Fecha;
use App\View\Html;
use App\View\Ui;

/**
 * Reportes (RF-22): gastos por categoría de un mes y evolución mensual.
 *
 * @var int $mes
 * @var int $anio
 * @var array{mes: int, anio: int} $anterior
 * @var array{mes: int, anio: int} $siguiente
 * @var int $meses Cuántos meses muestra la evolución (6 o 12).
 * @var array{items: list<array{label: string, total: string, percent: float}>, total: string} $categorias
 * @var list<array{mes: int, anio: int, ingresos: string, gastos: string}> $evolucion
 * @var string $promedio Promedio mensual de gastos en la ventana.
 */
$enlace = static fn (array $m, int $n): string => '/reportes.php?' . http_build_query($m + ['meses' => $n]);
[$primerDia, $primerDiaSiguiente] = Mes::limites($mes, $anio);
$ultimoDia = (new DateTimeImmutable($primerDiaSiguiente))->modify('-1 day')->format('Y-m-d');
$csv = '/exportar.php?' . http_build_query(['desde' => $primerDia, 'hasta' => $ultimoDia]);

$monthNav = new Html('<div class="month-nav">'
    . Ui::iconButton('chevron-left', 'Mes anterior', ['href' => $enlace($anterior, $meses)])
    . '<span class="month-nav__label">' . escape(Mes::etiqueta($mes, $anio)) . '</span>'
    . Ui::iconButton('chevron-right', 'Mes siguiente', ['href' => $enlace($siguiente, $meses)])
    . '</div>');

$slices = array_map(static fn (array $i): array => ['label' => $i['label'], 'value' => $i['percent']], $categorias['items']);
$filasCategorias = array_map(static fn (array $i): array => [
    'categoria' => $i['label'],
    'gasto' => Ui::money($i['total'], 'out'),
    'porcentaje' => number_format($i['percent'], 1, ',', '') . ' %',
], $categorias['items']);

$etiquetasMes = $etiquetasMes ?? [];
$filasEtiquetas = array_map(static fn (array $e): array => [
    'etiqueta' => new Html('<a href="/movimientos.php?etiqueta=' . urlencode($e['etiqueta']) . '">' . Ui::badge('#' . $e['etiqueta'], 'brand') . '</a>'),
    'movimientos' => $e['cantidad'] . ($e['cantidad'] === 1 ? ' movimiento' : ' movimientos'),
    'gasto' => Ui::money($e['total'], 'out'),
], $etiquetasMes);

$barras = array_map(static fn (array $m): array => [
    'label' => Fecha::mesCorto($m['mes']),
    'value' => Dinero::aCentavos($m['gastos']),
    'display' => Dinero::abreviado($m['gastos']),
], $evolucion);
$filasEvolucion = array_map(static fn (array $m): array => [
    'mes' => Mes::etiqueta($m['mes'], $m['anio']),
    // Un mes sin ingresos o sin gastos muestra «$0», no «+$0» ni «−$0».
    'ingresos' => Ui::money($m['ingresos'], Dinero::aCentavos($m['ingresos']) === 0 ? 'plain' : 'in'),
    'gastos' => Ui::money($m['gastos'], Dinero::aCentavos($m['gastos']) === 0 ? 'plain' : 'out'),
    'balance' => Ui::money(Dinero::sumar([$m['ingresos'], '-' . $m['gastos']])),
], array_reverse($evolucion));
?>
<?= Ui::pageHead('Reportes', 'En qué se va tu dinero', Html::join([$monthNav, Ui::button('Exportar CSV', ['href' => $csv, 'variant' => 'secondary', 'icon' => 'download'])])) ?>

<?= Ui::cardOpen(['title' => 'Gastos por categoría', 'subtitle' => Mes::etiqueta($mes, $anio)]) ?>
    <?php if ($slices === []): ?>
        <p class="muted">No hay gastos en <?= escape(Mes::etiqueta($mes, $anio)) ?>.</p>
    <?php else: ?>
        <?= Ui::donut($slices, 'Gráfico circular de gastos por categoría de ' . Mes::etiqueta($mes, $anio), Dinero::formatear($categorias['total']), 'en gastos') ?>
        <?= Ui::table([
            'caption' => 'Gastos por categoría de ' . Mes::etiqueta($mes, $anio),
            'columns' => [
                ['key' => 'categoria', 'label' => 'Categoría'],
                ['key' => 'gasto', 'label' => 'Gasto', 'align' => 'end'],
                ['key' => 'porcentaje', 'label' => 'Del total', 'align' => 'end'],
            ],
            'rows' => $filasCategorias,
        ]) ?>
    <?php endif; ?>
<?= Ui::cardClose() ?>

<?php if ($filasEtiquetas !== []): ?>
<?= Ui::cardOpen(['title' => 'Gastos por etiqueta o proyecto (#tag)', 'subtitle' => Mes::etiqueta($mes, $anio)]) ?>
    <?= Ui::table([
        'caption' => 'Gastos por etiqueta de ' . Mes::etiqueta($mes, $anio),
        'columns' => [
            ['key' => 'etiqueta', 'label' => 'Etiqueta'],
            ['key' => 'movimientos', 'label' => 'Movimientos'],
            ['key' => 'gasto', 'label' => 'Total gastado', 'align' => 'end'],
        ],
        'rows' => $filasEtiquetas,
    ]) ?>
<?= Ui::cardClose() ?>
<?php endif; ?>

<?= Ui::cardOpen(['title' => 'Evolución de los gastos', 'subtitle' => 'Últimos ' . $meses . ' meses · Promedio mensual: ' . Dinero::formatear($promedio), 'action' => Ui::button($meses === 6 ? 'Ver 12 meses' : 'Ver 6 meses', ['href' => $enlace(['mes' => $mes, 'anio' => $anio], $meses === 6 ? 12 : 6), 'variant' => 'ghost', 'size' => 'sm'])]) ?>
    <?= Ui::bars($barras, 'Gráfico de barras de gastos mensuales de los últimos ' . $meses . ' meses hasta ' . Mes::etiqueta($mes, $anio)) ?>
<?= Ui::cardClose() ?>

<h2 class="section-title">Mes a mes</h2>
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
<?= Ui::fab('/movimiento-form.php', 'Nuevo movimiento') ?>
