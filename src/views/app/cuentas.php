<?php

declare(strict_types=1);

use App\View\Ui;

/** Vista de maqueta (datos de ejemplo, sin lógica). Se conectará a datos reales en su fase. */
$accounts = [
    ['name' => 'Efectivo', 'type' => 'Efectivo', 'icon' => 'wallet', 'balance' => 180000],
    ['name' => 'Bancolombia ahorros', 'type' => 'Bancaria', 'icon' => 'bank', 'balance' => 1950000],
    ['name' => 'Nequi', 'type' => 'Ahorro', 'icon' => 'coins', 'balance' => 320000],
];
$total = array_sum(array_column($accounts, 'balance'));
?>
<?= Ui::pageHead('Cuentas', 'Dónde tienes tu dinero · Saldo total ' . Ui::pesos($total), Ui::button('Nueva cuenta', ['href' => '#', 'icon' => 'plus'])) ?>

<div class="cards-grid cards-grid--3">
    <?php foreach ($accounts as $a): ?>
        <?= Ui::cardOpen(['tag' => 'article', 'class' => 'account']) ?>
            <div class="account__head">
                <span class="account__icon"><?= Ui::icon($a['icon']) ?></span>
                <span class="account__name"><?= escape($a['name']) ?></span>
                <?= Ui::badge($a['type']) ?>
            </div>
            <div class="account__balance"><small>Saldo actual</small><strong><?= escape(Ui::pesos($a['balance'])) ?></strong></div>
            <div class="account__actions"><?= Ui::button('Editar', ['href' => '#', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'edit', 'attrs' => ['aria-label' => 'Editar ' . $a['name']]]) ?></div>
        <?= Ui::cardClose('article') ?>
    <?php endforeach; ?>
</div>
<?= Ui::fab('movimiento-form.php', 'Nuevo movimiento') ?>
