<?php

declare(strict_types=1);

use App\View\Html;
use App\View\Ui;

/** Vista de maqueta (datos de ejemplo, sin lógica). Se conectará a datos reales en su fase. */
$movements = [
    ['id' => 1, 'fecha' => '03/11/2026', 'descripcion' => 'Almuerzo', 'categoria' => 'Alimentación', 'cuenta' => 'Efectivo', 'monto' => 18000, 'tipo' => 'out'],
    ['id' => 2, 'fecha' => '02/11/2026', 'descripcion' => 'Bus', 'categoria' => 'Transporte', 'cuenta' => 'Efectivo', 'monto' => 12000, 'tipo' => 'out'],
    ['id' => 3, 'fecha' => '01/11/2026', 'descripcion' => 'Salario', 'categoria' => 'Salario', 'cuenta' => 'Bancolombia', 'monto' => 1800000, 'tipo' => 'in'],
    ['id' => 4, 'fecha' => '31/10/2026', 'descripcion' => 'Mercado', 'categoria' => 'Alimentación', 'cuenta' => 'Bancolombia', 'monto' => 95000, 'tipo' => 'out'],
    ['id' => 5, 'fecha' => '30/10/2026', 'descripcion' => 'Internet', 'categoria' => 'Servicios', 'cuenta' => 'Bancolombia', 'monto' => 75000, 'tipo' => 'out'],
    ['id' => 6, 'fecha' => '29/10/2026', 'descripcion' => 'Cine', 'categoria' => 'Entretenimiento', 'cuenta' => 'Nequi', 'monto' => 32000, 'tipo' => 'out'],
];
$accounts = ['Efectivo', 'Bancolombia', 'Nequi'];

$rows = array_map(static fn (array $m): array => [
    'fecha' => $m['fecha'],
    'descripcion' => new Html('<span class="cell-title">' . escape($m['descripcion']) . '</span>'),
    'categoria' => Ui::badge($m['categoria']),
    'cuenta' => $m['cuenta'],
    'monto' => Ui::money($m['monto'], $m['tipo']),
    'acciones' => Ui::actions([
        Ui::iconButton('edit', 'Editar ' . $m['descripcion'], ['href' => 'movimiento-form.php?id=' . $m['id']]),
        Ui::postForm('movimientos.php', ['id' => $m['id']], Ui::iconButton('trash', 'Eliminar ' . $m['descripcion'], [
            'type' => 'submit',
            'tone' => 'danger',
            'attrs' => ['data-confirm' => '¿Eliminar el movimiento «' . $m['descripcion'] . '»? Esta acción no se puede deshacer.', 'data-confirm-ok' => 'Eliminar'],
        ])),
    ]),
], $movements);
?>
<?= Ui::pageHead('Movimientos', 'Historial de ingresos y gastos', Ui::button('Nuevo movimiento', ['href' => 'movimiento-form.php', 'icon' => 'plus'])) ?>

<details class="filters">
    <summary class="filters__summary"><span><?= Ui::icon('filter') ?>Filtros</span><?= Ui::icon('chevron-down', 'filters__chevron') ?></summary>
    <form class="filters__form" action="movimientos.php" method="get">
        <?= Ui::field(['id' => 'desde', 'label' => 'Desde', 'type' => 'date', 'value' => '2026-10-01', 'flush' => true]) ?>
        <?= Ui::field(['id' => 'hasta', 'label' => 'Hasta', 'type' => 'date', 'value' => '2026-11-04', 'flush' => true]) ?>
        <?= Ui::select(['id' => 'f-cuenta', 'name' => 'cuenta', 'label' => 'Cuenta', 'blank' => 'Todas', 'options' => $accounts, 'flush' => true]) ?>
        <?= Ui::select(['id' => 'f-cat', 'name' => 'categoria', 'label' => 'Categoría', 'blank' => 'Todas', 'options' => ['Alimentación', 'Transporte', 'Servicios'], 'flush' => true]) ?>
        <?= Ui::select(['id' => 'f-tipo', 'name' => 'tipo', 'label' => 'Tipo', 'blank' => 'Todos', 'options' => ['gasto' => 'Gasto', 'ingreso' => 'Ingreso'], 'flush' => true]) ?>
        <?= Ui::button('Filtrar', ['type' => 'submit', 'variant' => 'dark']) ?>
    </form>
</details>

<?= Ui::table([
    'caption' => 'Movimientos',
    'columns' => [
        ['key' => 'fecha', 'label' => 'Fecha'],
        ['key' => 'descripcion', 'label' => 'Descripción'],
        ['key' => 'categoria', 'label' => 'Categoría'],
        ['key' => 'cuenta', 'label' => 'Cuenta'],
        ['key' => 'monto', 'label' => 'Monto', 'align' => 'end'],
        ['key' => 'acciones', 'label' => 'Acciones', 'kind' => 'actions'],
    ],
    'rows' => $rows,
]) ?>

<div class="toolbar">
    <?= Ui::pagination(1, 3, '#') ?>
    <?= Ui::button('Exportar CSV', ['href' => '#', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'download']) ?>
</div>
<?= Ui::fab('movimiento-form.php', 'Nuevo movimiento') ?>
