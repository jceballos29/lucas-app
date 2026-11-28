<?php

declare(strict_types=1);

use App\View\Ui;

/**
 * Página de error con la marca y salida (E3, U5): la que ve una persona en el navegador cuando una petición se corta con un error.
 *
 * @var string $titulo Título corto según el código.
 * @var int $estado Código HTTP.
 * @var string $mensaje Qué pasó, sin detalles técnicos.
 * @var string $volver Dónde ir con «Volver» (la página anterior si es del mismo sitio, o el inicio).
 */
?>
<div class="auth-box error-page">
        <?= Ui::logo('/') ?>
        <p class="error-page__code"><?= (int) $estado ?></p>
        <h1><?= escape($titulo) ?></h1>
        <p class="muted"><?= escape($mensaje) ?></p>
        <div class="form-actions">
            <?= Ui::button('Volver', ['href' => $volver, 'icon' => 'chevron-left']) ?>
            <?= Ui::button('Ir al inicio', ['href' => '/', 'variant' => 'secondary']) ?>
        </div>
</div>
