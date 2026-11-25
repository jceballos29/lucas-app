<?php

declare(strict_types=1);

use App\Domain\Deuda;
use App\View\Ui;

/**
 * Alta o edición de una deuda (Fase 11).
 *
 * @var Deuda|null $deuda
 * @var array{nombre: string, acreedor: string, monto_inicial: string, tasa_mensual: string, cuota_minima: string, dia_pago: string} $old
 * @var array<string, string> $errors
 */
$editando = $deuda !== null;
?>
<?= Ui::pageHead($editando ? 'Editar deuda' : 'Nueva deuda', $editando ? 'Actualiza el monto, la tasa mensual o la cuota mínima' : 'Registra un préstamo o crédito para incluirlo en tu plan Bola de Nieve / Avalancha') ?>

<?= Ui::cardOpen(['class' => 'form-card']) ?>
    <form action="/deuda-form.php" method="post" novalidate>
        <?= csrf_field() ?>
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $deuda->id ?>"><?php endif; ?>
        <?= Ui::field(['id' => 'nombre', 'label' => 'Nombre de la deuda', 'value' => $old['nombre'], 'placeholder' => 'Ej.: Crédito Vehículo, Libre Inversión, Préstamo Educativo', 'required' => true, 'error' => $errors['nombre'] ?? null, 'attrs' => ['maxlength' => '80']]) ?>
        <?= Ui::field(['id' => 'acreedor', 'label' => 'Entidad o acreedor', 'value' => $old['acreedor'], 'placeholder' => 'Ej.: Bancolombia, ICETEX, Cooperativa...', 'hint' => 'Opcional. Hasta 80 caracteres.', 'error' => $errors['acreedor'] ?? null, 'attrs' => ['maxlength' => '80']]) ?>
        <?= Ui::field(['id' => 'monto_inicial', 'label' => 'Capital de la deuda (saldo inicial a amortizar)', 'size' => 'lg', 'value' => $old['monto_inicial'], 'placeholder' => '$ 0', 'required' => true, 'error' => $errors['monto_inicial'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true]]) ?>
        <div class="form-grid">
            <?= Ui::field(['id' => 'cuota_minima', 'label' => 'Cuota mínima mensual', 'value' => $old['cuota_minima'], 'placeholder' => '$ 0', 'required' => true, 'error' => $errors['cuota_minima'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true]]) ?>
            <?= Ui::field(['id' => 'tasa_mensual', 'label' => 'Tasa de interés mensual (% M.V.)', 'value' => $old['tasa_mensual'], 'placeholder' => 'Ej.: 1,85 o 0', 'hint' => 'En porcentaje mensual vencido (0 si no cobra intereses).', 'error' => $errors['tasa_mensual'] ?? null, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']]) ?>
        </div>
        <?= Ui::field(['id' => 'dia_pago', 'label' => 'Día de pago del mes (1 a 31)', 'type' => 'number', 'value' => $old['dia_pago'], 'placeholder' => 'Ej.: 15', 'hint' => 'Opcional.', 'error' => $errors['dia_pago'] ?? null, 'attrs' => ['min' => '1', 'max' => '31', 'inputmode' => 'numeric']]) ?>
        <div class="form-actions">
            <?= Ui::button($editando ? 'Guardar cambios' : 'Guardar deuda', ['type' => 'submit']) ?>
            <?= Ui::button('Cancelar', ['href' => '/deudas.php', 'variant' => 'secondary']) ?>
        </div>
    </form>
<?= Ui::cardClose() ?>
