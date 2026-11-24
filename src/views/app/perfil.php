<?php

declare(strict_types=1);

use App\Domain\Usuario;
use App\Support\Dinero;
use App\View\Ui;

/**
 * Perfil (RF-03, RNF-20).
 *
 * @var Usuario $usuario
 * @var array{nombre: string, moneda: string} $old Valores del formulario de datos.
 * @var array<string, string> $errors Error por campo.
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$monedas = [];
foreach (Dinero::MONEDAS as $codigo => $simbolo) {
    $monedas[$codigo] = $codigo . ' (' . $simbolo . ')';
}
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
