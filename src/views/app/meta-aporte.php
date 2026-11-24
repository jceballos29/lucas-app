<?php

declare(strict_types=1);

use App\Domain\Cuenta;
use App\Domain\Meta;
use App\Support\Dinero;
use App\View\Fecha;
use App\View\Ui;

/**
 * Aporte a una meta desde una cuenta (RF-19) y su historial.
 *
 * @var Meta $meta
 * @var list<Cuenta> $cuentas Cuentas activas del usuario (y la elegida en el formulario, aunque ya esté inactiva).
 * @var list<array{id: int, fecha: string, monto: string, cuenta: string}> $historial
 * @var array{id_cuenta: string, monto: string, fecha: string} $old
 * @var array<string, string> $errors
 */
$pr = $meta->progreso();
$opciones = [];
foreach ($cuentas as $c) {
    $opciones[(string) $c->id] = $c->nombre . ($c->activa ? '' : ' (inactiva)') . ' · ' . Dinero::formatear($c->saldo);
}
$filas = array_map(static fn (array $a): array => [
    'fecha' => Fecha::simple($a['fecha']),
    'cuenta' => $a['cuenta'],
    'monto' => Ui::money($a['monto'], 'in'),
], $historial);
?>
<?= Ui::pageHead('Aporte a «' . $meta->nombre . '»', 'Aparta dinero de una de tus cuentas para esta meta') ?>

<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <div class="goal__body">
        <div class="goal__amount"><?= escape(Dinero::formatear($meta->ahorrado)) ?> <small>de <?= escape(Dinero::formatear($meta->montoObjetivo)) ?></small></div>
        <?= Ui::progress($pr['percent'], 'Avance de la meta ' . $meta->nombre, 'ok') ?>
        <div class="goal__foot"><span>Faltan <?= escape(Dinero::formatear($pr['faltante'])) ?></span><b><?= $pr['percent'] ?> %</b></div>
    </div>
<?= Ui::cardClose() ?>

<?php if ($opciones === []): ?>
    <?= Ui::cardOpen(['class' => 'form-card']) ?>
        <p>Para aportar necesitas al menos una cuenta activa.</p>
        <p><?= Ui::button('Crear una cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']) ?></p>
    <?= Ui::cardClose() ?>
<?php else: ?>
<?= Ui::cardOpen(['class' => 'form-card', 'title' => 'Nuevo aporte']) ?>
    <form action="/meta-aporte.php" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $meta->id ?>">
        <?= Ui::select(['id' => 'cuenta', 'name' => 'id_cuenta', 'label' => 'Cuenta de donde sale el dinero', 'blank' => 'Elige una cuenta', 'options' => $opciones, 'selected' => $old['id_cuenta'], 'error' => $errors['id_cuenta'] ?? null]) ?>
        <?= Ui::field(['id' => 'monto', 'label' => 'Monto del aporte', 'size' => 'lg', 'value' => $old['monto'], 'placeholder' => '$ 0', 'required' => true, 'error' => $errors['monto'] ?? null, 'hint' => 'No puede superar el saldo de la cuenta.', 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true]]) ?>
        <?= Ui::field(['id' => 'fecha', 'label' => 'Fecha', 'type' => 'date', 'value' => $old['fecha'], 'required' => true, 'error' => $errors['fecha'] ?? null]) ?>
        <div class="form-actions">
            <?= Ui::button('Guardar aporte', ['type' => 'submit']) ?>
            <?= Ui::button('Volver a mis metas', ['href' => '/metas.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
<?php endif; ?>

<?php if ($filas !== []): ?>
    <h2 class="section-title">Aportes anteriores</h2>
    <?= Ui::table([
        'caption' => 'Aportes a la meta ' . $meta->nombre,
        'columns' => [
            ['key' => 'fecha', 'label' => 'Fecha'],
            ['key' => 'cuenta', 'label' => 'Cuenta'],
            ['key' => 'monto', 'label' => 'Monto', 'align' => 'end'],
        ],
        'rows' => $filas,
    ]) ?>
<?php endif; ?>
