<?php

declare(strict_types=1);

use App\Domain\Cuenta;
use App\View\Ui;

/**
 * Alta o edición de una cuenta (RF-05, RF-06).
 *
 * @var Cuenta|null $cuenta La cuenta que se edita (null = cuenta nueva).
 * @var array{nombre: string, tipo: string, saldo_inicial: string} $old Valores del formulario.
 * @var array<string, string> $errors Error por campo.
 */
$editando = $cuenta !== null;
?>
<?= Ui::pageHead($editando ? 'Editar cuenta' : 'Nueva cuenta', $editando ? 'Cambia el nombre, el tipo o el saldo inicial' : 'Dónde guardas tu dinero') ?>

<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <form action="/cuenta-form.php" method="post" novalidate>
        <?= csrf_field() ?>
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $cuenta->id ?>"><?php endif; ?>
        <?= Ui::field(['id' => 'nombre', 'label' => 'Nombre', 'value' => $old['nombre'], 'placeholder' => 'Ej.: Bancolombia ahorros', 'required' => true, 'error' => $errors['nombre'] ?? null, 'attrs' => ['maxlength' => '60']]) ?>
        <?= Ui::select(['id' => 'tipo', 'label' => 'Tipo', 'options' => Cuenta::TIPOS, 'selected' => $old['tipo'], 'error' => $errors['tipo'] ?? null]) ?>
        <?= Ui::field(['id' => 'saldo_inicial', 'label' => 'Saldo inicial', 'size' => 'lg', 'value' => $old['saldo_inicial'], 'placeholder' => '$ 0', 'hint' => 'Lo que tienes hoy en esta cuenta. Opcional. Una tarjeta de crédito puede empezar en negativo (lo que debes).', 'error' => $errors['saldo_inicial'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => 'signed']]) ?>
        <div id="campos-tarjeta">
            <div class="form-grid">
                <?= Ui::field([
                    'id' => 'dia_corte',
                    'label' => 'Día de corte del extracto (1 a 31)',
                    'type' => 'number',
                    'value' => $old['dia_corte'] ?? '',
                    'placeholder' => 'Ej.: 15',
                    'hint' => 'Solo para tarjeta de crédito (opcional).',
                    'error' => $errors['dia_corte'] ?? null,
                    'attrs' => ['min' => '1', 'max' => '31', 'inputmode' => 'numeric'],
                ]) ?>
                <?= Ui::field([
                    'id' => 'dia_pago_limite',
                    'label' => 'Día límite de pago (1 a 31)',
                    'type' => 'number',
                    'value' => $old['dia_pago_limite'] ?? '',
                    'placeholder' => 'Ej.: 28',
                    'hint' => 'Para avisarte antes del vencimiento (opcional).',
                    'error' => $errors['dia_pago_limite'] ?? null,
                    'attrs' => ['min' => '1', 'max' => '31', 'inputmode' => 'numeric'],
                ]) ?>
            </div>
            <?= Ui::field([
                'id' => 'tasa_mensual',
                'label' => 'Tasa de interés mensual de la tarjeta (% M.V.)',
                'value' => $old['tasa_mensual'] ?? '',
                'placeholder' => 'Ej.: 2,15 o 1,95',
                'hint' => 'Se usa para calcular los intereses en compras diferidas a más de 1 cuota cuando eliges «Con intereses».',
                'error' => $errors['tasa_mensual'] ?? null,
                'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off'],
            ]) ?>
        </div>
        <div class="form-actions">
            <?= Ui::button($editando ? 'Guardar cambios' : 'Crear cuenta', ['type' => 'submit']) ?>
            <?= Ui::button('Cancelar', ['href' => '/cuentas.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
