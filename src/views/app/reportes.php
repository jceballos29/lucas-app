<?php

declare(strict_types=1);

use App\View\Ui;

/** Vista de maqueta (datos de ejemplo, sin lógica). Se conectará a datos reales en su fase. */
$months = [
    ['label' => 'Jun', 'value' => 1100000],
    ['label' => 'Jul', 'value' => 1400000],
    ['label' => 'Ago', 'value' => 960000],
    ['label' => 'Sep', 'value' => 1640000],
    ['label' => 'Oct', 'value' => 1280000],
    ['label' => 'Nov', 'value' => 1120000],
];
// Texto sobre cada barra en millones («$1,1 M»), con coma decimal.
$items = array_map(static fn (array $m): array => $m + ['display' => '$' . number_format($m['value'] / 1000000, 1, ',', '') . ' M'], $months);
$average = (int) round(array_sum(array_column($months, 'value')) / count($months));
?>
<?= Ui::pageHead('Reportes', 'Evolución de tus gastos', Ui::button('Exportar CSV', ['href' => '#', 'variant' => 'secondary', 'icon' => 'download'])) ?>

<?= Ui::cardOpen(['title' => 'Gastos de los últimos 6 meses', 'subtitle' => 'Promedio mensual: ' . Ui::pesos($average)]) ?>
    <?= Ui::bars($items, 'Gráfico de barras de gastos mensuales de junio a noviembre') ?>
<?= Ui::cardClose() ?>
<?= Ui::fab('movimiento-form.php', 'Nuevo movimiento') ?>
