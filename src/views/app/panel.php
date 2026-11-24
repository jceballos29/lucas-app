<?php

declare(strict_types=1);

use App\View\Ui;

/** Vista de maqueta (datos de ejemplo, sin lógica). Se conectará a datos reales en su fase. */
$income = 1800000;
$expense = 1120000;
$categories = [
    ['label' => 'Alimentación', 'value' => 40, 'tone' => 'teal'],
    ['label' => 'Transporte', 'value' => 22, 'tone' => 'navy'],
    ['label' => 'Servicios', 'value' => 18, 'tone' => 'sky'],
    ['label' => 'Entretenimiento', 'value' => 12, 'tone' => 'ink'],
    ['label' => 'Otros', 'value' => 8, 'tone' => 'soft'],
];
$recent = [
    ['title' => 'Almuerzo', 'meta' => 'Alimentación · 03 nov', 'amount' => 18000, 'kind' => 'out'],
    ['title' => 'Bus', 'meta' => 'Transporte · 02 nov', 'amount' => 12000, 'kind' => 'out'],
    ['title' => 'Salario', 'meta' => 'Ingreso · 01 nov', 'amount' => 1800000, 'kind' => 'in'],
    ['title' => 'Mercado', 'meta' => 'Alimentación · 31 oct', 'amount' => 95000, 'kind' => 'out'],
];
$budgets = [
    ['name' => 'Alimentación', 'percent' => 82],
    ['name' => 'Transporte', 'percent' => 40],
];
?>
<?= Ui::pageHead('Hola, Juan', 'Resumen de noviembre de 2026', Ui::button('Nuevo movimiento', ['href' => 'movimiento-form.php', 'icon' => 'plus'])) ?>

<div class="dashboard">
    <section class="balance" aria-label="Resumen del mes">
        <div>
            <span class="balance__label">Saldo total</span>
            <strong class="balance__amount"><?= escape(Ui::pesos(2450000)) ?></strong>
            <span class="balance__note">Repartido en 3 cuentas</span>
        </div>
        <div class="balance__month">
            <div class="balance__month-head"><span>Noviembre</span><span>Ingresos frente a gastos</span></div>
            <?= Ui::splitBar([['label' => 'Ingresos', 'value' => $income], ['label' => 'Gastos', 'value' => $expense]], 'Ingresos y gastos del mes') ?>
            <dl class="balance__stats">
                <div class="balance__stat"><dt><i class="balance__dot balance__dot--in"></i>Ingresos</dt><dd><?= escape(Ui::pesos($income)) ?></dd></div>
                <div class="balance__stat"><dt><i class="balance__dot balance__dot--out"></i>Gastos</dt><dd><?= escape(Ui::pesos($expense)) ?></dd></div>
                <div class="balance__stat"><dt>Balance del mes</dt><dd><?= escape('+' . Ui::pesos($income - $expense)) ?></dd></div>
            </dl>
        </div>
    </section>

    <div class="grid-2">
        <?= Ui::cardOpen(['title' => 'Gastos por categoría']) ?>
            <?= Ui::donut($categories, 'Gráfico circular de gastos por categoría', Ui::pesos($expense), 'en gastos') ?>
        <?= Ui::cardClose() ?>

        <?= Ui::cardOpen(['title' => 'Últimos movimientos', 'action' => Ui::button('Ver todos', ['href' => 'movimientos.php', 'variant' => 'ghost', 'size' => 'sm'])]) ?>
            <?php foreach ($recent as $m): ?>
                <?= Ui::listItem($m['title'], $m['meta'], Ui::money($m['amount'], $m['kind'])) ?>
            <?php endforeach; ?>
        <?= Ui::cardClose() ?>
    </div>

    <?= Ui::cardOpen(['title' => 'Presupuestos del mes', 'action' => Ui::button('Ver presupuestos', ['href' => 'presupuestos.php', 'variant' => 'ghost', 'size' => 'sm'])]) ?>
        <?php foreach ($budgets as $b): ?>
            <div class="budget-line">
                <div class="budget-line__head"><span><?= escape($b['name']) ?></span><?= Ui::badge($b['percent'] . ' %', $b['percent'] >= 80 ? 'warn' : 'neutral') ?></div>
                <?= Ui::progress($b['percent'], $b['name'], 'auto') ?>
            </div>
        <?php endforeach; ?>
    <?= Ui::cardClose() ?>
</div>
<?= Ui::fab('movimiento-form.php', 'Nuevo movimiento') ?>
