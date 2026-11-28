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
 * @var string|null $dosPasosSecreto Secreto de la activación en curso (aún sin confirmar).
 * @var list<string>|null $codigosNuevos Códigos de recuperación recién generados (se muestran una sola vez).
 * @var int $codigosQuedan Códigos de recuperación sin usar.
 * @var bool $adminObligatorio ¿Es administrador y por tanto no puede desactivar el 2FA?
 */
$dosPasosSecreto = $dosPasosSecreto ?? null;
$codigosNuevos = $codigosNuevos ?? null;
$codigosQuedan = $codigosQuedan ?? 0;
$adminObligatorio = $adminObligatorio ?? false;
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

<?= Ui::cardOpen(['title' => 'Correo electrónico', 'subtitle' => $usuario->correo, 'class' => 'form-card', 'id' => 'correo']) ?>
    <?php if ($usuario->correoVerificado): ?>
        <?= Ui::notice('Tu correo está verificado: puedes enviar y aceptar invitaciones a espacios compartidos y recibir avisos por correo.', 'ok') ?>
    <?php else: ?>
        <p>Aún no confirmaste que este correo es tuyo. Abre el enlace que te enviamos al registrarte; si no lo encuentras (mira también el spam), pide otro.</p>
        <?= Ui::postForm('/perfil.php', ['accion' => 'reenviar_verificacion'], Ui::button('Reenviar enlace de verificación', ['type' => 'submit'])) ?>
    <?php endif; ?>
<?= Ui::cardClose() ?>

<?= Ui::cardOpen(['title' => 'Verificación en dos pasos', 'subtitle' => 'Un código de tu teléfono además de la contraseña', 'class' => 'form-card', 'id' => 'dos-pasos']) ?>
    <?php if ($codigosNuevos !== null): ?>
        <?= Ui::notice('Guarda estos códigos de recuperación AHORA: no se volverán a mostrar. Cada uno sirve una sola vez si pierdes el teléfono.', 'warn') ?>
        <ul class="recovery-codes" aria-label="Códigos de recuperación">
            <?php foreach ($codigosNuevos as $codigo): ?><li><code><?= escape($codigo) ?></code></li><?php endforeach; ?>
        </ul>
        <p class="muted">Guárdalos en un gestor de contraseñas o impresos, lejos del teléfono. Los anteriores ya no valen.</p>
    <?php endif; ?>

    <?php if ($usuario->dosPasos): ?>
        <?php if ($codigosNuevos === null): ?><?= Ui::notice('La verificación en dos pasos está activa: al iniciar sesión se te pedirá el código de tu app de autenticación.', 'ok') ?><?php endif; ?>
        <p class="<?= $codigosQuedan <= 2 ? '' : 'muted' ?>">Te quedan <strong><?= (int) $codigosQuedan ?></strong> códigos de recuperación sin usar.<?= $codigosQuedan <= 2 ? ' Genera códigos nuevos.' : '' ?></p>
        <form action="/perfil.php#dos-pasos" method="post" novalidate>
            <?= csrf_field() ?>
            <?= Ui::field(['id' => 'dp_clave', 'label' => 'Tu contraseña', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'hint' => 'Para generar códigos nuevos o desactivar, confirma tu contraseña y un código actual.', 'error' => $errors['dp_clave'] ?? null]) ?>
            <?= Ui::field(['id' => 'dp_codigo2', 'name' => 'codigo', 'label' => 'Código de tu app (o de recuperación)', 'autocomplete' => 'one-time-code', 'required' => true, 'error' => $errors['dp_codigo2'] ?? null, 'attrs' => ['spellcheck' => 'false', 'autocapitalize' => 'characters']]) ?>
            <div class="form-actions">
                <button class="btn btn--secondary" type="submit" name="accion" value="dos_pasos_regenerar">Generar códigos de recuperación nuevos</button>
                <?php if (!$adminObligatorio): ?><button class="btn btn--danger" type="submit" name="accion" value="dos_pasos_desactivar">Desactivar</button><?php endif; ?>
            </div>
            <?php if ($adminObligatorio): ?><p class="muted">Como administrador, no puedes desactivar la verificación en dos pasos.</p><?php endif; ?>
        </form>
    <?php elseif ($dosPasosSecreto !== null): ?>
        <ol class="steps">
            <li>Instala una app de autenticación (Google Authenticator, Aegis, 2FAS, Authy, Microsoft Authenticator…).</li>
            <li>Añade una cuenta <strong>introduciendo esta clave</strong> (tipo «basada en tiempo»), o abre el enlace en el teléfono:<br>
                <code class="secret-key" id="dp-secreto"><?= escape(\App\Auth\Totp::agrupar($dosPasosSecreto)) ?></code><br>
                <a class="link" href="<?= escape(\App\Auth\Totp::uri($dosPasosSecreto, $usuario->correo)) ?>">Abrir en mi app de autenticación</a></li>
            <li>Escribe aquí el código de 6 dígitos que muestra la app para confirmar:</li>
        </ol>
        <form action="/perfil.php#dos-pasos" method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="dos_pasos_activar">
            <?= Ui::field(['id' => 'dp_codigo', 'name' => 'codigo', 'label' => 'Código de 6 dígitos', 'autocomplete' => 'one-time-code', 'required' => true, 'error' => $errors['dp_codigo'] ?? null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => '7', 'spellcheck' => 'false']]) ?>
            <div class="form-actions"><?= Ui::button('Activar verificación en dos pasos', ['type' => 'submit']) ?></div>
        </form>
        <?= Ui::postForm('/perfil.php#dos-pasos', ['accion' => 'dos_pasos_cancelar'], Ui::button('Cancelar', ['type' => 'submit', 'variant' => 'ghost'])) ?>
    <?php else: ?>
        <p>Protege tu cuenta aunque alguien conozca tu contraseña: al iniciar sesión también se pide un código de 6 dígitos que genera una app en tu teléfono. Recomendado, y <strong>obligatorio para administradores</strong>.</p>
        <?= Ui::postForm('/perfil.php#dos-pasos', ['accion' => 'dos_pasos_iniciar'], Ui::button('Configurar verificación en dos pasos', ['type' => 'submit'])) ?>
    <?php endif; ?>
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
