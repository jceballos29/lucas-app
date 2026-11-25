<?php

declare(strict_types=1);

use App\Domain\Espacio;
use App\Support\Dinero;
use App\View\Html;
use App\View\Ui;

/**
 * Gestión de espacios compartidos / Cuenta Familiar (Fase 10).
 *
 * @var list<Espacio> $espacios
 * @var array<int, array{miembros: list<array{id_usuario: int, nombre: string, correo: string, rol: string, unido_en: string}>, invitaciones: list<array{id_invitacion: int, correo: string, rol: string, expira_en: string}>}> $detallesEspacios
 * @var list<array{id_invitacion: int, id_espacio: int, espacio_nombre: string, moneda: string, rol: string, expira_en: string}> $invitacionesRecibidas
 * @var int|null $espacioActivoId
 * @var array{nombre: string, moneda: string} $oldCrear
 * @var array<string, string> $errorsCrear
 * @var array{correo: string, rol: string} $oldInvitar
 * @var array<string, string> $errorsInvitar
 * @var array<string, string> $errorsEditar
 * @var array{tone: string, message: string}|null $flash
 * @var array{id: int, name: string, email: string, espacio_id?: int|null, espacio_nombre?: string|null, espacio_rol?: string|null} $currentUser
 */
$flash = $flash ?? null;
$opcionesMoneda = [];
foreach (array_keys(Dinero::MONEDAS) as $codigo) {
    $opcionesMoneda[$codigo] = $codigo . ' (' . Dinero::MONEDAS[$codigo] . ')';
}
?>
<?= Ui::pageHead(
    'Espacios y Cuenta Familiar',
    'Alterna entre tus finanzas personales y espacios compartidos con tu pareja, familia o socios',
    $espacioActivoId !== null
        ? Ui::postForm('/espacios.php', ['accion' => 'cambiar', 'id_espacio' => '0'], Ui::button('Volver a mi espacio personal', ['type' => 'submit', 'variant' => 'secondary']))
        : null
) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<div class="stack stack--lg">
    <?php if ($invitacionesRecibidas !== []): ?>
        <?= Ui::cardOpen(['title' => 'Invitaciones pendientes para ti', 'subtitle' => 'Te han invitado a colaborar en los siguientes espacios familiares']) ?>
            <?php foreach ($invitacionesRecibidas as $inv): ?>
                <?= Ui::listItem(
                    $inv['espacio_nombre'],
                    'Rol asignado: ' . (Espacio::ROLES[$inv['rol']] ?? $inv['rol']) . ' · Moneda: ' . $inv['moneda'],
                    Ui::postForm('/espacios.php', ['accion' => 'aceptar_invitacion', 'id_invitacion' => $inv['id_invitacion']], Ui::button('Aceptar y entrar', ['type' => 'submit', 'size' => 'sm', 'icon' => 'check']))
                ) ?>
            <?php endforeach; ?>
        <?= Ui::cardClose() ?>
    <?php endif; ?>

    <div class="grid-2">
        <?= Ui::cardOpen(['title' => 'Tus espacios financieros', 'subtitle' => 'Elige en cuál espacio quieres trabajar ahora']) ?>
            <?= Ui::listItem(
                new Html('<strong>Mi espacio personal</strong> ' . ($espacioActivoId === null ? Ui::badge('Activo ahora', 'brand') : '')),
                'Tus cuentas, movimientos, presupuestos y metas privadas',
                $espacioActivoId === null
                    ? Ui::badge('En uso', 'neutral')
                    : Ui::postForm('/espacios.php', ['accion' => 'cambiar', 'id_espacio' => '0'], Ui::button('Activar', ['type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']))
            ) ?>

            <?php foreach ($espacios as $e): ?>
                <?php $esActivo = $espacioActivoId === $e->id; ?>
                <?= Ui::listItem(
                    new Html('<strong>' . escape($e->nombre) . '</strong> ' . ($esActivo ? Ui::badge('Activo ahora', 'brand') : Ui::badge($e->rolTexto(), 'neutral'))),
                    $e->numMiembros . ($e->numMiembros === 1 ? ' miembro' : ' miembros') . ' · Moneda ' . $e->moneda . ' · Tu rol: ' . $e->rolTexto(),
                    $esActivo
                        ? Ui::badge('En uso', 'neutral')
                        : Ui::postForm('/espacios.php', ['accion' => 'cambiar', 'id_espacio' => $e->id], Ui::button('Activar', ['type' => 'submit', 'variant' => 'primary', 'size' => 'sm']))
                ) ?>
            <?php endforeach; ?>
        <?= Ui::cardClose() ?>

        <?= Ui::cardOpen(['title' => 'Crear nuevo espacio familiar', 'subtitle' => 'Ideal para gestionar gastos del hogar, cuentas conjuntas o proyectos en pareja']) ?>
            <form action="/espacios.php" method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="crear">
                <?= Ui::field(['id' => 'nombre-espacio', 'name' => 'nombre', 'label' => 'Nombre del espacio compartido', 'value' => $oldCrear['nombre'], 'placeholder' => 'Ej.: Hogar Familia Restrepo', 'required' => true, 'error' => $errorsCrear['nombre'] ?? null, 'attrs' => ['maxlength' => '80']]) ?>
                <?= Ui::select(['id' => 'moneda-espacio', 'name' => 'moneda', 'label' => 'Moneda del espacio', 'options' => $opcionesMoneda, 'selected' => $oldCrear['moneda'], 'error' => $errorsCrear['moneda'] ?? null]) ?>
                <div class="form-actions">
                    <?= Ui::button('Crear espacio familiar', ['type' => 'submit', 'icon' => 'plus']) ?>
                </div>
            </form>
        <?= Ui::cardClose() ?>
    </div>

    <?php foreach ($espacios as $e): ?>
        <?php
        $detalle = $detallesEspacios[$e->id] ?? ['miembros' => [], 'invitaciones' => []];
        $miembros = $detalle['miembros'];
        $invitaciones = $detalle['invitaciones'];
        ?>
        <?= Ui::cardOpen([
            'title' => $e->nombre . ' (' . $e->moneda . ')',
            'subtitle' => 'Miembros y permisos de este espacio compartido',
            'action' => $espacioActivoId === $e->id
                ? Ui::badge('Espacio activo', 'brand')
                : Ui::postForm('/espacios.php', ['accion' => 'cambiar', 'id_espacio' => $e->id], Ui::button('Cambiar a este espacio', ['type' => 'submit', 'variant' => 'secondary', 'size' => 'sm'])),
        ]) ?>
            <?php
            $filasMiembros = array_map(static function (array $m) use ($e, $currentUser): array {
                $esYo = $m['id_usuario'] === $currentUser['id'];
                $acciones = [];

                if ($e->esPropietario() && !$esYo) {
                    $otroRol = $m['rol'] === 'editor' ? 'lector' : 'editor';
                    $acciones[] = Ui::postForm('/espacios.php', ['accion' => 'cambiar_rol', 'id_espacio' => $e->id, 'id_usuario' => $m['id_usuario'], 'rol' => $otroRol], Ui::button('Pasar a ' . (Espacio::ROLES[$otroRol] ?? $otroRol), [
                        'type' => 'submit',
                        'variant' => 'ghost',
                        'size' => 'sm',
                    ]));
                    if ($m['rol'] !== 'propietario') {
                        $acciones[] = Ui::postForm('/espacios.php', ['accion' => 'cambiar_rol', 'id_espacio' => $e->id, 'id_usuario' => $m['id_usuario'], 'rol' => 'propietario'], Ui::button('Hacer propietario', [
                            'type' => 'submit',
                            'variant' => 'ghost',
                            'size' => 'sm',
                        ]));
                    }
                    $acciones[] = Ui::postForm('/espacios.php', ['accion' => 'retirar', 'id_espacio' => $e->id, 'id_usuario' => $m['id_usuario']], Ui::iconButton('trash', 'Retirar a ' . $m['nombre'], [
                        'type' => 'submit',
                        'tone' => 'danger',
                        'attrs' => [
                            'data-confirm' => '¿Retirar a «' . $m['nombre'] . '» del espacio compartido «' . $e->nombre . '»?',
                            'data-confirm-ok' => 'Retirar',
                        ],
                    ]));
                } elseif ($esYo) {
                    $acciones[] = Ui::postForm('/espacios.php', ['accion' => 'salir', 'id_espacio' => $e->id], Ui::button('Salir del espacio', [
                        'type' => 'submit',
                        'variant' => 'ghost',
                        'size' => 'sm',
                        'attrs' => [
                            'data-confirm' => '¿Salir del espacio compartido «' . $e->nombre . '»?',
                            'data-confirm-ok' => 'Salir',
                        ],
                    ]));
                }

                return [
                    'miembro' => new Html('<div class="cell-person">' . Ui::avatar($m['nombre']) . '<div><span class="cell-title">' . escape($m['nombre']) . ($esYo ? ' (tú)' : '') . '</span><span class="cell-sub">' . escape($m['correo']) . '</span></div></div>'),
                    'rol' => Ui::badge(Espacio::ROLES[$m['rol']] ?? $m['rol'], $m['rol'] === 'propietario' ? 'brand' : 'neutral'),
                    'acciones' => Ui::actions($acciones),
                ];
            }, $miembros);
            ?>

            <?= Ui::table([
                'caption' => 'Miembros de ' . $e->nombre,
                'columns' => [
                    ['key' => 'miembro', 'label' => 'Miembro'],
                    ['key' => 'rol', 'label' => 'Rol en el espacio'],
                    ['key' => 'acciones', 'label' => 'Acciones', 'kind' => 'actions'],
                ],
                'rows' => $filasMiembros,
            ]) ?>

            <?php if ($e->esPropietario()): ?>
                <div class="grid-2">
                    <div class="space-block">
                        <h3 class="space-block__title">Invitar a un miembro</h3>
                        <form action="/espacios.php" method="post" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="invitar">
                            <input type="hidden" name="id_espacio" value="<?= (int) $e->id ?>">
                            <?= Ui::field(['id' => 'correo-' . $e->id, 'name' => 'correo', 'label' => 'Correo electrónico de la persona', 'type' => 'email', 'value' => $oldInvitar['correo'], 'placeholder' => 'pareja@correo.com', 'required' => true, 'error' => $errorsInvitar['correo'] ?? null]) ?>
                            <?= Ui::select(['id' => 'rol-' . $e->id, 'name' => 'rol', 'label' => 'Permisos en el espacio', 'options' => Espacio::ROLES_INVITACION, 'selected' => $oldInvitar['rol'], 'error' => $errorsInvitar['rol'] ?? null]) ?>
                            <div class="form-actions">
                                <?= Ui::button('Enviar invitación', ['type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus']) ?>
                            </div>
                        </form>

                        <?php if ($invitaciones !== []): ?>
                            <h4 class="space-block__sub">Invitaciones enviadas pendientes</h4>
                            <?php foreach ($invitaciones as $inv): ?>
                                <?= Ui::listItem(
                                    $inv['correo'],
                                    'Rol: ' . (Espacio::ROLES[$inv['rol']] ?? $inv['rol']),
                                    Ui::postForm('/espacios.php', ['accion' => 'cancelar_invitacion', 'id_espacio' => $e->id, 'id_invitacion' => $inv['id_invitacion']], Ui::button('Cancelar', ['type' => 'submit', 'variant' => 'ghost', 'size' => 'sm']))
                                ) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="space-block">
                        <h3 class="space-block__title">Ajustes del espacio</h3>
                        <form action="/espacios.php" method="post" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="editar">
                            <input type="hidden" name="id_espacio" value="<?= (int) $e->id ?>">
                            <?= Ui::field(['id' => 'edit-nombre-' . $e->id, 'name' => 'nombre', 'label' => 'Nombre del espacio', 'value' => $e->nombre, 'required' => true, 'error' => $errorsEditar['nombre'] ?? null]) ?>
                            <?= Ui::select(['id' => 'edit-moneda-' . $e->id, 'name' => 'moneda', 'label' => 'Moneda', 'options' => $opcionesMoneda, 'selected' => $e->moneda]) ?>
                            <div class="form-actions">
                                <?= Ui::button('Guardar cambios', ['type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?>
                            </div>
                        </form>
                        <div class="space-danger">
                            <p class="space-danger__note">Si eliminas el espacio compartido se borrarán sus cuentas y movimientos comunes, pero tus finanzas personales no se tocarán.</p>
                            <?= Ui::postForm('/espacios.php', ['accion' => 'eliminar_espacio', 'id_espacio' => $e->id], Ui::button('Eliminar espacio compartido', [
                                'type' => 'submit',
                                'variant' => 'danger',
                                'size' => 'sm',
                                'icon' => 'trash',
                                'attrs' => [
                                    'data-confirm' => '¿Eliminar para siempre el espacio compartido «' . $e->nombre . '» y todas sus cuentas, movimientos, presupuestos y metas? Tus finanzas personales NO se tocarán.',
                                    'data-confirm-ok' => 'Eliminar espacio',
                                ],
                            ])) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?= Ui::cardClose() ?>
    <?php endforeach; ?>
</div>
