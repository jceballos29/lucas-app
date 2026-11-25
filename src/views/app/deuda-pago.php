<?php

declare(strict_types=1);

use App\Domain\Cuenta;
use App\Domain\Deuda;
use App\Support\Dinero;
use App\View\Fecha;
use App\View\Ui;

/**
 * Registro de un pago de cuota o abono extraordinario a capital de una deuda, con desglose Capital vs. Intereses (Fase 11).
 *
 * @var Deuda $deuda
 * @var list<Cuenta> $cuentas
 * @var list<array{id: int, fecha: string, monto_total: string, abono_capital: string, pago_interes: string, cuenta: string, descripcion: string|null, autor: string}> $historial
 * @var array{id_cuenta: string, monto_total: string, modo_pago: string, fecha: string, descripcion: string} $old
 * @var array<string, string> $errors
 */
$pr = $deuda->progreso();
$interesMes = $deuda->interesMensual();
$opcionesCuenta = [];
foreach ($cuentas as $c) {
    $opcionesCuenta[(string) $c->id] = $c->nombre . ($c->activa ? '' : ' (inactiva)') . ' · ' . Dinero::formatear($c->saldo);
}
$opcionesModo = [
    'cuota' => 'Pago de cuota mensual (cubre primero el interés del mes: ' . Dinero::formatear($interesMes) . ' y el resto abona a capital)',
    'capital' => 'Abono 100 % extraordinario a capital (todo el monto reduce directamente la deuda)',
];

$filas = array_map(static fn (array $p): array => [
    'fecha' => Fecha::simple($p['fecha']),
    'cuenta' => $p['cuenta'],
    'capital' => Ui::money($p['abono_capital'], 'in'),
    'interes' => Ui::money($p['pago_interes'], Dinero::aCentavos($p['pago_interes']) > 0 ? 'out' : 'plain'),
    'total' => Ui::money($p['monto_total']),
], $historial);
?>
<?= Ui::pageHead('Pago a «' . $deuda->nombre . '»', 'Registra tu cuota mensual o haz un abono extraordinario a capital para ahorrar intereses') ?>

<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <div class="goal__body">
        <div class="goal__amount">
            <?= escape(Dinero::formatear($pr['saldo'])) ?>
            <small>pendientes de <?= escape(Dinero::formatear($deuda->montoInicial)) ?></small>
        </div>
        <?= Ui::progress($pr['percent'], 'Capital pagado de ' . $deuda->nombre, 'ok') ?>
        <div class="goal__foot">
            <span>Tasa: <?= escape($deuda->tasaTexto()) ?> · Interés estimado de este mes: <b><?= escape(Dinero::formatear($interesMes)) ?></b></span>
            <b><?= $pr['percent'] ?> %</b>
        </div>
    </div>
<?= Ui::cardClose() ?>

<?php if ($opcionesCuenta === []): ?>
    <?= Ui::cardOpen(['class' => 'form-card']) ?>
        <p>Para registrar un pago de deuda necesitas al menos una cuenta activa.</p>
        <p><?= Ui::button('Crear una cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']) ?></p>
    <?= Ui::cardClose() ?>
<?php else: ?>
<?= Ui::cardOpen(['class' => 'form-card', 'title' => 'Registrar pago o abono']) ?>
    <form action="/deuda-pago.php" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $deuda->id ?>">
        <?= Ui::select(['id' => 'modo_pago', 'name' => 'modo_pago', 'label' => 'Tipo de pago', 'options' => $opcionesModo, 'selected' => $old['modo_pago'], 'error' => $errors['modo_pago'] ?? null]) ?>
        <?= Ui::select(['id' => 'cuenta', 'name' => 'id_cuenta', 'label' => 'Cuenta de donde sale el dinero', 'blank' => 'Elige una cuenta', 'options' => $opcionesCuenta, 'selected' => $old['id_cuenta'], 'error' => $errors['id_cuenta'] ?? null]) ?>
        <?= Ui::field(['id' => 'monto_total', 'label' => 'Monto total a pagar', 'size' => 'lg', 'value' => $old['monto_total'], 'placeholder' => '$ 0', 'required' => true, 'hint' => 'Cuota mínima configurada: ' . Dinero::formatear($deuda->cuotaMinima) . '. Si pagas más de la cuota mínima, el excedente reduce directamente el capital.', 'error' => $errors['monto_total'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true]]) ?>
        <div class="form-grid">
            <?= Ui::field(['id' => 'fecha', 'label' => 'Fecha del pago', 'type' => 'date', 'value' => $old['fecha'], 'required' => true, 'error' => $errors['fecha'] ?? null]) ?>
            <?= Ui::field(['id' => 'descripcion', 'label' => 'Nota / Comprobante', 'value' => $old['descripcion'], 'placeholder' => 'Ej.: Cuota #4 / Abono extra prima', 'error' => $errors['descripcion'] ?? null, 'attrs' => ['maxlength' => '150']]) ?>
        </div>
        <div class="form-actions">
            <?= Ui::button('Registrar pago', ['type' => 'submit']) ?>
            <?= Ui::button('Volver a mis deudas', ['href' => '/deudas.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
<?php endif; ?>

<?php if ($filas !== []): ?>
    <h2 class="section-title">Historial de pagos y amortización</h2>
    <?= Ui::table([
        'caption' => 'Pagos registrados para ' . $deuda->nombre,
        'columns' => [
            ['key' => 'fecha', 'label' => 'Fecha'],
            ['key' => 'cuenta', 'label' => 'Cuenta'],
            ['key' => 'capital', 'label' => 'Abono a capital', 'align' => 'end'],
            ['key' => 'interes', 'label' => 'Intereses pagados', 'align' => 'end'],
            ['key' => 'total', 'label' => 'Pago total', 'align' => 'end'],
        ],
        'rows' => $filas,
    ]) ?>
<?php endif; ?>
