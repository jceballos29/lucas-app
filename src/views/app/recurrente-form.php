<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\Domain\Cuenta;
use App\Domain\Recurrente;
use App\Support\Dinero;
use App\Support\Frecuencia;
use App\View\Ui;

/**
 * Alta o edición de un movimiento recurrente / gasto fijo (Fase 9).
 *
 * @var Recurrente|null $recurrente El que se edita (null = nuevo).
 * @var array{tipo: string, monto: string, frecuencia: string, proxima_fecha: string, id_cuenta: string, id_categoria: string, descripcion: string, auto_aplicar: string} $old
 * @var array<string, string> $errors Error por campo.
 * @var list<Cuenta> $cuentas Cuentas activas (y la actual si quedó inactiva).
 * @var list<Categoria> $categorias Categorías visibles para el usuario.
 */
$editando = $recurrente !== null;

$opcionesCuenta = [];
foreach ($cuentas as $c) {
    $opcionesCuenta[(string) $c->id] = $c->nombre . ($c->activa ? '' : ' (inactiva)') . ' · ' . Dinero::formatear($c->saldo);
}
$opcionesCategoria = [];
$atributosCategoria = [];
foreach ($categorias as $k) {
    $opcionesCategoria[(string) $k->id] = $k->nombre;
    $atributosCategoria[(string) $k->id] = ['data-tipo' => $k->tipo];
}
$opcionesModo = [
    '1' => 'Automático (se registra solo al llegar la fecha)',
    '0' => 'Con confirmación (avisarme para registrarlo con un clic)',
];
?>
<?= Ui::pageHead($editando ? 'Editar movimiento recurrente' : 'Nuevo movimiento recurrente', $editando ? 'Actualiza la frecuencia, próxima fecha o cuenta de este compromiso fijo' : 'Programa un gasto fijo o un ingreso periódico') ?>

<?php if ($opcionesCuenta === []): ?>
    <?= Ui::cardOpen(['class' => 'form-card']) ?>
        <p>Para programar un movimiento recurrente necesitas al menos una cuenta activa.</p>
        <p><?= Ui::button('Crear una cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']) ?></p>
    <?= Ui::cardClose() ?>
<?php else: ?>
<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <form action="/recurrente-form.php" method="post" novalidate>
        <?= csrf_field() ?>
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $recurrente->id ?>"><?php endif; ?>
        <?= Ui::segmented(['name' => 'tipo', 'legend' => 'Tipo de movimiento recurrente', 'options' => ['gasto' => 'Gasto fijo', 'ingreso' => 'Ingreso fijo'], 'checked' => $old['tipo']]) ?>
        <?php if (isset($errors['tipo'])): ?><?= Ui::notice($errors['tipo'], 'error') ?><?php endif; ?>
        <?= Ui::field(['id' => 'monto', 'label' => 'Monto por periodo', 'size' => 'lg', 'value' => $old['monto'], 'placeholder' => '$ 0', 'required' => true, 'error' => $errors['monto'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true]]) ?>
        <div class="form-grid">
            <?= Ui::select(['id' => 'frecuencia', 'name' => 'frecuencia', 'label' => 'Frecuencia', 'options' => Frecuencia::TIPOS, 'selected' => $old['frecuencia'], 'error' => $errors['frecuencia'] ?? null]) ?>
            <?= Ui::field(['id' => 'proxima_fecha', 'label' => 'Próxima fecha de cobro / pago', 'type' => 'date', 'value' => $old['proxima_fecha'], 'required' => true, 'error' => $errors['proxima_fecha'] ?? null]) ?>
        </div>
        <div class="form-grid">
            <?= Ui::select(['id' => 'cuenta', 'name' => 'id_cuenta', 'label' => 'Cuenta', 'blank' => 'Elige una cuenta', 'options' => $opcionesCuenta, 'selected' => $old['id_cuenta'], 'error' => $errors['id_cuenta'] ?? null]) ?>
            <?= Ui::select(['id' => 'categoria', 'name' => 'id_categoria', 'label' => 'Categoría', 'blank' => 'Elige una categoría', 'options' => $opcionesCategoria, 'optionAttrs' => $atributosCategoria, 'selected' => $old['id_categoria'], 'error' => $errors['id_categoria'] ?? null]) ?>
        </div>
        <?= Ui::select(['id' => 'auto_aplicar', 'name' => 'auto_aplicar', 'label' => 'Modo de aplicación', 'options' => $opcionesModo, 'selected' => $old['auto_aplicar']]) ?>
        <?= Ui::field(['id' => 'descripcion', 'label' => 'Concepto / Descripción', 'value' => $old['descripcion'], 'placeholder' => 'Ej.: Arriendo apartamento, Internet fibra, Salario mensual...', 'hint' => 'Opcional. Hasta 150 caracteres.', 'error' => $errors['descripcion'] ?? null, 'attrs' => ['maxlength' => '150']]) ?>
        <div class="form-actions">
            <?= Ui::button($editando ? 'Guardar cambios' : 'Guardar recurrente', ['type' => 'submit']) ?>
            <?= Ui::button('Cancelar', ['href' => '/recurrentes.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
<?php endif; ?>
