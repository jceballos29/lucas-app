<?php

declare(strict_types=1);

use App\View\Ui;
use App\View\View;

/**
 * Registro público de usuarios. Siempre crea usuarios con rol «usuario»: el formulario NO tiene campo de rol (RF-25).
 *
 * @var array{nombre?: string, correo?: string} $old Valores digitados (las contraseñas nunca se devuelven).
 * @var array<string, string> $errors Error por campo (nombre, correo, clave, clave2).
 * @var string|null $error Error general (demasiados intentos, BD caída...).
 */
$old = $old ?? [];
$errors = $errors ?? [];
$error = $error ?? null;
?>
<div class="auth">
    <?php View::render('auth/hero') ?>
    <section class="auth-form">
        <div class="auth-box">
            <?= Ui::logo('/login.php') ?>
            <h1>Crear cuenta</h1>
            <p class="muted">Gratis y en menos de un minuto</p>
            <?php if ($error !== null): ?><?= Ui::notice($error, 'error') ?><?php endif; ?>
            <form action="/registro.php" method="post" novalidate>
                <?= csrf_field() ?>
                <?= Ui::field(['id' => 'nombre', 'label' => 'Nombre completo', 'value' => $old['nombre'] ?? '', 'autocomplete' => 'name', 'required' => true, 'error' => $errors['nombre'] ?? null, 'attrs' => ['maxlength' => '80']]) ?>
                <?= Ui::field(['id' => 'correo', 'label' => 'Correo electrónico', 'type' => 'email', 'value' => $old['correo'] ?? '', 'autocomplete' => 'email', 'placeholder' => 'tucorreo@ejemplo.com', 'required' => true, 'error' => $errors['correo'] ?? null, 'attrs' => ['maxlength' => '120']]) ?>
                <?= Ui::field(['id' => 'clave', 'label' => 'Contraseña', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'hint' => 'Mínimo 10 caracteres.', 'error' => $errors['clave'] ?? null, 'attrs' => ['minlength' => '10']]) ?>
                <?= Ui::field(['id' => 'clave2', 'label' => 'Repite la contraseña', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['clave2'] ?? null]) ?>
                <?= Ui::button('Crear cuenta', ['type' => 'submit', 'block' => true]) ?>
            </form>
            <p class="auth-links">¿Ya tienes cuenta? <a href="/login.php">Inicia sesión</a></p>
        </div>
    </section>
</div>
