<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\Domain\Cuenta;
use App\Domain\Movimiento;
use App\View\Ui;

/**
 * Alta o edición de un movimiento (RF-10, RF-13, Fase 14: etiquetas y división de movimientos).
 *
 * @var Movimiento|null $movimiento El que se edita (null = nuevo).
 * @var array{tipo: string, monto: string, fecha: string, id_cuenta: string, id_categoria: string, descripcion: string, etiqueta?: string} $old
 * @var list<array{id_categoria: string, monto: string, descripcion: string}>|null $splitOld
 * @var array<string, string> $errors Error por campo.
 * @var list<Cuenta> $cuentas Cuentas activas (y la actual del movimiento, aunque esté inactiva).
 * @var list<Categoria> $categorias Todas las que ve el usuario; el JS deja solo las del tipo elegido (el servidor lo comprueba siempre).
 */
$editando = $movimiento !== null;
$splitOld = $splitOld ?? [
    ['id_categoria' => '', 'monto' => '', 'descripcion' => ''],
    ['id_categoria' => '', 'monto' => '', 'descripcion' => ''],
    ['id_categoria' => '', 'monto' => '', 'descripcion' => ''],
    ['id_categoria' => '', 'monto' => '', 'descripcion' => ''],
];
$splitAbierto = isset($errors['split']) || array_filter($splitOld, static fn (array $s): bool => $s['id_categoria'] !== '' || $s['monto'] !== '') !== [];

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
        <div class="form-grid">
            <?= Ui::field(['id' => 'descripcion', 'label' => 'Descripción', 'value' => $old['descripcion'], 'placeholder' => 'Ej.: Almuerzo con compañeros', 'hint' => 'Opcional. Hasta 150 caracteres.', 'error' => $errors['descripcion'] ?? null, 'attrs' => ['maxlength' => '150']]) ?>
            <?= Ui::field(['id' => 'etiqueta', 'label' => 'Etiqueta / Proyecto (#tag)', 'value' => $old['etiqueta'] ?? '', 'placeholder' => 'Ej.: vacaciones-2026', 'hint' => 'Opcional. Agrupa gastos de un viaje, evento o proyecto.', 'error' => $errors['etiqueta'] ?? null, 'attrs' => ['maxlength' => '40']]) ?>
        </div>

        <?php if (!$editando): ?>
        <details class="filters"<?= $splitAbierto ? ' open' : '' ?>>
            <summary class="filters__summary">
                <span><?= Ui::icon('plus') ?>Dividir este movimiento en varias categorías (opcional)</span>
                <?= Ui::icon('chevron-down', 'filters__chevron') ?>
            </summary>
            <div class="card__body">
                <p class="muted-note">Si una misma compra incluye varias categorías (por ejemplo, supermercado: Alimentación + Hogar), indica aquí las partes. La suma debe coincidir con el monto total.</p>
                <?php if (isset($errors['split'])): ?><?= Ui::notice($errors['split'], 'error') ?><?php endif; ?>
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="form-grid">
                    <?= Ui::select([
                        'id' => 'split_cat_' . $i,
                        'name' => 'split_categoria[]',
                        'label' => 'Categoría parte ' . ($i + 1),
                        'blank' => 'Sin usar',
                        'options' => $opcionesCategoria,
                        'optionAttrs' => $atributosCategoria,
                        'selected' => $splitOld[$i]['id_categoria'],
                    ]) ?>
                    <?= Ui::field([
                        'id' => 'split_monto_' . $i,
                        'name' => 'split_monto[]',
                        'label' => 'Monto parte ' . ($i + 1),
                        'value' => $splitOld[$i]['monto'],
                        'placeholder' => '$ 0',
                        'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true],
                    ]) ?>
                    <?= Ui::field([
                        'id' => 'split_desc_' . $i,
                        'name' => 'split_descripcion[]',
                        'label' => 'Detalle parte ' . ($i + 1) . ' (opcional)',
                        'value' => $splitOld[$i]['descripcion'],
                        'placeholder' => 'Ej.: Aseo y hogar',
                        'attrs' => ['maxlength' => '150'],
                    ]) ?>
                </div>
                <?php endfor; ?>
            </div>
        </details>
        <?php endif; ?>

        <div class="form-actions">
            <?= Ui::button($editando ? 'Guardar cambios' : 'Guardar movimiento', ['type' => 'submit']) ?>
            <?= Ui::button('Cancelar', ['href' => '/movimientos.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
<?php endif; ?>
