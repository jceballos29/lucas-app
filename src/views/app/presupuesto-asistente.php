<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\Support\Dinero;
use App\Support\Mes;
use App\View\Ui;

/**
 * Asistente guiado de presupuestos con la regla 50/30/20 (Fase 12).
 *
 * @var int $mes
 * @var int $anio
 * @var string $ingresoCampo
 * @var array{
 *   ingreso: string,
 *   necesidades: string,
 *   estiloVida: string,
 *   ahorroDeudas: string,
 *   sugerencias: list<array{categoria: Categoria, grupo: string, monto: string}>
 * } $distribucion
 * @var array<string, string> $errors
 */
$volver = '/presupuestos.php?' . http_build_query(['mes' => $mes, 'anio' => $anio]);
$etiquetaMes = Mes::etiqueta($mes, $anio);
?>
<?= Ui::pageHead('Asistente 50/30/20 · ' . $etiquetaMes, 'Distribuye tu ingreso mensual en 50 % necesidades básicas, 30 % estilo de vida y 20 % ahorro o pago de deudas') ?>

<?php if (isset($errors['general'])): ?><?= Ui::notice($errors['general'], 'error') ?><?php endif; ?>

<div class="stack stack--lg">
    <?= Ui::cardOpen(['title' => '1. Define tu ingreso mensual base', 'subtitle' => 'Lucas calcula automáticamente el 50 %, 30 % y 20 % sobre este valor']) ?>
        <form class="search" action="/presupuesto-asistente.php" method="get">
            <input type="hidden" name="mes" value="<?= (int) $mes ?>">
            <input type="hidden" name="anio" value="<?= (int) $anio ?>">
            <div class="search__field">
                <?= Ui::field([
                    'id' => 'ingreso',
                    'name' => 'ingreso',
                    'label' => 'Ingreso mensual disponible',
                    'value' => $ingresoCampo,
                    'placeholder' => '$ 0',
                    'flush' => true,
                    'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true],
                ]) ?>
            </div>
            <?= Ui::button('Recalcular 50/30/20', ['type' => 'submit', 'variant' => 'dark']) ?>
        </form>

        <div class="kpis">
            <?= Ui::stat('50 % Necesidades básicas', Dinero::formatear($distribucion['necesidades']), ['icon' => 'shield', 'hint' => 'Vivienda, comida, servicios, salud y transporte']) ?>
            <?= Ui::stat('30 % Estilo de vida', Dinero::formatear($distribucion['estiloVida']), ['icon' => 'pie', 'hint' => 'Entretenimiento, educación y gustos personales']) ?>
            <?= Ui::stat('20 % Ahorro y deudas', Dinero::formatear($distribucion['ahorroDeudas']), ['icon' => 'flag', 'tone' => 'brand', 'hint' => 'Para tus Metas de ahorro o abono extra a Deudas']) ?>
            <?= Ui::stat('Ingreso base', Dinero::formatear($distribucion['ingreso']), ['icon' => 'wallet', 'hint' => '100 % distribuido para ' . $etiquetaMes]) ?>
        </div>
    <?= Ui::cardClose() ?>

    <?= Ui::cardOpen(['title' => '2. Revisa y aplica los límites por categoría', 'subtitle' => 'Puedes ajustar cualquier monto antes de guardar los presupuestos de ' . $etiquetaMes]) ?>
        <form action="/presupuesto-asistente.php" method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="mes" value="<?= (int) $mes ?>">
            <input type="hidden" name="anio" value="<?= (int) $anio ?>">

            <div class="form-grid">
                <?php foreach ($distribucion['sugerencias'] as $item): ?>
                    <?php
                    /** @var Categoria $cat */
                    $cat = $item['categoria'];
                    $esNec = $item['grupo'] === 'necesidades';
                    ?>
                    <?= Ui::field([
                        'id' => 'lim-' . $cat->id,
                        'name' => 'limites[' . $cat->id . ']',
                        'label' => $cat->nombre . ' (' . ($esNec ? '50 % Necesidad' : '30 % Estilo de vida') . ')',
                        'value' => Dinero::paraCampo($item['monto']),
                        'placeholder' => '$ 0',
                        'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true],
                    ]) ?>
                <?php endforeach; ?>
            </div>

            <div class="form-actions">
                <?= Ui::button('Aplicar presupuestos a ' . $etiquetaMes, ['type' => 'submit', 'icon' => 'check']) ?>
                <?= Ui::button('Cancelar', ['href' => $volver, 'variant' => 'secondary']) ?>
            </div>
        </form>
    <?= Ui::cardClose() ?>
</div>
