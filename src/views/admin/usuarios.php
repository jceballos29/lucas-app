<?php

declare(strict_types=1);

use App\View\Fecha;
use App\View\Html;
use App\View\Ui;

/**
 * Gestión de usuarios (RF-30 a RF-33). Activar/desactivar y cambiar rol son POST con CSRF y confirmación.
 *
 * @var list<array{id: int, nombre: string, correo: string, rol: string, activo: bool, creado_en: string}> $rows
 * @var int $actorId Id del administrador conectado (no puede modificarse a sí mismo).
 * @var string $q Texto buscado.
 * @var int $page Página actual.
 * @var int $pages Total de páginas.
 * @var int $total Usuarios que coinciden con la búsqueda.
 * @var array{tone: string, message: string}|null $flash Resultado de la última acción.
 */
$flash = $flash ?? null;

/** Botón de una acción de administración: pide confirmación antes de enviar el POST. */
$action = static fn (array $u, string $accion, string $label, string $confirm): Html => Ui::postForm('/admin/usuarios.php', ['id' => $u['id'], 'accion' => $accion, 'q' => $q, 'pagina' => $page], Ui::button($label, [
    'type' => 'submit',
    'variant' => 'secondary',
    'size' => 'sm',
    'attrs' => ['data-confirm' => $confirm],
]));

$rows = array_map(static function (array $u) use ($action, $actorId): array {
    $isAdmin = $u['rol'] === 'admin';
    $yo = $u['id'] === $actorId;

    return [
        'nombre' => new Html('<div class="cell-person">' . Ui::avatar($u['nombre']) . '<span class="cell-title">' . escape($u['nombre']) . '</span>' . ($yo ? Ui::badge('tú', 'outline') : '') . '</div>'),
        'correo' => $u['correo'],
        'rol' => Ui::badge($isAdmin ? 'Administrador' : 'Usuario', $isAdmin ? 'dark' : 'neutral'),
        'estado' => Ui::badge($u['activo'] ? 'Activo' : 'Inactivo', $u['activo'] ? 'brand' : 'outline'),
        'fecha' => Fecha::numerica($u['creado_en']),
        'acciones' => $yo
            ? new Html('<span class="muted-note">No puedes modificar tu propia cuenta</span>')
            : Ui::actions([
                $action($u, $u['activo'] ? 'desactivar' : 'activar', $u['activo'] ? 'Desactivar' : 'Activar', $u['activo']
                    ? '¿Desactivar a ' . $u['nombre'] . '? No podrá iniciar sesión, pero sus datos se conservan.'
                    : '¿Activar de nuevo a ' . $u['nombre'] . '?'),
                $action($u, $isAdmin ? 'quitar_admin' : 'hacer_admin', $isAdmin ? 'Quitar admin' : 'Hacer admin', $isAdmin
                    ? '¿Quitar el rol de administrador a ' . $u['nombre'] . '?'
                    : '¿Dar el rol de administrador a ' . $u['nombre'] . '?'),
            ]),
    ];
}, $rows);

// El texto buscado va en cada enlace de página; el «%» se duplica porque el patrón pasa por sprintf().
$pattern = '?pagina=%d' . ($q !== '' ? str_replace('%', '%%', '&q=' . rawurlencode($q)) : '');
?>
<?= Ui::pageHead('Usuarios', 'Cuentas registradas en la aplicación') ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<form class="search" action="/admin/usuarios.php" method="get" role="search">
    <label class="sr-only" for="q">Buscar por nombre o correo</label>
    <div class="search__field">
        <?= Ui::icon('search') ?>
        <input class="input" type="search" id="q" name="q" placeholder="Buscar por nombre o correo" value="<?= escape($q) ?>" maxlength="80">
    </div>
    <?= Ui::button('Buscar', ['type' => 'submit', 'variant' => 'dark']) ?>
</form>

<?= Ui::table([
    'caption' => 'Usuarios',
    'columns' => [
        ['key' => 'nombre', 'label' => 'Nombre'],
        ['key' => 'correo', 'label' => 'Correo'],
        ['key' => 'rol', 'label' => 'Rol'],
        ['key' => 'estado', 'label' => 'Estado'],
        ['key' => 'fecha', 'label' => 'Registro'],
        ['key' => 'acciones', 'label' => 'Acciones', 'kind' => 'actions'],
    ],
    'rows' => $rows,
]) ?>
<?php if ($rows === []): ?><p class="muted">No hay usuarios que coincidan con la búsqueda.</p><?php endif; ?>

<div class="toolbar">
    <?= $pages > 1 ? Ui::pagination($page, $pages, $pattern) : '' ?>
    <span class="muted"><?= count($rows) ?> de <?= number_format($total, 0, ',', '.') ?> <?= $total === 1 ? 'usuario' : 'usuarios' ?></span>
</div>
