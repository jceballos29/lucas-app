<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\Domain\Cuenta;
use App\Support\Dinero;
use App\View\Html;
use App\View\Ui;

/**
 * Importación bancaria asistida de movimientos por CSV (Fase 13).
 *
 * @var list<Cuenta> $cuentas
 * @var list<Categoria> $categorias
 * @var array{id_cuenta: string, id_categoria_gasto: string, id_categoria_ingreso: string, csv_texto: string} $old
 * @var array<string, string> $errors
 * @var list<string> $erroresCsv Todos los problemas del CSV, con su número de línea.
 * @var array<string, mixed>|null $preview Vista previa (M8/U4): filas interpretadas, avisos, totales y saldo resultante.
 */
$erroresCsv = $erroresCsv ?? [];
$preview = $preview ?? null;
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

<?php if ($preview !== null): ?>
<?php
    $dups = $preview['duplicadas'];
    $marcadas = isset($preview['marcadas']) ? array_flip($preview['marcadas']) : null;
    $nAmbiguas = count(array_filter($preview['filas'], static fn (array $f): bool => $f['ambiguo']));
    $filasTabla = [];
    foreach ($preview['filas'] as $f) {
        $linea = (int) $f['linea'];
        $avisos = [];
        if (isset($dups[$linea])) {
            $avisos[] = Ui::badge($dups[$linea] === 'bd' ? 'Posible duplicado: ya está en la cuenta' : 'Repetida en este archivo', 'warn', 'alert');
        }
        if ($f['ambiguo']) {
            $avisos[] = Ui::badge('Monto ambiguo: se leyó «' . trim($f['monto_crudo']) . '» como ' . Dinero::formatear($f['monto']), 'warn', 'alert');
        }
        $marcada = $marcadas !== null ? isset($marcadas[$linea]) : (($dups[$linea] ?? '') !== 'bd');
        $filasTabla[] = [
            'incluir' => new Html('<input type="checkbox" name="incluir[]" value="' . $linea . '" id="inc-' . $linea . '"' . ($marcada ? ' checked' : '') . ' aria-label="Importar la línea ' . $linea . '">'),
            'linea' => (string) $linea,
            'fecha' => $f['fecha'],
            'tipo' => Ui::badge($f['tipo'] === 'ingreso' ? 'Ingreso' : 'Gasto', $f['tipo'] === 'ingreso' ? 'brand' : 'neutral'),
            'descripcion' => $f['descripcion'],
            'monto' => ($f['tipo'] === 'ingreso' ? '+' : '−') . Dinero::formatear($f['monto']),
            'avisos' => $avisos === [] ? '' : Html::join($avisos),
        ];
    }
?>
<?= Ui::cardOpen(['title' => 'Revisa antes de importar', 'subtitle' => count($preview['filas']) . ' movimientos leídos para «' . $preview['cuenta'] . '»', 'class' => 'form-card']) ?>
    <?php if (isset($errors['incluir'])): ?><?= Ui::notice($errors['incluir'], 'error') ?><?php endif; ?>
    <?php if ($preview['truncado']): ?><?= Ui::notice('El archivo tiene más de ' . App\Movimientos\ImportadorCsv::MAX_FILAS . ' filas: aquí solo se leyeron las primeras ' . App\Movimientos\ImportadorCsv::MAX_FILAS . '. Importa estas y luego repite con el resto.', 'warn') ?><?php endif; ?>
    <?php if ($nAmbiguas > 0): ?><?= Ui::notice(($nAmbiguas === 1 ? 'Hay 1 monto que se puede leer de dos maneras' : "Hay $nAmbiguas montos que se pueden leer de dos maneras") . ': Lucas lee el punto como separador de miles («1.234» = mil doscientos treinta y cuatro). Si tu banco usa el punto como decimal, vuelve a editar y escríbelos con coma (1,234).', 'warn') ?><?php endif; ?>
    <?php if ($dups !== []): ?><?= Ui::notice('Se marcaron ' . count($dups) . ' filas que parecen repetidas (mismo día, tipo, monto y descripción). Las que ya están en la cuenta vienen desmarcadas.', 'info') ?><?php endif; ?>
    <?php if ($preview['saldo_negativo']): ?><?= Ui::notice('Con todas las filas, la cuenta quedaría en ' . Dinero::formatear($preview['saldo_final']) . ' (saldo negativo): no se podrá importar así. Desmarca gastos o elige otra cuenta.', 'error') ?><?php endif; ?>

    <dl class="summary">
        <div><dt>Ingresos</dt><dd>+<?= escape(Dinero::formatear($preview['ingresos'])) ?></dd></div>
        <div><dt>Gastos</dt><dd>−<?= escape(Dinero::formatear($preview['gastos'])) ?></dd></div>
        <div><dt>Saldo de «<?= escape($preview['cuenta']) ?>»</dt><dd><?= escape(Dinero::formatear($preview['saldo_actual'])) ?> → <strong><?= escape(Dinero::formatear($preview['saldo_final'])) ?></strong></dd></div>
    </dl>
    <p class="muted">Los totales cuentan todas las filas leídas; las que desmarques no se importan.</p>

    <form action="/movimientos-importar.php" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id_cuenta" value="<?= escape($old['id_cuenta']) ?>">
        <input type="hidden" name="id_categoria_gasto" value="<?= escape($old['id_categoria_gasto']) ?>">
        <input type="hidden" name="id_categoria_ingreso" value="<?= escape($old['id_categoria_ingreso']) ?>">
        <textarea name="csv_texto" hidden><?= escape($old['csv_texto']) ?></textarea>
        <?= Ui::table([
            'caption' => 'Movimientos que se importarán',
            'columns' => [
                ['key' => 'incluir', 'label' => 'Importar'],
                ['key' => 'linea', 'label' => 'Línea'],
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'tipo', 'label' => 'Tipo'],
                ['key' => 'descripcion', 'label' => 'Descripción'],
                ['key' => 'monto', 'label' => 'Monto', 'align' => 'end'],
                ['key' => 'avisos', 'label' => 'Avisos'],
            ],
            'rows' => $filasTabla,
        ]) ?>
        <div class="form-actions">
            <button class="btn btn--primary" type="submit" name="accion" value="confirmar">Confirmar importación</button>
            <button class="btn btn--secondary" type="submit" name="accion" value="editar">Volver y editar</button>
            <?= Ui::button('Cancelar', ['href' => '/movimientos.php', 'variant' => 'ghost']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
<?php elseif ($opcionesCuenta === []): ?>
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
            'type' => 'textarea',
            'label' => 'Líneas CSV (Fecha;Descripción;Monto o Fecha;Tipo;Descripción;Monto)',
            'value' => $old['csv_texto'],
            'placeholder' => "2026-11-25;Supermercado Éxito;-185.000\n2026-11-25;Reembolso nómina;+2.500.000",
            'hint' => 'Una fila por línea. Acepta fechas AAAA-MM-DD o DD/MM/AAAA, separadas por punto y coma (;) o coma (,). Un monto negativo (-185000) o tipo «gasto» se importa como gasto; un monto con «+» (+2500000) o tipo «ingreso», como ingreso.',
            'error' => $errors['csv_texto'] ?? null,
        ]) ?>

        <?= Ui::field([
            'id' => 'csv_archivo',
            'label' => 'O sube un archivo .csv desde tu equipo (opcional)',
            'type' => 'file',
            'attrs' => ['accept' => '.csv,text/csv'],
        ]) ?>

        <?php if ($erroresCsv !== []): ?>
            <?= Ui::notice(new Html('<strong>Líneas con problemas</strong> (corrígelas en el cuadro de arriba):<ul class="error-list">' . implode('', array_map(static fn (string $e): string => '<li>' . escape($e) . '</li>', $erroresCsv)) . '</ul>'), 'error') ?>
        <?php endif; ?>
        <div class="form-actions">
            <?= Ui::button('Previsualizar', ['type' => 'submit', 'icon' => 'search']) ?>
            <?= Ui::button('Cancelar', ['href' => '/movimientos.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
<?php endif; ?>
