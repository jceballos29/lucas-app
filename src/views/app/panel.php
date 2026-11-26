<?php

declare(strict_types=1);

use App\Domain\Movimiento;
use App\Domain\Presupuesto;
use App\Domain\Recurrente;
use App\Support\Dinero;
use App\View\Avisos;
use App\View\Fecha;
use App\View\Html;
use App\View\Ui;

/**
 * Panel del usuario (RF-21): saldo total, ingresos, gastos y balance del mes, gastos por categoría, últimos movimientos y presupuestos.
 *
 * @var string $nombre Primer nombre del usuario.
 * @var string $mesTexto «noviembre de 2026».
 * @var string $mesCorto «Noviembre».
 * @var string $saldoTotal Suma del saldo de las cuentas activas.
 * @var int $numCuentas Cuentas activas.
 * @var string $ingresos Ingresos del mes.
 * @var string $gastos Gastos del mes.
 * @var string $balance Ingresos − gastos del mes.
 * @var array{items: list<array{label: string, total: string, percent: float}>, total: string} $categorias
 * @var list<Movimiento> $ultimos
 * @var list<Presupuesto> $presupuestos Los del mes, los más gastados primero.
 * @var list<Recurrente> $recurrentesVencidos
 */
$recurrentesVencidos = $recurrentesVencidos ?? [];
$tarjetasPorVencer = $tarjetasPorVencer ?? [];
$puedeEscribir = \App\Espacios\ReglasEspacio::puedeEscribir($currentUser['espacio_rol'] ?? null);
$balanceCentavos = Dinero::aCentavos($balance);
$balanceTono = $balanceCentavos < 0 ? 'out' : ($balanceCentavos > 0 ? 'in' : 'plain');
$slices = array_map(static fn (array $i): array => ['label' => $i['label'], 'value' => $i['percent']], $categorias['items']);
?>
<?= Ui::pageHead('Hola, ' . $nombre, 'Resumen de ' . $mesTexto, $puedeEscribir ? Ui::button('Nuevo movimiento', ['href' => '/movimiento-form.php', 'icon' => 'plus']) : null) ?>

<?php if ($recurrentesVencidos !== []): ?>
    <?= Ui::notice(new Html('Tienes <strong>' . count($recurrentesVencidos) . (count($recurrentesVencidos) === 1 ? ' gasto o ingreso fijo pendiente' : ' gastos o ingresos fijos pendientes') . '</strong> por aplicar. <a href="/recurrentes.php">Revisar en Gastos fijos</a>.'), 'warn') ?>
<?php endif; ?>

<?php foreach ($tarjetasPorVencer as $tv): ?>
    <?= Ui::notice(new Html('La fecha límite de pago de <strong>' . escape($tv['nombre']) . '</strong> vence ' . ($tv['dias'] === 0 ? '<strong>hoy</strong>' : 'en <strong>' . (int) $tv['dias'] . ($tv['dias'] === 1 ? ' día' : ' días') . '</strong>') . ' (deuda actual: ' . escape(Dinero::formatear($tv['deuda'])) . '). <a href="/transferencia-form.php?destino=' . (int) $tv['id'] . '">Abonar a la tarjeta</a>.'), 'warn') ?>
<?php endforeach; ?>

<?php foreach ($presupuestos as $p): ?><?= Avisos::presupuesto($p) ?><?php endforeach; ?>

<div class="dashboard">
    <section class="balance" aria-label="Resumen del mes">
        <div>
            <span class="balance__label">Saldo total</span>
            <strong class="balance__amount"><?= escape(Dinero::formatear($saldoTotal)) ?></strong>
            <span class="balance__note"><?= $numCuentas === 0 ? 'Aún no tienes cuentas activas' : 'Repartido en ' . $numCuentas . ($numCuentas === 1 ? ' cuenta' : ' cuentas') ?></span>
        </div>
        <div class="balance__month">
            <div class="balance__month-head"><span><?= escape($mesCorto) ?></span><span>Ingresos frente a gastos</span></div>
            <?= Ui::splitBar([['label' => 'Ingresos', 'value' => Dinero::aCentavos($ingresos)], ['label' => 'Gastos', 'value' => Dinero::aCentavos($gastos)]], 'Ingresos y gastos del mes') ?>
            <dl class="balance__stats">
                <div class="balance__stat"><dt><i class="balance__dot balance__dot--in"></i>Ingresos</dt><dd><?= escape(Dinero::formatear($ingresos)) ?></dd></div>
                <div class="balance__stat"><dt><i class="balance__dot balance__dot--out"></i>Gastos</dt><dd><?= escape(Dinero::formatear($gastos)) ?></dd></div>
                <div class="balance__stat"><dt>Balance del mes</dt><dd><?= Ui::money($balance, $balanceTono) ?></dd></div>
            </dl>
        </div>
    </section>

    <div class="grid-2">
        <?= Ui::cardOpen(['title' => 'Gastos por categoría', 'action' => Ui::button('Ver reportes', ['href' => '/reportes.php', 'variant' => 'ghost', 'size' => 'sm'])]) ?>
            <?php if ($slices === []): ?>
                <p class="muted">Todavía no registras gastos este mes. Cuando lo hagas, verás aquí en qué se va tu dinero.</p>
            <?php else: ?>
                <?= Ui::donut($slices, 'Gráfico circular de gastos por categoría', Dinero::formatear($categorias['total']), 'en gastos') ?>
            <?php endif; ?>
        <?= Ui::cardClose() ?>

        <?= Ui::cardOpen(['title' => 'Últimos movimientos', 'action' => Ui::button('Ver todos', ['href' => '/movimientos.php', 'variant' => 'ghost', 'size' => 'sm'])]) ?>
            <?php if ($ultimos === []): ?>
                <p class="muted">Aún no has registrado movimientos.</p>
                <?php if ($puedeEscribir): ?><p><?= Ui::button($numCuentas === 0 ? 'Crear mi primera cuenta' : 'Registrar un movimiento', ['href' => $numCuentas === 0 ? '/cuenta-form.php' : '/movimiento-form.php', 'icon' => 'plus']) ?></p><?php endif; ?>
            <?php endif; ?>
            <?php foreach ($ultimos as $m): ?>
                <?= Ui::listItem($m->descripcion ?? $m->categoriaNombre, $m->categoriaNombre . ' · ' . Fecha::diaMes($m->fecha), Ui::money($m->monto, $m->esIngreso() ? 'in' : 'out')) ?>
            <?php endforeach; ?>
        <?= Ui::cardClose() ?>
    </div>

    <?= Ui::cardOpen(['title' => 'Presupuestos del mes', 'action' => Ui::button('Ver presupuestos', ['href' => '/presupuestos.php', 'variant' => 'ghost', 'size' => 'sm'])]) ?>
        <?php if ($presupuestos === []): ?>
            <p class="muted">No tienes presupuestos este mes. Fija un límite por categoría y te avisaremos al acercarte.</p>
            <?php if ($puedeEscribir): ?><p><?= Ui::button('Crear un presupuesto', ['href' => '/presupuesto-form.php', 'icon' => 'plus']) ?></p><?php endif; ?>
        <?php endif; ?>
        <?php foreach (array_slice($presupuestos, 0, 4) as $p): ?>
            <?php $pr = $p->progreso(); ?>
            <div class="budget-line">
                <div class="budget-line__head"><span><?= escape($p->categoriaNombre) ?></span><?= Ui::badge($pr['percent'] . ' %', $pr['tone'] === 'over' ? 'danger' : ($pr['tone'] === 'warn' ? 'warn' : 'neutral')) ?></div>
                <?= Ui::progress($pr['percent'], 'Presupuesto de ' . $p->categoriaNombre, $pr['tone']) ?>
            </div>
        <?php endforeach; ?>
    <?= Ui::cardClose() ?>
</div>
<?php if ($puedeEscribir): ?><?= Ui::fab('/movimiento-form.php', 'Nuevo movimiento') ?><?php endif; ?>
