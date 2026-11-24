<?php

declare(strict_types=1);

use App\View\Ui;

/**
 * Dashboard de administración (maqueta con datos de ejemplo). Solo agregados: el administrador no ve movimientos ni
 * saldos de nadie (RNF-21).
 */
$registrations = [
    ['label' => 'Dic', 'value' => 30], ['label' => 'Ene', 'value' => 38], ['label' => 'Feb', 'value' => 35],
    ['label' => 'Mar', 'value' => 48], ['label' => 'Abr', 'value' => 52], ['label' => 'May', 'value' => 44],
    ['label' => 'Jun', 'value' => 60], ['label' => 'Jul', 'value' => 72], ['label' => 'Ago', 'value' => 58],
    ['label' => 'Sep', 'value' => 80], ['label' => 'Oct', 'value' => 66], ['label' => 'Nov', 'value' => 46],
];
$latest = [
    ['name' => 'Laura Gómez', 'email' => 'laura@correo.com', 'date' => '04 nov', 'active' => true],
    ['name' => 'Andrés Rojas', 'email' => 'andres@correo.com', 'date' => '03 nov', 'active' => true],
    ['name' => 'Marta Ríos', 'email' => 'marta@correo.com', 'date' => '02 nov', 'active' => false],
    ['name' => 'Camilo Pérez', 'email' => 'camilo@correo.com', 'date' => '31 oct', 'active' => true],
];
?>
<?= Ui::pageHead('Dashboard', 'Resumen general de la aplicación · noviembre 2026', Ui::button('Gestionar usuarios', ['href' => '/admin/usuarios.php', 'icon' => 'users'])) ?>

<div class="dashboard">
    <section class="kpis" aria-label="Métricas globales">
        <?= Ui::stat('Usuarios registrados', '128', ['icon' => 'users']) ?>
        <?= Ui::stat('Usuarios activos', '121', ['icon' => 'check-circle']) ?>
        <?= Ui::stat('Nuevos este mes', '+14', ['icon' => 'trend-up', 'tone' => 'brand']) ?>
        <?= Ui::stat('Movimientos registrados', '9.842', ['icon' => 'transfer']) ?>
    </section>

    <div class="grid-2">
        <?= Ui::cardOpen(['title' => 'Registros por mes', 'subtitle' => 'Últimos 12 meses']) ?>
            <?= Ui::bars($registrations, 'Gráfico de barras de usuarios registrados en los últimos 12 meses') ?>
        <?= Ui::cardClose() ?>

        <?= Ui::cardOpen(['title' => 'Últimos registros', 'action' => Ui::button('Ver todos', ['href' => '/admin/usuarios.php', 'variant' => 'ghost', 'size' => 'sm'])]) ?>
            <?php foreach ($latest as $u): ?>
                <?= Ui::listItem($u['name'], $u['email'] . ' · ' . $u['date'], Ui::badge($u['active'] ? 'Activo' : 'Inactivo', $u['active'] ? 'brand' : 'outline'), $u['name']) ?>
            <?php endforeach; ?>
        <?= Ui::cardClose() ?>
    </div>

    <?= Ui::cardOpen(['title' => 'Estado de la aplicación', 'class' => 'stat-list']) ?>
        <?= Ui::listItem('Cuentas creadas', 'Entre todos los usuarios', '312') ?>
        <?= Ui::listItem('Administradores activos', 'Debe haber al menos uno', '2') ?>
        <?= Ui::listItem('Privacidad', 'Este panel solo muestra totales: los movimientos y saldos de cada persona no son visibles para el administrador.', Ui::badge('RNF-21', 'outline', 'lock')) ?>
    <?= Ui::cardClose() ?>
</div>
