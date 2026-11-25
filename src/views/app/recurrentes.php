<?php

declare(strict_types=1);

use App\Domain\Recurrente;
use App\Support\Dinero;
use App\View\Fecha;
use App\View\Html;
use App\View\Ui;

/**
 * Listado de movimientos recurrentes y gastos fijos programados (Fase 9).
 *
 * @var list<Recurrente> $recurrentes
 * @var list<Recurrente> $vencidos
 * @var string $hoy Fecha actual «YYYY-MM-DD».
 * @var string $totalGastosFijosMes Equivalente mensual de los gastos fijos activos.
 * @var string $totalIngresosFijosMes Equivalente mensual de los ingresos fijos activos.
 * @var int $retenidosPorSaldo Automáticos que no pudieron ejecutarse hoy por falta de saldo o cuenta inactiva.
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$puedeEscribir = \App\Espacios\ReglasEspacio::puedeEscribir($currentUser['espacio_rol'] ?? null);

$filas = array_map(static function (Recurrente $r) use ($hoy, $puedeEscribir): array {
    $nombre = $r->nombreVisible();
    $esVencido = $r->vencido($hoy);

    $estadoBadge = !$r->activo
        ? Ui::badge('Pausado', 'outline')
        : ($esVencido
            ? Ui::badge('Pendiente hoy', 'warn')
            : ($r->autoAplicar ? Ui::badge('Automático', 'brand') : Ui::badge('Confirmar', 'neutral')));

    $botones = [];

    if ($puedeEscribir) {
        if ($r->activo) {
            $botones[] = Ui::postForm('/recurrentes.php', ['id' => $r->id, 'accion' => 'aplicar'], Ui::button('Registrar ahora', [
                'type' => 'submit',
                'variant' => $esVencido ? 'primary' : 'secondary',
                'size' => 'sm',
                'icon' => 'check',
                'attrs' => ['aria-label' => 'Registrar ahora ' . $nombre],
            ]));
            if ($esVencido) {
                $botones[] = Ui::postForm('/recurrentes.php', ['id' => $r->id, 'accion' => 'saltar'], Ui::button('Saltar', [
                    'type' => 'submit',
                    'variant' => 'ghost',
                    'size' => 'sm',
                    'attrs' => ['aria-label' => 'Saltar este periodo de ' . $nombre],
                ]));
            }
        }

        $botones[] = Ui::iconButton('edit', 'Editar ' . $nombre, ['href' => '/recurrente-form.php?id=' . $r->id]);
        $botones[] = Ui::postForm('/recurrentes.php', ['id' => $r->id, 'accion' => $r->activo ? 'pausar' : 'activar'], Ui::button($r->activo ? 'Pausar' : 'Activar', [
            'type' => 'submit',
            'variant' => 'ghost',
            'size' => 'sm',
            'attrs' => ['aria-label' => ($r->activo ? 'Pausar ' : 'Activar ') . $nombre],
        ]));
        $botones[] = Ui::postForm('/recurrentes.php', ['id' => $r->id, 'accion' => 'eliminar'], Ui::iconButton('trash', 'Eliminar ' . $nombre, [
            'type' => 'submit',
            'tone' => 'danger',
            'attrs' => [
                'data-confirm' => '¿Eliminar la programación de «' . $nombre . '»? Los movimientos que ya se hayan registrado en el historial no se borrarán.',
                'data-confirm-ok' => 'Eliminar',
            ],
        ]));
    }

    return [
        'concepto' => new Html('<span class="cell-title">' . escape($nombre) . '</span><span class="cell-sub">' . escape($r->categoriaNombre) . '</span>'),
        'cuenta' => $r->cuentaNombre,
        'frecuencia' => $r->frecuenciaTexto(),
        'proxima' => Fecha::simple($r->proximaFecha),
        'modo' => $estadoBadge,
        'monto' => Ui::money($r->monto, $r->esIngreso() ? 'in' : 'out'),
        'acciones' => $puedeEscribir ? Ui::actions($botones) : new Html('<span class="muted-note">Solo lectura</span>'),
    ];
}, $recurrentes);
?>
<?= Ui::pageHead('Gastos fijos y recurrentes', 'Automatiza tu arriendo, servicios, suscripciones o salario para no olvidarlos cada mes', $puedeEscribir ? Ui::button('Nuevo recurrente', ['href' => '/recurrente-form.php', 'icon' => 'plus']) : null) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<?php if ($retenidosPorSaldo > 0): ?>
    <?= Ui::notice('Hay ' . $retenidosPorSaldo . ($retenidosPorSaldo === 1 ? ' gasto recurrente automático que no pudo aplicarse' : ' gastos recurrentes automáticos que no pudieron aplicarse') . ' hoy porque la cuenta no tiene saldo disponible suficiente. Recarga o transfiere saldo a la cuenta y pulsa «Registrar ahora».', 'warn') ?>
<?php elseif ($vencidos !== []): ?>
    <?= Ui::notice('Tienes ' . count($vencidos) . (count($vencidos) === 1 ? ' movimiento recurrente pendiente' : ' movimientos recurrentes pendientes') . ' de confirmar para hoy o fechas anteriores. Pulsa «Registrar ahora» para descontarlo o «Saltar» si este periodo no aplica.', 'warn') ?>
<?php endif; ?>

<?php if ($recurrentes === []): ?>
    <?= Ui::cardOpen() ?>
        <p>Aún no has programado gastos fijos ni ingresos recurrentes. Registra aquí los pagos que se repiten (arriendo, internet, gimnasio, servicios, nómina...) y elige si quieres que Lucas los descuente automáticamente al llegar la fecha o que te avise para confirmarlos con un clic.</p>
        <p><?= Ui::button('Programar mi primer gasto fijo', ['href' => '/recurrente-form.php', 'icon' => 'plus']) ?></p>
    <?= Ui::cardClose() ?>
<?php else: ?>
    <p class="summary" aria-live="polite">
        <span><?= count($recurrentes) ?> <?= count($recurrentes) === 1 ? 'programación' : 'programaciones' ?></span>
        <span>Gastos fijos estimados al mes <?= Ui::money($totalGastosFijosMes, 'out') ?></span>
        <span>Ingresos fijos estimados al mes <?= Ui::money($totalIngresosFijosMes, 'in') ?></span>
    </p>

    <?= Ui::table([
        'caption' => 'Movimientos recurrentes y gastos fijos',
        'columns' => [
            ['key' => 'concepto', 'label' => 'Concepto / Categoría'],
            ['key' => 'cuenta', 'label' => 'Cuenta'],
            ['key' => 'frecuencia', 'label' => 'Frecuencia'],
            ['key' => 'proxima', 'label' => 'Próxima fecha'],
            ['key' => 'modo', 'label' => 'Modo / Estado'],
            ['key' => 'monto', 'label' => 'Monto', 'align' => 'end'],
            ['key' => 'acciones', 'label' => 'Acciones', 'kind' => 'actions'],
        ],
        'rows' => $filas,
    ]) ?>
<?php endif; ?>
<?= Ui::fab('/recurrente-form.php', 'Nuevo movimiento recurrente') ?>
