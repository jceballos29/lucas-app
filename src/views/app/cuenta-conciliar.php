<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\Domain\Cuenta;
use App\Support\Dinero;
use App\View\Ui;

/**
 * Conciliación rápida del saldo de una cuenta (Fase 13).
 *
 * @var Cuenta $cuenta
 * @var list<Categoria> $categorias
 * @var array{saldo_real: string, id_categoria_gasto: string, id_categoria_ingreso: string, fecha: string, descripcion: string} $old
 * @var array<string, string> $errors
 */
$esTarjeta = $cuenta->tipo === 'tarjeta_credito';
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
<?= Ui::pageHead('Conciliar saldo de «' . $cuenta->nombre . '»', 'Si olvidaste anotar algún movimiento pequeño, escribe tu saldo real de hoy y Lucas creará un movimiento de ajuste por la diferencia') ?>

<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <div class="goal__body">
        <div class="goal__amount">
            <small>Saldo actual calculado en Lucas:</small> <?= escape(Dinero::formatearEn($cuenta->saldo, $cuenta->moneda)) ?>
        </div>
    </div>
<?= Ui::cardClose() ?>

<?= Ui::cardOpen(['class' => 'form-card', 'title' => '¿Cuánto dinero real tienes hoy en esta cuenta?']) ?>
    <form action="/cuenta-conciliar.php" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $cuenta->id ?>">
        <?= Ui::field([
            'id' => 'saldo_real',
            'label' => 'Saldo real hoy en tu banco o billetera',
            'size' => 'lg',
            'value' => $old['saldo_real'],
            'placeholder' => '$ 0',
            'required' => true,
            'hint' => $esTarjeta
                ? 'En una tarjeta de crédito escribe tu deuda actual con signo menos (p. ej. -450.000) o 0 si está al día.'
                : 'Si el saldo real es menor al de Lucas, se registrará un gasto por la diferencia; si es mayor, un ingreso.',
            'error' => $errors['saldo_real'] ?? null,
            'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => $esTarjeta ? 'signed' : true],
        ]) ?>
        <div class="form-grid">
            <?= Ui::select(['id' => 'cat_gasto', 'name' => 'id_categoria_gasto', 'label' => 'Categoría si falta dinero (gasto)', 'options' => $catsGasto, 'selected' => $old['id_categoria_gasto']]) ?>
            <?= Ui::select(['id' => 'cat_ingreso', 'name' => 'id_categoria_ingreso', 'label' => 'Categoría si sobra dinero (ingreso)', 'options' => $catsIngreso, 'selected' => $old['id_categoria_ingreso']]) ?>
        </div>
        <div class="form-grid">
            <?= Ui::field(['id' => 'fecha', 'label' => 'Fecha del ajuste', 'type' => 'date', 'value' => $old['fecha'], 'required' => true, 'error' => $errors['fecha'] ?? null]) ?>
            <?= Ui::field(['id' => 'descripcion', 'label' => 'Descripción del movimiento', 'value' => $old['descripcion'], 'required' => true, 'attrs' => ['maxlength' => '150']]) ?>
        </div>
        <div class="form-actions">
            <?= Ui::button('Conciliar y ajustar saldo', ['type' => 'submit', 'icon' => 'check']) ?>
            <?= Ui::button('Cancelar', ['href' => '/cuentas.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
