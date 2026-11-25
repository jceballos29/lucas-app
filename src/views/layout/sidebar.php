<?php

declare(strict_types=1);

use App\View\Ui;

/**
 * Cabecera móvil + menú lateral. Variables:
 *
 * @var list<array{href: string, label: string, key: string, icon: string}> $items Entradas del menú (`icon` = nombre en Ui::icon()).
 * @var string $nav Clave de la entrada activa.
 * @var array{id: int, name: string, email: string}|null $currentUser
 * @var string $home Destino del logo.
 * @var string|null $badge Rótulo del grupo del menú (p. ej. «Administración»); si es null, «Menú».
 */
$name = $currentUser['name'] ?? 'Invitado';
$email = $currentUser['email'] ?? '';
$espacioNombre = is_array($currentUser) ? ($currentUser['espacio_nombre'] ?? null) : null;
$espacioRol = is_array($currentUser) ? ($currentUser['espacio_rol'] ?? null) : null;
?>
<header class="app-header">
    <?= Ui::logo($home, 'dark') ?>
    <button class="menu-btn" type="button" data-menu aria-label="Abrir menú" aria-expanded="false" aria-controls="sidebar"><?= Ui::icon('menu') ?></button>
</header>
<div class="overlay"></div>
<aside class="sidebar" id="sidebar" aria-label="Menú principal">
    <div class="sidebar__brand"><?= Ui::logo($home, 'dark') ?></div>
    <?php if (is_string($espacioNombre) && $espacioNombre !== ''): ?>
        <div class="sidebar__space" role="status" aria-label="Espacio compartido activo">
            <div class="sidebar__space-info">
                <span class="sidebar__space-label">Espacio compartido<?= $espacioRol === 'lector' ? ' · Solo lectura' : '' ?></span>
                <strong class="sidebar__space-name"><?= escape($espacioNombre) ?></strong>
            </div>
            <a class="sidebar__space-link" href="/espacios.php">Cambiar</a>
        </div>
    <?php endif; ?>
    <p class="sidebar__group"><?= escape($badge ?? 'Menú') ?></p>
    <nav class="nav">
        <?php foreach ($items as $item): ?>
            <a href="<?= escape($item['href']) ?>"<?= $item['key'] === $nav ? ' aria-current="page"' : '' ?>><?= Ui::icon($item['icon']) ?><?= escape($item['label']) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar__user">
        <?= Ui::avatar($name) ?>
        <div class="sidebar__who"><strong><?= escape($name) ?></strong><span><?= escape($email) ?></span></div>
        <form method="post" action="/logout.php" class="inline"><?= csrf_field() ?><?= Ui::iconButton('logout', 'Cerrar sesión', ['type' => 'submit']) ?></form>
    </div>
</aside>
<main class="main" id="contenido" tabindex="-1">
    <div class="main__inner">
