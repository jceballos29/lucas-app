<?php

declare(strict_types=1);

use App\View\Ui;
use App\View\View;

/**
 * Inicio de sesión.
 *
 * @var string $email Correo digitado (se conserva si hay error).
 * @var string|null $error Mensaje general (credenciales inválidas, sesión caducada...).
 * @var string|null $notice Aviso informativo (p. ej. «Tu cuenta fue creada»).
 */
$email = $email ?? '';
$error = $error ?? null;
$notice = $notice ?? null;
?>
<div class="auth">
    <?php View::render('auth/hero') ?>
    <section class="auth-form">
        <div class="auth-box">
            <?= Ui::logo('/login.php') ?>
            <h1>Iniciar sesión</h1>
            <p class="muted">Ingresa con tu correo y contraseña</p>
            <?php if ($notice !== null): ?><?= Ui::notice($notice, 'ok') ?><?php endif; ?>
            <?php if ($error !== null): ?><?= Ui::notice($error, 'error') ?><?php endif; ?>
            <form action="/login.php" method="post" novalidate>
                <?= csrf_field() ?>
                <?= Ui::field(['id' => 'correo', 'label' => 'Correo electrónico', 'type' => 'email', 'value' => $email, 'autocomplete' => 'email', 'placeholder' => 'tucorreo@ejemplo.com', 'required' => true]) ?>
                <?= Ui::field(['id' => 'clave', 'label' => 'Contraseña', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true]) ?>
                <a class="link auth-forgot" href="/recuperar.php">¿Olvidaste tu contraseña?</a>
                <?= Ui::button('Iniciar sesión', ['type' => 'submit', 'block' => true]) ?>
            </form>
            <p class="auth-links">¿No tienes cuenta? <a href="/registro.php">Regístrate</a></p>
        </div>
    </section>
</div>
