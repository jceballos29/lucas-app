<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\Domain\Cuenta;
use App\Support\Dinero;
use App\View\Ui;

/**
 * Importación bancaria asistida de movimientos por CSV (Fase 13).
 *
 * @var list<Cuenta> $cuentas
 * @var list<Categoria> $categorias
 * @var array{id_cuenta: string, id_categoria_gasto: string, id_categoria_ingreso: string, csv_texto: string} $old
 * @var array<string, string> $errors
 */
$opcionesCuenta = [];
foreach ($cuentas as $c) {
    $opcionesCuenta[(string) $c->id] = $c->nombre . ' · ' . Dinero::formatear($c->saldo);
}
$catsGasto = [];
$catsIngreso = [];
foreach ($categorias as $k) {
    if ($k->tipo === 'gasto') {
        $catsGasto[(string) $k->id] = $k->nombre;
    } else {
        $catsIngreso[(string) $k->id] = $k->nombre;
    }
}
?>
<?= Ui::pageHead('Importar movimientos desde CSV', 'Carga un extracto bancario o pega tus movimientos en lote sin digitarlos uno por uno') ?>

<?php if ($opcionesCuenta === []): ?>
    <?= Ui::cardOpen(['class' => 'form-card']) ?>
        <p>Para importar movimientos necesitas al menos una cuenta activa.</p>
        <p><?= Ui::button('Crear una cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']) ?></p>
    <?= Ui::cardClose() ?>
<?php else: ?>
<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <form action="/movimientos-importar.php" method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <?= Ui::select(['id' => 'cuenta', 'name' => 'id_cuenta', 'label' => 'Cuenta donde se importarán los movimientos', 'options' => $opcionesCuenta, 'selected' => $old['id_cuenta'], 'error' => $errors['id_cuenta'] ?? null]) ?>
        <div class="form-grid">
            <?= Ui::select(['id' => 'cat_gasto', 'name' => 'id_categoria_gasto', 'label' => 'Categoría para los gastos (-)', 'options' => $catsGasto, 'selected' => $old['id_categoria_gasto'], 'error' => $errors['id_categoria_gasto'] ?? null]) ?>
            <?= Ui::select(['id' => 'cat_ingreso', 'name' => 'id_categoria_ingreso', 'label' => 'Categoría para los ingresos (+)', 'options' => $catsIngreso, 'selected' => $old['id_categoria_ingreso'], 'error' => $errors['id_categoria_ingreso'] ?? null]) ?>
        </div>

        <?= Ui::field([
            'id' => 'csv_texto',
            'label' => 'Líneas CSV (Fecha;Descripción;Monto o Fecha;Tipo;Descripción;Monto)',
            'value' => $old['csv_texto'],
            'placeholder' => '2026-11-25;Supermercado Éxito;-185000',
            'hint' => 'Acepta fechas AAAA-MM-DD o DD/MM/AAAA, separadas por punto y coma (;) o coma (,). Un monto negativo (-185000) o tipo «gasto» se importa como gasto; un monto con «+» (+2500000) o tipo «ingreso», como ingreso.',
            'error' => $errors['csv_texto'] ?? null,
        ]) ?>

        <?= Ui::field([
            'id' => 'csv_archivo',
            'label' => 'O sube un archivo .csv desde tu equipo (opcional)',
            'type' => 'file',
            'attrs' => ['accept' => '.csv,text/csv'],
        ]) ?>

        <div class="form-actions">
            <?= Ui::button('Importar movimientos', ['type' => 'submit', 'icon' => 'plus']) ?>
            <?= Ui::button('Cancelar', ['href' => '/movimientos.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
<?php endif; ?>
