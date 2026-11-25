<?php

declare(strict_types=1);

use App\Domain\Transferencia;
use App\Support\Dinero;
use App\View\Fecha;
use App\View\Html;
use App\View\Ui;

/**
 * Listado de transferencias entre cuentas propias y abonos a tarjetas de crédito (Fase 8).
 *
 * @var list<Transferencia> $rows
 * @var int $total
 * @var string $montoTotal
 * @var int $pagina
 * @var int $paginas
 * @var int $numCuentasActivas
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$enEspacioCompartido = is_array($currentUser ?? null) && ($currentUser['espacio_id'] ?? null) !== null;
$puedeEscribir = \App\Espacios\ReglasEspacio::puedeEscribir($currentUser['espacio_rol'] ?? null);

$rows = array_map(static function (Transferencia $t) use ($pagina, $enEspacioCompartido, $puedeEscribir): array {
    $etiqueta = $t->cuentaOrigenNombre . ' → ' . $t->cuentaDestinoNombre;
    $autorSub = $enEspacioCompartido && $t->autorNombre !== ''
        ? '<span class="cell-sub">Por ' . escape($t->autorNombre) . '</span>'
        : '';

    return [
        'fecha' => Fecha::simple($t->fecha),
        'origen' => $t->cuentaOrigenNombre,
        'destino' => $t->cuentaDestinoNombre,
        'descripcion' => $t->descripcion !== null
            ? new Html('<span class="cell-title">' . escape($t->descripcion) . '</span>' . $autorSub)
            : new Html('<span class="muted-note">Transferencia entre cuentas</span>' . $autorSub),
        'monto' => Ui::money($t->monto),
        'acciones' => $puedeEscribir ? Ui::actions([
            Ui::iconButton('edit', 'Editar transferencia ' . $etiqueta, ['href' => '/transferencia-form.php?id=' . $t->id]),
            Ui::postForm('/transferencias.php', ['id' => $t->id, 'accion' => 'eliminar', 'pagina' => $pagina], Ui::iconButton('trash', 'Eliminar transferencia ' . $etiqueta, [
                'type' => 'submit',
                'tone' => 'danger',
                'attrs' => [
                    'data-confirm' => '¿Eliminar la transferencia de ' . Dinero::formatear($t->monto) . ' de «' . $t->cuentaOrigenNombre . '» a «' . $t->cuentaDestinoNombre . '»? El dinero volverá a la cuenta de origen.',
                    'data-confirm-ok' => 'Eliminar',
                ],
            ])),
        ]) : new Html('<span class="muted-note">Solo lectura</span>'),
    ];
}, $rows);
?>
<?= Ui::pageHead('Transferencias', 'Mueve dinero entre tus cuentas o paga tu tarjeta sin alterar tus ingresos ni gastos', $puedeEscribir ? Ui::button('Nueva transferencia', ['href' => '/transferencia-form.php', 'icon' => 'plus']) : null) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<?php if ($total === 0): ?>
    <?= Ui::cardOpen() ?>
        <?php if ($numCuentasActivas < 2): ?>
            <p>Para transferir dinero necesitas al menos <strong>dos cuentas activas</strong> (por ejemplo, una cuenta bancaria y efectivo, o una cuenta de ahorros y una tarjeta de crédito).</p>
            <p><?= Ui::button('Ir a mis cuentas', ['href' => '/cuentas.php', 'icon' => 'wallet']) ?></p>
        <?php else: ?>
            <p>Aún no has registrado transferencias entre tus cuentas. Úsalas cuando retires efectivo del cajero, pases dinero a una cuenta de ahorros o pagues la cuota de tu tarjeta de crédito.</p>
            <p><?= Ui::button('Registrar una transferencia', ['href' => '/transferencia-form.php', 'icon' => 'plus']) ?></p>
        <?php endif; ?>
    <?= Ui::cardClose() ?>
<?php else: ?>
    <p class="summary" aria-live="polite">
        <span><?= number_format($total, 0, ',', '.') ?> <?= $total === 1 ? 'transferencia' : 'transferencias' ?></span>
        <span>Total movilizado <?= Ui::money($montoTotal) ?></span>
    </p>

    <?= Ui::table([
        'caption' => 'Transferencias entre cuentas',
        'columns' => [
            ['key' => 'fecha', 'label' => 'Fecha'],
            ['key' => 'origen', 'label' => 'Desde (origen)'],
            ['key' => 'destino', 'label' => 'Hacia (destino)'],
            ['key' => 'descripcion', 'label' => 'Descripción'],
            ['key' => 'monto', 'label' => 'Monto', 'align' => 'end'],
            ['key' => 'acciones', 'label' => 'Acciones', 'kind' => 'actions'],
        ],
        'rows' => $rows,
    ]) ?>

    <?php if ($paginas > 1): ?>
        <div class="toolbar">
            <?= Ui::pagination($pagina, $paginas, '?pagina=%d') ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?= Ui::fab('/transferencia-form.php', 'Nueva transferencia') ?>
