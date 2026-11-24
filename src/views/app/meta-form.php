<?php

declare(strict_types=1);

use App\Domain\Meta;
use App\View\Ui;

/**
 * Alta o edición de una meta de ahorro (RF-18).
 *
 * @var Meta|null $meta La que se edita (null = nueva).
 * @var array{nombre: string, monto_objetivo: string, fecha_limite: string} $old
 * @var array<string, string> $errors
 */
$editando = $meta !== null;
?>
<?= Ui::pageHead($editando ? 'Editar meta' : 'Nueva meta', $editando ? 'Cambia el nombre, el objetivo o la fecha límite' : 'Define qué quieres lograr y cuánto necesitas') ?>

<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <form action="/meta-form.php" method="post" novalidate>
        <?= csrf_field() ?>
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $meta->id ?>"><?php endif; ?>
        <?= Ui::field(['id' => 'nombre', 'label' => 'Nombre de la meta', 'value' => $old['nombre'], 'placeholder' => 'Ej.: Viaje a Cartagena', 'required' => true, 'error' => $errors['nombre'] ?? null, 'attrs' => ['maxlength' => '80']]) ?>
        <?= Ui::field(['id' => 'monto_objetivo', 'label' => 'Monto objetivo', 'size' => 'lg', 'value' => $old['monto_objetivo'], 'placeholder' => '$ 0', 'required' => true, 'error' => $errors['monto_objetivo'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true]]) ?>
        <?= Ui::field(['id' => 'fecha_limite', 'label' => 'Fecha límite', 'type' => 'date', 'value' => $old['fecha_limite'], 'hint' => 'Opcional.', 'error' => $errors['fecha_limite'] ?? null]) ?>
        <div class="form-actions">
            <?= Ui::button($editando ? 'Guardar cambios' : 'Crear meta', ['type' => 'submit']) ?>
            <?= Ui::button('Cancelar', ['href' => '/metas.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
