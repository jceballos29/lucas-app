<?php

declare(strict_types=1);

use App\Domain\Presupuesto;
use App\Support\Dinero;
use App\Support\Mes;
use App\View\Avisos;
use App\View\Html;
use App\View\Ui;

/**
 * Presupuestos del mes (RF-15 a RF-17): barra de progreso por categoría y avisos al 80 % y al superar el límite.
 *
 * @var int $mes
 * @var int $anio
 * @var array{mes: int, anio: int} $anterior
 * @var array{mes: int, anio: int} $siguiente
 * @var list<Presupuesto> $presupuestos
 * @var string $presupuestado Suma de los límites del mes.
 * @var string $gastado Suma de lo gastado en esas categorías.
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$puedeEscribir = \App\Espacios\ReglasEspacio::puedeEscribir($currentUser['espacio_rol'] ?? null);
$enlace = static fn (array $m): string => '/presupuestos.php?' . http_build_query($m);
$etiqueta = [
    'ok' => ['En orden', 'neutral', null],
    'cerca' => ['Cerca del límite', 'warn', 'alert'],
    'limite' => ['Límite alcanzado', 'danger', 'alert'],
    'excedido' => ['Excedido', 'danger', 'alert'],
];

$monthNav = new Html('<div class="month-nav">'
    . Ui::iconButton('chevron-left', 'Mes anterior', ['href' => $enlace($anterior)])
    . '<span class="month-nav__label">' . escape(Mes::etiqueta($mes, $anio)) . '</span>'
    . Ui::iconButton('chevron-right', 'Mes siguiente', ['href' => $enlace($siguiente)])
    . '</div>');
$nuevo = '/presupuesto-form.php?' . http_build_query(['mes' => $mes, 'anio' => $anio]);
$asistente = '/presupuesto-asistente.php?' . http_build_query(['mes' => $mes, 'anio' => $anio]);
$numMesAnterior = $numMesAnterior ?? 0;
?>
<?= Ui::pageHead('Presupuestos', 'Límites de gasto por categoría', Html::join($puedeEscribir ? [
    $monthNav,
    Ui::button('Asistente 50/30/20', ['href' => $asistente, 'variant' => 'secondary', 'icon' => 'pie']),
    Ui::button('Nuevo presupuesto', ['href' => $nuevo, 'icon' => 'plus']),
] : [$monthNav])) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<?php foreach ($presupuestos as $p): ?>
    <?= Avisos::presupuesto($p) ?>
<?php endforeach; ?>

<?php if ($presupuestos === []): ?>
    <?= Ui::cardOpen() ?>
        <p>No tienes presupuestos para <?= escape(Mes::etiqueta($mes, $anio)) ?>. Fija un límite para una categoría de gastos, usa la regla 50/30/20 o copia los límites del mes anterior con un clic.</p>
        <?php if ($puedeEscribir): ?>
            <div class="page-head__actions mt-4">
                <?= Ui::button('Crear un presupuesto', ['href' => $nuevo, 'icon' => 'plus']) ?>
                <?= Ui::button('Usar asistente 50/30/20', ['href' => $asistente, 'variant' => 'secondary', 'icon' => 'pie']) ?>
                <?php if ($numMesAnterior > 0): ?>
                    <?= Ui::postForm($enlace(['mes' => $mes, 'anio' => $anio]), ['accion' => 'clonar_anterior'], Ui::button('Copiar ' . $numMesAnterior . ' del mes anterior', ['type' => 'submit', 'variant' => 'secondary', 'icon' => 'check'])) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?= Ui::cardClose() ?>
<?php else: ?>
    <div class="toolbar">
        <p class="summary">
            <span>Presupuestado <b><?= escape(Dinero::formatear($presupuestado)) ?></b></span>
            <span>Gastado <b><?= escape(Dinero::formatear($gastado)) ?></b></span>
        </p>
        <?php if ($puedeEscribir && $numMesAnterior > 0): ?>
            <?= Ui::postForm($enlace(['mes' => $mes, 'anio' => $anio]), ['accion' => 'clonar_anterior'], Ui::button('Copiar faltantes del mes anterior', ['type' => 'submit', 'variant' => 'ghost', 'size' => 'sm'])) ?>
        <?php endif; ?>
    </div>
    <div class="cards-grid cards-grid--3">
        <?php foreach ($presupuestos as $p): ?>
            <?php
            $pr = $p->progreso();
            [$texto, $tonoInsignia, $icono] = $etiqueta[$pr['estado']];
            ?>
            <?= Ui::cardOpen(['tag' => 'article', 'class' => 'budget']) ?>
                <div class="budget__head"><span class="budget__name"><?= escape($p->categoriaNombre) ?></span><?= Ui::badge($texto, $tonoInsignia, $icono) ?></div>
                <?= Ui::progress($pr['percent'], 'Presupuesto de ' . $p->categoriaNombre, $pr['tone']) ?>
                <div class="budget__foot"><span><?= escape(Dinero::formatear($p->gastado)) ?> de <?= escape(Dinero::formatear($p->montoLimite)) ?></span><b class="<?= $pr['tone'] === 'over' ? 'is-over' : '' ?>"><?= $pr['percent'] ?> %</b></div>
                <?php if ($puedeEscribir): ?>
                <div class="account__actions">
                    <?= Ui::button('Editar', ['href' => '/presupuesto-form.php?id=' . $p->id, 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'edit', 'attrs' => ['aria-label' => 'Editar el presupuesto de ' . $p->categoriaNombre]]) ?>
                    <?= Ui::postForm($enlace(['mes' => $mes, 'anio' => $anio]), ['id' => $p->id, 'accion' => 'eliminar'], Ui::button('Eliminar', [
                        'type' => 'submit',
                        'variant' => 'secondary',
                        'size' => 'sm',
                        'attrs' => ['aria-label' => 'Eliminar el presupuesto de ' . $p->categoriaNombre, 'data-confirm' => '¿Eliminar el presupuesto de «' . $p->categoriaNombre . '» de ' . Mes::etiqueta($mes, $anio) . '? Tus movimientos no se tocan.', 'data-confirm-ok' => 'Eliminar'],
                    ])) ?>
                </div>
                <?php endif; ?>
            <?= Ui::cardClose('article') ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($puedeEscribir): ?><?= Ui::fab('/movimiento-form.php', 'Nuevo movimiento') ?><?php endif; ?>
