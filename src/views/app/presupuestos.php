<?php

declare(strict_types=1);

use App\View\Html;
use App\View\Ui;

/** Vista de maqueta (datos de ejemplo, sin lógica). Se conectará a datos reales en su fase. */
$budgets = [
    ['name' => 'Alimentación', 'spent' => 330000, 'limit' => 400000],
    ['name' => 'Transporte', 'spent' => 80000, 'limit' => 200000],
    ['name' => 'Entretenimiento', 'spent' => 150000, 'limit' => 120000],
    ['name' => 'Servicios', 'spent' => 210000, 'limit' => 300000],
    ['name' => 'Salud', 'spent' => 20000, 'limit' => 150000],
    ['name' => 'Educación', 'spent' => 90000, 'limit' => 100000],
];
/** Texto y tono de la etiqueta según el consumo (la misma regla de aviso al 80 % y 100 % de RF-17). */
$status = [
    'ok' => ['En orden', 'neutral', null],
    'warn' => ['Cerca del límite', 'warn', 'alert'],
    'over' => ['Excedido', 'danger', 'alert'],
];
$over = array_values(array_filter($budgets, static fn (array $b): bool => $b['spent'] > $b['limit']));

$monthNav = new Html('<div class="month-nav">'
    . Ui::iconButton('chevron-left', 'Mes anterior', ['href' => '#'])
    . '<span class="month-nav__label">Noviembre 2026</span>'
    . Ui::iconButton('chevron-right', 'Mes siguiente', ['href' => '#'])
    . '</div>');
?>
<?= Ui::pageHead('Presupuestos', 'Límites de gasto por categoría', Html::join([$monthNav, Ui::button('Nuevo presupuesto', ['href' => '#', 'icon' => 'plus'])])) ?>

<?php foreach ($over as $b): ?>
    <?= Ui::notice(new Html('<b>Aviso:</b> superaste el presupuesto de <b>' . escape($b['name']) . '</b> en ' . escape(Ui::pesos($b['spent'] - $b['limit'])) . '. Revisa tus gastos de esta categoría.'), 'warn') ?>
<?php endforeach; ?>

<div class="cards-grid cards-grid--3">
    <?php foreach ($budgets as $b): ?>
        <?php
        $percent = (int) floor($b['spent'] / $b['limit'] * 100);
        $tone = Ui::budgetTone($percent);
        [$text, $badgeTone, $badgeIcon] = $status[$tone];
        ?>
        <?= Ui::cardOpen(['tag' => 'article', 'class' => 'budget']) ?>
            <div class="budget__head"><span class="budget__name"><?= escape($b['name']) ?></span><?= Ui::badge($text, $badgeTone, $badgeIcon) ?></div>
            <?= Ui::progress($percent, $b['name'], $tone) ?>
            <div class="budget__foot"><span><?= escape(Ui::pesos($b['spent'])) ?> de <?= escape(Ui::pesos($b['limit'])) ?></span><b class="<?= $tone === 'over' ? 'is-over' : '' ?>"><?= $percent ?> %</b></div>
        <?= Ui::cardClose('article') ?>
    <?php endforeach; ?>
</div>
<?= Ui::fab('movimiento-form.php', 'Nuevo movimiento') ?>
