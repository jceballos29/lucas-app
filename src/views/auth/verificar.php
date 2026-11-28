<?php

declare(strict_types=1);

use App\View\Ui;
use App\View\View;

/**
 * Confirmar el correo con el enlace recibido (A1).
 *
 * @var string $token El token del enlace (se reenvía en un campo oculto).
 * @var bool $valido ¿Sirve el enlace (existe, sin usar y sin caducar)?
 */
?>
<div class="auth">
    <?php View::render('auth/hero') ?>
    <section class="auth-form">
        <div class="auth-box">
            <?= Ui::logo('/login.php') ?>
            <h1>Confirmar correo</h1>
            <?php if (!$valido): ?>
                <?= Ui::notice('Este enlace no es válido o ya caducó. Los enlaces valen 48 horas y solo se pueden usar una vez. Inicia sesión y pide otro en Mi perfil.', 'error') ?>
                <p><?= Ui::button('Iniciar sesión', ['href' => '/login.php', 'block' => true]) ?></p>
            <?php else: ?>
                <p class="muted">Confirma que este correo es tuyo para activar las invitaciones y los avisos de Lucas.</p>
                <form action="/verificar.php" method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= escape($token) ?>">
                    <?= Ui::button('Confirmar mi correo', ['type' => 'submit', 'block' => true]) ?>
                </form>
                <p class="auth-links"><a href="/login.php">Ahora no</a></p>
            <?php endif; ?>
        </div>
    </section>
</div>
