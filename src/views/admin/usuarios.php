<?php

declare(strict_types=1);

use App\View\Html;
use App\View\Ui;

/** Gestión de usuarios (maqueta con datos de ejemplo). Activar/desactivar y cambiar rol son POST con CSRF. */
$users = [
    ['id' => 1, 'nombre' => 'Administrador', 'correo' => 'admin@lucas.local', 'rol' => 'admin', 'activo' => true, 'fecha' => '01/11/2026', 'yo' => true],
    ['id' => 2, 'nombre' => 'Juan Ceballos', 'correo' => 'juan@correo.com', 'rol' => 'usuario', 'activo' => true, 'fecha' => '01/11/2026', 'yo' => false],
    ['id' => 3, 'nombre' => 'Laura Gómez', 'correo' => 'laura@correo.com', 'rol' => 'usuario', 'activo' => true, 'fecha' => '04/11/2026', 'yo' => false],
    ['id' => 4, 'nombre' => 'Marta Ríos', 'correo' => 'marta@correo.com', 'rol' => 'usuario', 'activo' => false, 'fecha' => '02/11/2026', 'yo' => false],
    ['id' => 5, 'nombre' => 'Camilo Pérez', 'correo' => 'camilo@correo.com', 'rol' => 'admin', 'activo' => true, 'fecha' => '28/10/2026', 'yo' => false],
];

/** Botón de una acción de administración: pide confirmación antes de enviar el POST. */
$action = static fn (array $u, string $accion, string $label, string $confirm): Html => Ui::postForm('/admin/usuarios.php', ['id' => $u['id'], 'accion' => $accion], Ui::button($label, [
    'type' => 'submit',
    'variant' => 'secondary',
    'size' => 'sm',
    'attrs' => ['data-confirm' => $confirm],
]));

$rows = array_map(static function (array $u) use ($action): array {
    $isAdmin = $u['rol'] === 'admin';

    return [
        'nombre' => new Html('<div class="cell-person">' . Ui::avatar($u['nombre']) . '<span class="cell-title">' . escape($u['nombre']) . '</span>' . ($u['yo'] ? Ui::badge('tú', 'outline') : '') . '</div>'),
        'correo' => $u['correo'],
        'rol' => Ui::badge($isAdmin ? 'Administrador' : 'Usuario', $isAdmin ? 'dark' : 'neutral'),
        'estado' => Ui::badge($u['activo'] ? 'Activo' : 'Inactivo', $u['activo'] ? 'brand' : 'outline'),
        'fecha' => $u['fecha'],
        'acciones' => $u['yo']
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
}, $users);
?>
<?= Ui::pageHead('Usuarios', 'Cuentas registradas en la aplicación') ?>

<form class="search" action="/admin/usuarios.php" method="get" role="search">
    <label class="sr-only" for="q">Buscar por nombre o correo</label>
    <div class="search__field">
        <?= Ui::icon('search') ?>
        <input class="input" type="search" id="q" name="q" placeholder="Buscar por nombre o correo">
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

<div class="toolbar">
    <?= Ui::pagination(1, 3, '#') ?>
    <span class="muted">5 de 128 usuarios</span>
</div>
