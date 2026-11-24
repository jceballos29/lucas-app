<?php

declare(strict_types=1);

use App\View\Ui;

/** Panel de presentación de las pantallas de acceso (solo en pantallas anchas). Los números son ilustrativos. */
?>
<section class="auth-hero">
    <?= Ui::logo('/login.php', 'dark') ?>
    <div class="auth-hero__body">
        <h2>Toma el control de tu dinero</h2>
        <p class="auth-hero__lead">Registra tus ingresos y gastos, fija presupuestos y alcanza tus metas de ahorro desde cualquier navegador.</p>
        <ul class="auth-hero__list">
            <li><?= Ui::icon('check-circle') ?>Cuentas y categorías a tu medida</li>
            <li><?= Ui::icon('check-circle') ?>Alertas cuando te acerques al límite</li>
            <li><?= Ui::icon('check-circle') ?>Reportes claros cada mes</li>
        </ul>
    </div>
    <aside class="auth-preview" aria-hidden="true">
        <div class="auth-preview__row"><span>Así se ve tu mes</span><strong><?= Ui::money(680000, 'in') ?></strong></div>
        <?= Ui::splitBar([['label' => 'Ingresos', 'value' => 1800000], ['label' => 'Gastos', 'value' => 1120000]], 'Ingresos frente a gastos') ?>
        <div class="auth-preview__budget"><span>Presupuesto de alimentación</span><span>82 %</span></div>
        <?= Ui::progress(82, 'Presupuesto de alimentación', 'warn') ?>
    </aside>
</section>
