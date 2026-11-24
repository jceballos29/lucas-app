<?php

declare(strict_types=1);

use App\View\Ui;
use App\View\View;

/**
 * Elegir una contraseña nueva con el enlace del correo (RF-04).
 *
 * @var string $token El token del enlace (se reenvía en un campo oculto).
 * @var bool $valido ¿Sirve el enlace (existe, sin usar y sin caducar)?
 * @var array<string, string> $errors Error por campo (clave, clave2).
 */
?>
<div class="auth">
    <?php View::render('auth/hero') ?>
    <section class="auth-form">
        <div class="auth-box">
            <?= Ui::logo('/login.php') ?>
            <h1>Nueva contraseña</h1>
            <?php if (!$valido): ?>
                <?= Ui::notice('Este enlace no es válido o ya caducó. Los enlaces valen 30 minutos y solo se pueden usar una vez.', 'error') ?>
                <p><?= Ui::button('Pedir un enlace nuevo', ['href' => '/recuperar.php', 'block' => true]) ?></p>
            <?php else: ?>
                <p class="muted">Elige una contraseña de al menos 8 caracteres</p>
                <form action="/restablecer.php" method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= escape($token) ?>">
                    <?= Ui::field(['id' => 'clave', 'label' => 'Contraseña nueva', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'hint' => 'Mínimo 8 caracteres.', 'error' => $errors['clave'] ?? null, 'attrs' => ['minlength' => '8']]) ?>
                    <?= Ui::field(['id' => 'clave2', 'label' => 'Repite la contraseña', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['clave2'] ?? null]) ?>
                    <?= Ui::button('Cambiar contraseña', ['type' => 'submit', 'block' => true]) ?>
                </form>
            <?php endif; ?>
            <p class="auth-links"><a href="/login.php">Volver a iniciar sesión</a></p>
        </div>
    </section>
</div>
