<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\Domain\Cuenta;
use App\Domain\Movimiento;
use App\Movimientos\Filtros;
use App\Support\Dinero;
use App\View\Fecha;
use App\View\Html;
use App\View\Ui;

/**
 * Listado de movimientos con filtros y paginación (RF-11, RF-12).
 *
 * @var list<Movimiento> $rows La página actual.
 * @var array{desde: string, hasta: string, cuenta: int, categoria: int, tipo: string} $filtros
 * @var list<Cuenta> $cuentas Todas las cuentas del usuario (para el filtro).
 * @var list<Categoria> $categorias Las categorías que ve el usuario (para el filtro).
 * @var int $total Movimientos que cumplen los filtros (todas las páginas).
 * @var int $pagina Página actual.
 * @var int $paginas Total de páginas.
 * @var array{ingresos: string, gastos: string} $totales Suma de TODO lo filtrado.
 * @var string $volver Filtros y página actuales como parte de una URL (para volver tras eliminar).
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$hayFiltros = Filtros::hayAlguno($filtros);

$rows = array_map(static function (Movimiento $m) use ($volver): array {
    $nombre = $m->descripcion ?? $m->categoriaNombre;

    return [
        'fecha' => Fecha::simple($m->fecha),
        'descripcion' => $m->descripcion !== null ? new Html('<span class="cell-title">' . escape($m->descripcion) . '</span>') : new Html('<span class="muted-note">Sin descripción</span>'),
        'categoria' => Ui::badge($m->categoriaNombre),
        'cuenta' => $m->cuentaNombre,
        'monto' => Ui::money($m->monto, $m->esIngreso() ? 'in' : 'out'),
        'acciones' => Ui::actions([
            Ui::iconButton('edit', 'Editar ' . $nombre, ['href' => '/movimiento-form.php?id=' . $m->id]),
            Ui::postForm('/movimiento-eliminar.php', ['id' => $m->id, 'volver' => $volver], Ui::iconButton('trash', 'Eliminar ' . $nombre, [
                'type' => 'submit',
                'tone' => 'danger',
                'attrs' => ['data-confirm' => '¿Eliminar el movimiento «' . $nombre . '» de ' . Dinero::formatear($m->monto) . '? Esta acción no se puede deshacer.', 'data-confirm-ok' => 'Eliminar'],
            ])),
        ]),
    ];
}, $rows);

$opcionesCuenta = [];
foreach ($cuentas as $c) {
    $opcionesCuenta[(string) $c->id] = $c->nombre . ($c->activa ? '' : ' (inactiva)');
}
$opcionesCategoria = [];
foreach ($categorias as $k) {
    $opcionesCategoria[(string) $k->id] = $k->nombre . ' · ' . ($k->tipo === 'gasto' ? 'gasto' : 'ingreso');
}

// Los enlaces de página conservan los filtros; el «%» se duplica porque el patrón pasa por sprintf().
$base = Filtros::query($filtros);
$patron = '?' . ($base !== '' ? str_replace('%', '%%', $base) . '&' : '') . 'pagina=%d';
$balance = Dinero::sumar([$totales['ingresos'], '-' . $totales['gastos']]);
?>
<?= Ui::pageHead('Movimientos', 'Historial de ingresos y gastos', Ui::button('Nuevo movimiento', ['href' => '/movimiento-form.php', 'icon' => 'plus'])) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<details class="filters">
    <summary class="filters__summary"><span><?= Ui::icon('filter') ?>Filtros</span><?= Ui::icon('chevron-down', 'filters__chevron') ?></summary>
    <form class="filters__form" action="/movimientos.php" method="get">
        <?= Ui::field(['id' => 'desde', 'label' => 'Desde', 'type' => 'date', 'value' => $filtros['desde'], 'flush' => true]) ?>
        <?= Ui::field(['id' => 'hasta', 'label' => 'Hasta', 'type' => 'date', 'value' => $filtros['hasta'], 'flush' => true]) ?>
        <?= Ui::select(['id' => 'f-cuenta', 'name' => 'cuenta', 'label' => 'Cuenta', 'blank' => 'Todas', 'options' => $opcionesCuenta, 'selected' => (string) $filtros['cuenta'], 'flush' => true]) ?>
        <?= Ui::select(['id' => 'f-cat', 'name' => 'categoria', 'label' => 'Categoría', 'blank' => 'Todas', 'options' => $opcionesCategoria, 'selected' => (string) $filtros['categoria'], 'flush' => true]) ?>
        <?= Ui::select(['id' => 'f-tipo', 'name' => 'tipo', 'label' => 'Tipo', 'blank' => 'Todos', 'options' => ['gasto' => 'Gasto', 'ingreso' => 'Ingreso'], 'selected' => $filtros['tipo'], 'flush' => true]) ?>
        <?= Ui::button('Filtrar', ['type' => 'submit', 'variant' => 'dark']) ?>
    </form>
</details>

<?php if ($total === 0): ?>
    <?= Ui::cardOpen() ?>
        <?php if ($hayFiltros): ?>
            <p>No hay movimientos con esos filtros.</p>
            <p><?= Ui::button('Quitar filtros', ['href' => '/movimientos.php', 'variant' => 'secondary']) ?></p>
        <?php elseif ($cuentas === []): ?>
            <p>Para registrar movimientos primero crea una cuenta (efectivo, bancaria, una billetera...).</p>
            <p><?= Ui::button('Crear mi primera cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']) ?></p>
        <?php else: ?>
            <p>Aún no has registrado movimientos. Empieza con tu último gasto o ingreso.</p>
            <p><?= Ui::button('Registrar un movimiento', ['href' => '/movimiento-form.php', 'icon' => 'plus']) ?></p>
        <?php endif; ?>
    <?= Ui::cardClose() ?>
<?php else: ?>
    <p class="summary" aria-live="polite">
        <span><?= number_format($total, 0, ',', '.') ?> <?= $total === 1 ? 'movimiento' : 'movimientos' ?></span>
        <span>Ingresos <?= Ui::money($totales['ingresos'], 'in') ?></span>
        <span>Gastos <?= Ui::money($totales['gastos'], 'out') ?></span>
        <span>Balance <?= Ui::money($balance) ?></span>
    </p>

    <?= Ui::table([
        'caption' => 'Movimientos',
        'columns' => [
            ['key' => 'fecha', 'label' => 'Fecha'],
            ['key' => 'descripcion', 'label' => 'Descripción'],
            ['key' => 'categoria', 'label' => 'Categoría'],
            ['key' => 'cuenta', 'label' => 'Cuenta'],
            ['key' => 'monto', 'label' => 'Monto', 'align' => 'end'],
            ['key' => 'acciones', 'label' => 'Acciones', 'kind' => 'actions'],
        ],
        'rows' => $rows,
    ]) ?>

    <div class="toolbar">
        <?= $paginas > 1 ? Ui::pagination($pagina, $paginas, $patron) : '<span></span>' ?>
        <?= Ui::button('Exportar CSV', ['href' => '#', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'download', 'attrs' => ['aria-disabled' => 'true', 'title' => 'Disponible próximamente']]) ?>
    </div>
<?php endif; ?>
<?= Ui::fab('/movimiento-form.php', 'Nuevo movimiento') ?>
