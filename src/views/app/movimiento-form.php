<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\Domain\Cuenta;
use App\Domain\Movimiento;
use App\View\Ui;

/**
 * Alta o edición de un movimiento (RF-10, RF-13).
 *
 * @var Movimiento|null $movimiento El que se edita (null = nuevo).
 * @var array{tipo: string, monto: string, fecha: string, id_cuenta: string, id_categoria: string, descripcion: string} $old
 * @var array<string, string> $errors Error por campo.
 * @var list<Cuenta> $cuentas Cuentas activas (y la actual del movimiento, aunque esté inactiva).
 * @var list<Categoria> $categorias Todas las que ve el usuario; el JS deja solo las del tipo elegido (el servidor lo comprueba siempre).
 */
$editando = $movimiento !== null;

$opcionesCuenta = [];
foreach ($cuentas as $c) {
    $opcionesCuenta[(string) $c->id] = $c->nombre . ($c->activa ? '' : ' (inactiva)') . ' · ' . \App\Support\Dinero::formatear($c->saldo);
}
$opcionesCategoria = [];
$atributosCategoria = [];
foreach ($categorias as $k) {
    $opcionesCategoria[(string) $k->id] = $k->nombre;
    $atributosCategoria[(string) $k->id] = ['data-tipo' => $k->tipo];
}
?>
<?= Ui::pageHead($editando ? 'Editar movimiento' : 'Nuevo movimiento', $editando ? 'Corrige los datos del movimiento' : 'Registra un ingreso o un gasto') ?>

<?php if ($opcionesCuenta === []): ?>
    <?= Ui::cardOpen(['class' => 'form-card']) ?>
        <p>Para registrar un movimiento necesitas al menos una cuenta activa.</p>
        <p><?= Ui::button('Crear una cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']) ?></p>
    <?= Ui::cardClose() ?>
<?php else: ?>
<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <form action="/movimiento-form.php" method="post" novalidate>
        <?= csrf_field() ?>
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $movimiento->id ?>"><?php endif; ?>
        <?= Ui::segmented(['name' => 'tipo', 'legend' => 'Tipo de movimiento', 'options' => ['gasto' => 'Gasto', 'ingreso' => 'Ingreso'], 'checked' => $old['tipo']]) ?>
        <?php if (isset($errors['tipo'])): ?><?= Ui::notice($errors['tipo'], 'error') ?><?php endif; ?>
        <?= Ui::field(['id' => 'monto', 'label' => 'Monto', 'size' => 'lg', 'value' => $old['monto'], 'placeholder' => '$ 0', 'required' => true, 'error' => $errors['monto'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true]]) ?>
        <div class="form-grid">
            <?= Ui::field(['id' => 'fecha', 'label' => 'Fecha', 'type' => 'date', 'value' => $old['fecha'], 'required' => true, 'error' => $errors['fecha'] ?? null]) ?>
            <?= Ui::select(['id' => 'cuenta', 'name' => 'id_cuenta', 'label' => 'Cuenta', 'blank' => 'Elige una cuenta', 'options' => $opcionesCuenta, 'selected' => $old['id_cuenta'], 'error' => $errors['id_cuenta'] ?? null]) ?>
        </div>
        <?= Ui::select(['id' => 'categoria', 'name' => 'id_categoria', 'label' => 'Categoría', 'blank' => 'Elige una categoría', 'options' => $opcionesCategoria, 'optionAttrs' => $atributosCategoria, 'selected' => $old['id_categoria'], 'error' => $errors['id_categoria'] ?? null]) ?>
        <?= Ui::field(['id' => 'descripcion', 'label' => 'Descripción', 'value' => $old['descripcion'], 'placeholder' => 'Ej.: Almuerzo con compañeros', 'hint' => 'Opcional. Hasta 150 caracteres.', 'error' => $errors['descripcion'] ?? null, 'attrs' => ['maxlength' => '150']]) ?>
        <div class="form-actions">
            <?= Ui::button($editando ? 'Guardar cambios' : 'Guardar movimiento', ['type' => 'submit']) ?>
            <?= Ui::button('Cancelar', ['href' => '/movimientos.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
<?php endif; ?>
