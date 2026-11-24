<?php

declare(strict_types=1);

use App\View\Ui;

/** Vista de maqueta (datos de ejemplo, sin lógica). Se conectará a datos reales en su fase. */
?>
<?= Ui::pageHead('Nuevo movimiento', 'Registra un ingreso o un gasto') ?>

<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <form action="movimientos.php" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="">
        <?= Ui::segmented(['name' => 'tipo', 'legend' => 'Tipo de movimiento', 'options' => ['gasto' => 'Gasto', 'ingreso' => 'Ingreso'], 'checked' => 'gasto']) ?>
        <?= Ui::field(['id' => 'monto', 'label' => 'Monto', 'size' => 'lg', 'placeholder' => '$ 0', 'required' => true, 'attrs' => ['inputmode' => 'numeric', 'data-money' => true]]) ?>
        <div class="form-grid">
            <?= Ui::field(['id' => 'fecha', 'label' => 'Fecha', 'type' => 'date', 'value' => '2026-11-04', 'required' => true]) ?>
            <?= Ui::select(['id' => 'cuenta', 'name' => 'id_cuenta', 'label' => 'Cuenta', 'options' => ['Efectivo', 'Bancolombia', 'Nequi']]) ?>
        </div>
        <?= Ui::select(['id' => 'categoria', 'name' => 'id_categoria', 'label' => 'Categoría']) ?>
        <?= Ui::field(['id' => 'descripcion', 'label' => 'Descripción', 'placeholder' => 'Ej.: Almuerzo con compañeros', 'hint' => 'Opcional. Hasta 150 caracteres.', 'attrs' => ['maxlength' => '150']]) ?>
        <div class="form-actions">
            <?= Ui::button('Guardar movimiento', ['type' => 'submit']) ?>
            <?= Ui::button('Cancelar', ['href' => 'movimientos.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
