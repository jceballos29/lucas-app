<?php

declare(strict_types=1);

use App\View\View;

/** Páginas del usuario: menú de finanzas personales. @var string $nav @var array|null $currentUser */
$items = [
    ['href' => '/panel.php', 'label' => 'Panel', 'key' => 'panel', 'icon' => 'dashboard'],
    ['href' => '/movimientos.php', 'label' => 'Movimientos', 'key' => 'movimientos', 'icon' => 'transfer'],
    ['href' => '/recurrentes.php', 'label' => 'Gastos fijos', 'key' => 'recurrentes', 'icon' => 'book'],
    ['href' => '/transferencias.php', 'label' => 'Transferencias', 'key' => 'transferencias', 'icon' => 'bank'],
    ['href' => '/cuentas.php', 'label' => 'Cuentas', 'key' => 'cuentas', 'icon' => 'wallet'],
    ['href' => '/categorias.php', 'label' => 'Categorías', 'key' => 'categorias', 'icon' => 'target'],
    ['href' => '/presupuestos.php', 'label' => 'Presupuestos', 'key' => 'presupuestos', 'icon' => 'pie'],
    ['href' => '/metas.php', 'label' => 'Metas de ahorro', 'key' => 'metas', 'icon' => 'flag'],
    ['href' => '/reportes.php', 'label' => 'Reportes', 'key' => 'reportes', 'icon' => 'chart'],
    ['href' => '/espacios.php', 'label' => 'Espacios / Familia', 'key' => 'espacios', 'icon' => 'users'],
    ['href' => '/perfil.php', 'label' => 'Mi perfil', 'key' => 'perfil', 'icon' => 'shield'],
];
$espacioNombre = is_array($currentUser ?? null) ? ($currentUser['espacio_nombre'] ?? null) : null;
$rotulo = is_string($espacioNombre) && $espacioNombre !== '' ? 'Espacio · ' . $espacioNombre : null;
View::render('layout/sidebar', ['items' => $items, 'nav' => $nav ?? '', 'currentUser' => $currentUser ?? null, 'home' => '/panel.php', 'badge' => $rotulo]);
