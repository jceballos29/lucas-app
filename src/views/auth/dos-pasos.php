<?php

declare(strict_types=1);

use App\View\Ui;
use App\View\View;

/**
 * Segundo paso del inicio de sesión (M7).
 *
 * @var string|null $error Mensaje general (código incorrecto, demasiados intentos...).
 */
$error = $error ?? null;
?>
<div class="auth">
    <?php View::render('auth/hero') ?>
    <section class="auth-form">
        <div class="auth-box">
            <?= Ui::logo('/login.php') ?>
            <h1>Verificación en dos pasos</h1>
            <p class="muted">Escribe el código de 6 dígitos de tu app de autenticación. Si perdiste el teléfono, usa un código de recuperación.</p>
            <?php if ($error !== null): ?><?= Ui::notice($error, 'error') ?><?php endif; ?>
            <form action="/login-2fa.php" method="post" novalidate>
                <?= csrf_field() ?>
                <?= Ui::field(['id' => 'codigo', 'label' => 'Código', 'type' => 'text', 'autocomplete' => 'one-time-code', 'placeholder' => '123456', 'required' => true, 'attrs' => ['autofocus' => true, 'inputmode' => 'text', 'autocapitalize' => 'characters', 'spellcheck' => 'false']]) ?>
                <?= Ui::button('Verificar', ['type' => 'submit', 'block' => true]) ?>
            </form>
            <form action="/login-2fa.php" method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="cancelar">
                <?= Ui::button('Cancelar y volver al inicio de sesión', ['type' => 'submit', 'variant' => 'ghost', 'block' => true]) ?>
            </form>
        </div>
    </section>
</div>
