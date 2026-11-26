<?php

declare(strict_types=1);

use App\Domain\Cuenta;
use App\Domain\Transferencia;
use App\Support\Dinero;
use App\View\Ui;

/**
 * Alta o edición de una transferencia entre cuentas propias (Fase 8).
 *
 * @var Transferencia|null $transferencia La que se edita (null = nueva).
 * @var array{id_cuenta_origen: string, id_cuenta_destino: string, monto: string, fecha: string, descripcion: string} $old
 * @var array<string, string> $errors
 * @var list<Cuenta> $cuentas Cuentas activas (y las actuales de la transferencia si alguna se desactivó).
 */
$editando = $transferencia !== null;
$monedaBase = $monedaBase ?? Dinero::monedaActual();

$opcionesCuenta = [];
$atributosCuenta = [];
foreach ($cuentas as $c) {
    $monedaC = $c->monedaEfectiva($monedaBase);
    $opcionesCuenta[(string) $c->id] = $c->nombre . ($c->activa ? '' : ' (inactiva)') . ' · ' . Dinero::formatearEn($c->saldo, $monedaC);
    $atributosCuenta[(string) $c->id] = ['data-moneda' => $monedaC];
}
$tieneErrorMulti = isset($errors['monto_destino']) || isset($errors['tasa_cambio'])
    || ($old['monto_destino'] ?? '') !== '' || ($old['tasa_cambio'] ?? '') !== '';
?>
<?= Ui::pageHead($editando ? 'Editar transferencia' : 'Nueva transferencia', $editando ? 'Modifica las cuentas, el monto o la fecha' : 'Mueve dinero de una cuenta a otra o abona a tu tarjeta de crédito') ?>

<?php if (count($opcionesCuenta) < 2): ?>
    <?= Ui::cardOpen(['class' => 'form-card']) ?>
        <p>Para hacer una transferencia necesitas al menos dos cuentas activas.</p>
        <p><?= Ui::button('Crear una cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']) ?></p>
    <?= Ui::cardClose() ?>
<?php else: ?>
<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <form action="/transferencia-form.php" method="post" novalidate>
        <?= csrf_field() ?>
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $transferencia->id ?>"><?php endif; ?>
        <div class="form-grid">
            <?= Ui::select(['id' => 'cuenta_origen', 'name' => 'id_cuenta_origen', 'label' => 'Desde la cuenta (origen)', 'blank' => 'Elige la cuenta de origen', 'options' => $opcionesCuenta, 'optionAttrs' => $atributosCuenta, 'selected' => $old['id_cuenta_origen'], 'error' => $errors['id_cuenta_origen'] ?? null]) ?>
            <?= Ui::select(['id' => 'cuenta_destino', 'name' => 'id_cuenta_destino', 'label' => 'Hacia la cuenta (destino)', 'blank' => 'Elige la cuenta de destino', 'options' => $opcionesCuenta, 'optionAttrs' => $atributosCuenta, 'selected' => $old['id_cuenta_destino'], 'error' => $errors['id_cuenta_destino'] ?? null]) ?>
        </div>
        <?= Ui::field(['id' => 'monto', 'label' => 'Monto a transferir (en moneda de origen)', 'size' => 'lg', 'value' => $old['monto'], 'placeholder' => '$ 0', 'required' => true, 'hint' => 'Se descuenta de la cuenta de origen y se suma a la de destino. No altera tus ingresos ni gastos del mes.', 'error' => $errors['monto'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true]]) ?>
        <div id="campos-multimoneda"<?= $tieneErrorMulti ? ' data-keep-open="1"' : '' ?>>
            <?= Ui::notice('Transferencia multimoneda: escribe el monto exacto que llega a la cuenta destino o la tasa aplicada. Si los dejas vacíos, se usará tu tasa de referencia guardada en Cuentas.', 'info') ?>
            <div class="form-grid mt-4">
                <?= Ui::field([
                    'id' => 'monto_destino',
                    'label' => 'Monto recibido en destino (opcional)',
                    'value' => $old['monto_destino'] ?? '',
                    'placeholder' => 'Ej.: 420.000',
                    'hint' => 'Tiene prioridad sobre la tasa si lo llenas.',
                    'error' => $errors['monto_destino'] ?? null,
                    'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true],
                ]) ?>
                <?= Ui::field([
                    'id' => 'tasa_cambio',
                    'label' => 'O tasa aplicada (1 origen = X destino)',
                    'value' => $old['tasa_cambio'] ?? '',
                    'placeholder' => 'Ej.: 4.200 o 0,000238',
                    'hint' => 'Opcional si ya llenaste monto destino o tienes tasa guardada.',
                    'error' => $errors['tasa_cambio'] ?? null,
                    'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off'],
                ]) ?>
            </div>
        </div>
        <?= Ui::field(['id' => 'fecha', 'label' => 'Fecha', 'type' => 'date', 'value' => $old['fecha'], 'required' => true, 'error' => $errors['fecha'] ?? null]) ?>
        <?= Ui::field(['id' => 'descripcion', 'label' => 'Descripción', 'value' => $old['descripcion'], 'placeholder' => 'Ej.: Retiro en cajero / Pago cuota tarjeta Visa', 'hint' => 'Opcional. Hasta 150 caracteres.', 'error' => $errors['descripcion'] ?? null, 'attrs' => ['maxlength' => '150']]) ?>
        <div class="form-actions">
            <?= Ui::button($editando ? 'Guardar cambios' : 'Guardar transferencia', ['type' => 'submit']) ?>
            <?= Ui::button('Cancelar', ['href' => '/transferencias.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
<?php endif; ?>
