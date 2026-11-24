<?php

declare(strict_types=1);

use App\View\Ui;
use App\View\View;

/** Recuperar contraseña (RF-04). @var string|null $notice Aviso tras enviar. */
$notice = $notice ?? null;
?>
<div class="auth">
    <?php View::render('auth/hero') ?>
    <section class="auth-form">
        <div class="auth-box">
            <?= Ui::logo('/login.php') ?>
            <h1>Recuperar contraseña</h1>
            <p class="muted">Te enviaremos un enlace a tu correo</p>
            <?php if ($notice !== null): ?><?= Ui::notice($notice, 'ok') ?><?php endif; ?>
            <form action="/recuperar.php" method="post" novalidate>
                <?= csrf_field() ?>
                <?= Ui::field(['id' => 'correo', 'label' => 'Correo electrónico', 'type' => 'email', 'autocomplete' => 'email', 'placeholder' => 'tucorreo@ejemplo.com', 'required' => true]) ?>
                <?= Ui::button('Enviar enlace', ['type' => 'submit', 'block' => true]) ?>
            </form>
            <p class="auth-links"><a href="/login.php">Volver a iniciar sesión</a></p>
        </div>
    </section>
</div>
