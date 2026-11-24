<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\Domain\Presupuesto;
use App\Support\Mes;
use App\View\Fecha;
use App\View\Ui;

/**
 * Alta de un presupuesto o edición de su límite (RF-15).
 *
 * @var Presupuesto|null $presupuesto El que se edita (null = nuevo).
 * @var array{id_categoria: string, mes: string, anio: string, monto_limite: string} $old
 * @var array<string, string> $errors
 * @var list<Categoria> $categorias Las categorías de gastos que ve el usuario.
 */
$editando = $presupuesto !== null;
$volver = '/presupuestos.php?' . http_build_query(['mes' => (int) $old['mes'], 'anio' => (int) $old['anio']]);

$opcionesCategoria = [];
foreach ($categorias as $c) {
    $opcionesCategoria[(string) $c->id] = $c->nombre;
}
$opcionesMes = [];
for ($m = 1; $m <= 12; $m++) {
    $opcionesMes[(string) $m] = ucfirst(Fecha::mesNombre($m));
}
?>
<?= Ui::pageHead($editando ? 'Editar presupuesto' : 'Nuevo presupuesto', $editando ? 'Cambia el límite de ' . $presupuesto->categoriaNombre : 'Fija cuánto quieres gastar en una categoría este mes') ?>

<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <form action="/presupuesto-form.php" method="post" novalidate>
        <?= csrf_field() ?>
        <?php if ($editando): ?>
            <input type="hidden" name="id" value="<?= (int) $presupuesto->id ?>">
            <p>Categoría: <strong><?= escape($presupuesto->categoriaNombre) ?></strong> · <strong><?= escape(Mes::etiqueta($presupuesto->mes, $presupuesto->anio)) ?></strong></p>
            <p class="muted">La categoría y el mes no se cambian: si quieres otro, crea un presupuesto nuevo.</p>
        <?php else: ?>
            <?= Ui::select(['id' => 'categoria', 'name' => 'id_categoria', 'label' => 'Categoría de gastos', 'blank' => 'Elige una categoría', 'options' => $opcionesCategoria, 'selected' => $old['id_categoria'], 'error' => $errors['id_categoria'] ?? null]) ?>
            <div class="form-grid">
                <?= Ui::select(['id' => 'mes', 'name' => 'mes', 'label' => 'Mes', 'options' => $opcionesMes, 'selected' => $old['mes'], 'error' => $errors['mes'] ?? null]) ?>
                <?= Ui::field(['id' => 'anio', 'label' => 'Año', 'type' => 'number', 'value' => $old['anio'], 'required' => true, 'attrs' => ['min' => (string) Mes::ANIO_MIN, 'max' => (string) Mes::ANIO_MAX, 'inputmode' => 'numeric']]) ?>
            </div>
        <?php endif; ?>
        <?= Ui::field(['id' => 'monto_limite', 'label' => 'Límite de gasto', 'size' => 'lg', 'value' => $old['monto_limite'], 'placeholder' => '$ 0', 'required' => true, 'error' => $errors['monto_limite'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true]]) ?>
        <div class="form-actions">
            <?= Ui::button($editando ? 'Guardar cambios' : 'Crear presupuesto', ['type' => 'submit']) ?>
            <?= Ui::button('Cancelar', ['href' => $volver, 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
