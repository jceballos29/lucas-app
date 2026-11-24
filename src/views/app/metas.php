<?php

declare(strict_types=1);

use App\View\Ui;

/** Vista de maqueta (datos de ejemplo, sin lógica). Se conectará a datos reales en su fase. */
$goals = [
    ['name' => 'Computador portátil', 'icon' => 'laptop', 'saved' => 1200000, 'target' => 3000000, 'due' => 'Límite: 30/06/2027'],
    ['name' => 'Viaje a Cartagena', 'icon' => 'plane', 'saved' => 300000, 'target' => 2000000, 'due' => 'Límite: 15/12/2027'],
    ['name' => 'Fondo de emergencia', 'icon' => 'shield', 'saved' => 2400000, 'target' => 3000000, 'due' => 'Sin fecha límite'],
    ['name' => 'Curso de inglés', 'icon' => 'book', 'saved' => 500000, 'target' => 500000, 'due' => 'Meta cumplida'],
];
?>
<?= Ui::pageHead('Metas de ahorro', 'Sigue el avance de lo que quieres lograr', Ui::button('Nueva meta', ['href' => '#', 'icon' => 'plus'])) ?>

<div class="cards-grid">
    <?php foreach ($goals as $g): ?>
        <?php
        $percent = (int) floor($g['saved'] / $g['target'] * 100);
        $done = $g['saved'] >= $g['target'];
        ?>
        <?= Ui::cardOpen(['tag' => 'article', 'class' => 'goal' . ($done ? ' is-done' : '')]) ?>
            <div class="goal__head">
                <span class="goal__icon"><?= Ui::icon($g['icon']) ?></span>
                <div class="goal__title"><span class="goal__name"><?= escape($g['name']) ?></span><span class="goal__due"><?= escape($g['due']) ?></span></div>
                <?php if ($done): ?><?= Ui::badge('Lograda', 'brand', 'check') ?><?php endif; ?>
            </div>
            <div class="goal__body">
                <div class="goal__amount"><?= escape(Ui::pesos($g['saved'])) ?> <small>de <?= escape(Ui::pesos($g['target'])) ?></small></div>
                <?= Ui::progress($percent, $g['name'], 'ok') ?>
                <div class="goal__foot"><span>Faltan <?= escape(Ui::pesos(max(0, $g['target'] - $g['saved']))) ?></span><b><?= $percent ?> %</b></div>
            </div>
            <?php if (!$done): ?><?= Ui::button('Añadir aporte', ['href' => '#', 'variant' => 'secondary', 'icon' => 'plus']) ?><?php endif; ?>
        <?= Ui::cardClose('article') ?>
    <?php endforeach; ?>
</div>
<?= Ui::fab('movimiento-form.php', 'Nuevo movimiento') ?>
