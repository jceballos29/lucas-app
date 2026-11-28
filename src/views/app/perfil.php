<?php

declare(strict_types=1);

use App\Domain\Usuario;
use App\Support\Dinero;
use App\Support\DispositivoSesion;
use App\View\Fecha;
use App\View\Html;
use App\View\Ui;

/**
 * Perfil (RF-03, RNF-20, Fase 17: panel de sesiones activas).
 *
 * @var Usuario $usuario
 * @var array{nombre: string, moneda: string} $old Valores del formulario de datos.
 * @var array<string, string> $errors Error por campo.
 * @var list<array{id_sesion: string, ip: string, user_agent: string, creado_en: string, ultimo_uso: string}>|null $sesiones
 * @var string|null $sesionActualHash
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$sesiones = $sesiones ?? [];
$sesionActualHash = $sesionActualHash ?? '';
$monedas = [];
foreach (Dinero::MONEDAS as $codigo => $simbolo) {
    $monedas[$codigo] = $codigo . ' (' . $simbolo . ')';
}
$filasSesiones = array_map(static function (array $s) use ($sesionActualHash): array {
    $esActual = $sesionActualHash !== '' && hash_equals($sesionActualHash, $s['id_sesion']);
    $dispositivo = DispositivoSesion::describir($s['user_agent']);
    $badgeActual = $esActual ? ' ' . Ui::badge('Este dispositivo', 'brand', 'check') : '';

    return [
        'dispositivo' => new Html('<div><span class="cell-title">' . escape($dispositivo) . '</span>' . $badgeActual . '<span class="cell-sub">IP: ' . escape($s['ip']) . '</span></div>'),
        'inicio' => format_utc_datetime($s['creado_en']),
        'actividad' => format_utc_datetime($s['ultimo_uso']),
        'acciones' => $esActual
            ? Ui::badge('Sesión actual', 'neutral')
            : Ui::postForm('/perfil.php', ['accion' => 'cerrar_sesion', 'id_sesion' => $s['id_sesion']], Ui::button('Cerrar sesión', [
                'type' => 'submit',
                'variant' => 'secondary',
                'size' => 'sm',
                'attrs' => [
                    'data-confirm' => '¿Cerrar la sesión abierta en «' . $dispositivo . '» (IP ' . $s['ip'] . ')?',
                    'data-confirm-ok' => 'Cerrar sesión',
                ],
            ])),
    ];
}, $sesiones);
?>
<?= Ui::pageHead('Mi perfil', $usuario->correo) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<?= Ui::cardOpen(['title' => 'Mis datos', 'class' => 'form-card']) ?>
    <form action="/perfil.php" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="datos">
        <?= Ui::field(['id' => 'nombre', 'label' => 'Nombre', 'value' => $old['nombre'], 'autocomplete' => 'name', 'required' => true, 'error' => $errors['nombre'] ?? null, 'attrs' => ['maxlength' => '80']]) ?>
        <?= Ui::select(['id' => 'moneda', 'label' => 'Moneda', 'options' => $monedas, 'selected' => $old['moneda'], 'error' => $errors['moneda'] ?? null]) ?>
        <p class="muted">La moneda solo cambia el símbolo con el que se muestran tus montos: Lucas no convierte entre monedas.</p>
        <div class="form-actions"><?= Ui::button('Guardar datos', ['type' => 'submit']) ?></div>
    </form>
<?= Ui::cardClose() ?>

<?= Ui::cardOpen(['title' => 'Notificaciones por correo', 'subtitle' => 'Recibe avisos en ' . $usuario->correo, 'class' => 'form-card']) ?>
    <form action="/perfil.php" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="notificaciones">
        <?php if (!$usuario->correoVerificado): ?><p class="muted">Primero verifica tu correo (arriba): hasta entonces no se pueden activar estos avisos.</p><?php endif; ?>
        <label class="check-row">
            <input type="checkbox" name="notif_presupuestos" value="1"<?= $usuario->notifPresupuestos ? ' checked' : '' ?>>
            <span><strong>Alertas de presupuesto (80 % y 100 %)</strong><br><span class="muted">Incluye los gastos que registren otros miembros en tus espacios compartidos. Un correo por umbral, categoría y mes.</span></span>
        </label>
        <label class="check-row">
            <input type="checkbox" name="notif_resumen_mensual" value="1"<?= $usuario->notifResumenMensual ? ' checked' : '' ?>>
            <span><strong>Resumen financiero mensual</strong><br><span class="muted">El día 1 de cada mes: balance del mes anterior, categorías donde más gastaste y avance de tus metas.</span></span>
        </label>
        <div class="form-actions"><?= Ui::button('Guardar preferencias', ['type' => 'submit']) ?></div>
    </form>
    <?= Ui::postForm('/perfil.php', ['accion' => 'resumen_prueba'], Ui::button('Enviarme el resumen de prueba ahora', ['type' => 'submit', 'variant' => 'secondary'])) ?>
<?= Ui::cardClose() ?>

<?= Ui::cardOpen(['title' => 'Cambiar contraseña', 'class' => 'form-card']) ?>
    <form action="/perfil.php" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="clave">
        <?= Ui::field(['id' => 'clave_actual', 'label' => 'Contraseña actual', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'error' => $errors['clave_actual'] ?? null]) ?>
        <?= Ui::field(['id' => 'clave_nueva', 'label' => 'Contraseña nueva', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'hint' => 'Mínimo 8 caracteres.', 'error' => $errors['clave_nueva'] ?? null, 'attrs' => ['minlength' => '8']]) ?>
        <?= Ui::field(['id' => 'clave_nueva2', 'label' => 'Repite la contraseña nueva', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['clave_nueva2'] ?? null]) ?>
        <div class="form-actions"><?= Ui::button('Cambiar contraseña', ['type' => 'submit']) ?></div>
    </form>
<?= Ui::cardClose() ?>

<?= Ui::cardOpen([
    'title' => 'Sesiones activas y dispositivos',
    'subtitle' => 'Revisa desde dónde has iniciado sesión en Lucas y cierra cualquier dispositivo que ya no uses',
    'class' => 'form-card',
    'action' => count($sesiones) > 1
        ? Ui::postForm('/perfil.php', ['accion' => 'cerrar_otras_sesiones'], Ui::button('Cerrar todas las demás', [
            'type' => 'submit',
            'variant' => 'secondary',
            'size' => 'sm',
            'attrs' => [
                'data-confirm' => '¿Cerrar todas las demás sesiones abiertas de tu cuenta excepto la de este dispositivo?',
                'data-confirm-ok' => 'Cerrar las demás',
            ],
        ]))
        : null,
]) ?>
    <?php if ($filasSesiones === []): ?>
        <p class="muted">Solo tienes activa la sesión de este dispositivo.</p>
    <?php else: ?>
        <?= Ui::table([
            'caption' => 'Sesiones activas de tu cuenta',
            'columns' => [
                ['key' => 'dispositivo', 'label' => 'Dispositivo e IP'],
                ['key' => 'inicio', 'label' => 'Inicio de sesión'],
                ['key' => 'actividad', 'label' => 'Última actividad'],
                ['key' => 'acciones', 'label' => 'Acciones', 'kind' => 'actions'],
            ],
            'rows' => $filasSesiones,
        ]) ?>
    <?php endif; ?>
<?= Ui::cardClose() ?>

<?= Ui::cardOpen(['title' => 'Eliminar mi cuenta', 'class' => 'form-card danger-zone']) ?>
    <?php if ($usuario->esAdmin()): ?>
        <p>Eres administrador: no puedes eliminar tu propia cuenta. Pídele a otro administrador que te quite el rol y después podrás hacerlo.</p>
    <?php else: ?>
        <p>Se eliminarán <strong>para siempre</strong> tu cuenta y todos tus datos: cuentas, movimientos, categorías propias, presupuestos y metas de ahorro con sus aportes. No se puede deshacer.</p>
        <form action="/perfil.php" method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="eliminar">
            <?= Ui::field(['id' => 'eliminar_clave', 'label' => 'Tu contraseña, para confirmar', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'error' => $errors['eliminar_clave'] ?? null]) ?>
            <div class="form-actions">
                <?= Ui::button('Eliminar mi cuenta y mis datos', ['type' => 'submit', 'variant' => 'secondary', 'attrs' => ['data-confirm' => '¿Eliminar tu cuenta y TODOS tus datos? Esta acción no se puede deshacer.', 'data-confirm-ok' => 'Eliminar todo']]) ?>
            </div>
        </form>
    <?php endif; ?>
<?= Ui::cardClose() ?>
