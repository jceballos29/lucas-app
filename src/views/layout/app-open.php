<?php

declare(strict_types=1);

use App\View\View;

/** Páginas del usuario: menú de finanzas personales. @var string $nav @var array|null $currentUser */
$items = [
    ['href' => '/panel.php', 'label' => 'Panel', 'key' => 'panel', 'icon' => 'dashboard'],
    ['href' => '/movimientos.php', 'label' => 'Movimientos', 'key' => 'movimientos', 'icon' => 'transfer'],
    ['href' => '/cuentas.php', 'label' => 'Cuentas', 'key' => 'cuentas', 'icon' => 'wallet'],
    ['href' => '/presupuestos.php', 'label' => 'Presupuestos', 'key' => 'presupuestos', 'icon' => 'pie'],
    ['href' => '/metas.php', 'label' => 'Metas de ahorro', 'key' => 'metas', 'icon' => 'flag'],
    ['href' => '/reportes.php', 'label' => 'Reportes', 'key' => 'reportes', 'icon' => 'chart'],
];
View::render('layout/sidebar', ['items' => $items, 'nav' => $nav ?? '', 'currentUser' => $currentUser ?? null, 'home' => '/panel.php', 'badge' => null]);
