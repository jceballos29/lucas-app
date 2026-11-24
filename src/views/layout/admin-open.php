<?php

declare(strict_types=1);

use App\View\View;

/** Páginas del administrador. @var string $nav @var array|null $currentUser */
$items = [
    ['href' => '/admin/index.php', 'label' => 'Dashboard', 'key' => 'dashboard', 'icon' => 'dashboard'],
    ['href' => '/admin/usuarios.php', 'label' => 'Usuarios', 'key' => 'usuarios', 'icon' => 'users'],
    ['href' => '/admin/categorias.php', 'label' => 'Categorías', 'key' => 'categorias', 'icon' => 'target'],
    ['href' => '/perfil.php', 'label' => 'Mi perfil', 'key' => 'perfil', 'icon' => 'shield'],
];
View::render('layout/sidebar', ['items' => $items, 'nav' => $nav ?? '', 'currentUser' => $currentUser ?? null, 'home' => '/admin/index.php', 'badge' => 'Administración']);
