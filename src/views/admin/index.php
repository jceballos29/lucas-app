<?php

declare(strict_types=1);

use App\View\Fecha;
use App\View\Ui;

/**
 * Dashboard de administración. Solo agregados: el administrador no ve movimientos ni saldos de nadie (RNF-21).
 *
 * @var array{usuarios: int, activos: int, nuevos_mes: int, admins_activos: int, cuentas: int, movimientos: int} $totals
 * @var list<array{label: string, value: int}> $registrations Registros por mes (últimos 12).
 * @var list<array{id: int, nombre: string, correo: string, activo: bool, creado_en: string}> $latest
 * @var string $month Mes actual en texto («noviembre 2026»).
 */
$n = static fn (int $value): string => number_format($value, 0, ',', '.');
?>
<?= Ui::pageHead('Dashboard', 'Resumen general de la aplicación · ' . $month, Ui::button('Gestionar usuarios', ['href' => '/admin/usuarios.php', 'icon' => 'users'])) ?>

<div class="dashboard">
    <section class="kpis" aria-label="Métricas globales">
        <?= Ui::stat('Usuarios registrados', $n($totals['usuarios']), ['icon' => 'users']) ?>
        <?= Ui::stat('Usuarios activos', $n($totals['activos']), ['icon' => 'check-circle']) ?>
        <?= Ui::stat('Nuevos este mes', '+' . $n($totals['nuevos_mes']), ['icon' => 'trend-up', 'tone' => 'brand']) ?>
        <?= Ui::stat('Movimientos registrados', $n($totals['movimientos']), ['icon' => 'transfer']) ?>
    </section>

    <div class="grid-2">
        <?= Ui::cardOpen(['title' => 'Registros por mes', 'subtitle' => 'Últimos 12 meses']) ?>
            <?= Ui::bars($registrations, 'Gráfico de barras de usuarios registrados en los últimos 12 meses') ?>
        <?= Ui::cardClose() ?>

        <?= Ui::cardOpen(['title' => 'Últimos registros', 'action' => Ui::button('Ver todos', ['href' => '/admin/usuarios.php', 'variant' => 'ghost', 'size' => 'sm'])]) ?>
            <?php foreach ($latest as $u): ?>
                <?= Ui::listItem($u['nombre'], $u['correo'] . ' · ' . Fecha::dia($u['creado_en']), Ui::badge($u['activo'] ? 'Activo' : 'Inactivo', $u['activo'] ? 'brand' : 'outline'), $u['nombre']) ?>
            <?php endforeach; ?>
        <?= Ui::cardClose() ?>
    </div>

    <?= Ui::cardOpen(['title' => 'Estado de la aplicación', 'class' => 'stat-list']) ?>
        <?= Ui::listItem('Cuentas creadas', 'Entre todos los usuarios', $n($totals['cuentas'])) ?>
        <?= Ui::listItem('Administradores activos', 'Debe haber al menos uno', $n($totals['admins_activos'])) ?>
        <?= Ui::listItem('Privacidad', 'Este panel solo muestra totales: los movimientos y saldos de cada persona no son visibles para el administrador.', Ui::badge('RNF-21', 'outline', 'lock')) ?>
    <?= Ui::cardClose() ?>
</div>
