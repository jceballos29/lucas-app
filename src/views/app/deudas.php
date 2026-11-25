<?php

declare(strict_types=1);

use App\Domain\Deuda;
use App\Support\Dinero;
use App\View\Html;
use App\View\Ui;

/**
 * Listado de Deudas, cálculo de intereses y comparador de estrategias Bola de Nieve vs. Avalancha (Fase 11).
 *
 * @var list<Deuda> $deudas
 * @var array{
 *   deudaTotal: string,
 *   cuotasMinimasTotal: string,
 *   interesMensualTotal: string,
 *   minimos: array{meses: int, intereses: string, totalPagado: string, orden: list<string>},
 *   bolaNieve: array{meses: int, intereses: string, totalPagado: string, ahorroIntereses: string, mesesAhorrados: int, orden: list<string>, primeraDeuda: string|null},
 *   avalancha: array{meses: int, intereses: string, totalPagado: string, ahorroIntereses: string, mesesAhorrados: int, orden: list<string>, primeraDeuda: string|null}
 * } $plan
 * @var string $extraCampo
 * @var string $extraMonto
 * @var array{tone: string, message: string}|null $flash
 * @var array{espacio_rol?: string|null}|null $currentUser
 */
$flash = $flash ?? null;
$puedeEscribir = \App\Espacios\ReglasEspacio::puedeEscribir($currentUser['espacio_rol'] ?? null);
$activas = array_values(array_filter($deudas, static fn (Deuda $d): bool => $d->activa() && Dinero::aCentavos($d->saldoCapital()) > 0));
?>
<?= Ui::pageHead(
    'Deudas y plan de salida',
    'Controla tus préstamos, conoce cuánto pagas en intereses y compáralos con los métodos Bola de Nieve y Avalancha',
    $puedeEscribir ? Ui::button('Nueva deuda', ['href' => '/deuda-form.php', 'icon' => 'plus']) : null
) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<?php if ($deudas === []): ?>
    <?= Ui::cardOpen() ?>
        <p>Aún no has registrado préstamos ni deudas. Registra aquí tus créditos (vehículo, libre inversión, hipoteca, préstamos familiares...) para hacer seguimiento del capital amortizado, calcular los intereses reales de cada cuota y descubrir qué deuda te conviene atacar primero.</p>
        <?php if ($puedeEscribir): ?><p><?= Ui::button('Registrar mi primera deuda', ['href' => '/deuda-form.php', 'icon' => 'plus']) ?></p><?php endif; ?>
    <?= Ui::cardClose() ?>
<?php else: ?>
<div class="stack stack--lg">
    <div class="kpis">
        <?= Ui::stat('Deuda total de capital', Dinero::formatear($plan['deudaTotal']), ['icon' => 'trend-down', 'hint' => count($activas) . (count($activas) === 1 ? ' deuda activa' : ' deudas activas')]) ?>
        <?= Ui::stat('Cuotas mínimas al mes', Dinero::formatear($plan['cuotasMinimasTotal']), ['icon' => 'wallet', 'hint' => 'Compromiso mensual obligatorio']) ?>
        <?= Ui::stat('Interés mensual estimado', Dinero::formatear($plan['interesMensualTotal']), ['icon' => 'pie', 'hint' => 'Costo mensual actual del dinero']) ?>
        <?= Ui::stat('Libre de deudas (Avalancha)', $plan['avalancha']['meses'] > 0 ? $plan['avalancha']['meses'] . ($plan['avalancha']['meses'] === 1 ? ' mes' : ' meses') : '¡Logrado!', [
            'icon' => 'check-circle',
            'tone' => 'brand',
            'hint' => Dinero::aCentavos($plan['avalancha']['ahorroIntereses']) > 0
                ? 'Ahorras ' . Dinero::formatear($plan['avalancha']['ahorroIntereses']) . ' en intereses'
                : 'Reinvirtiendo cuotas liberadas',
        ]) ?>
    </div>

    <?php if (count($activas) >= 1): ?>
        <?= Ui::cardOpen([
            'title' => 'Simulador de estrategias: Bola de Nieve vs. Avalancha',
            'subtitle' => 'Si cuando terminas de pagar una deuda sumas esa cuota a la siguiente (y añades un abono extra mensual si puedes), sales de deudas mucho antes',
        ]) ?>
            <form class="search" action="/deudas.php" method="get">
                <div class="search__field">
                    <?= Ui::field([
                        'id' => 'extra',
                        'name' => 'extra',
                        'label' => '¿Cuánto dinero EXTRA al mes puedes destinar por encima de tus cuotas mínimas?',
                        'value' => $extraCampo,
                        'placeholder' => 'Ej.: 100.000 (opcional)',
                        'flush' => true,
                        'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-money' => true],
                    ]) ?>
                </div>
                <?= Ui::button('Simular plan', ['type' => 'submit', 'variant' => 'dark']) ?>
            </form>

            <div class="grid-2">
                <div class="space-block">
                    <div class="budget__head">
                        <h3 class="space-block__title">Método Bola de Nieve (victorias rápidas)</h3>
                        <?= Ui::badge($plan['bolaNieve']['meses'] . ($plan['bolaNieve']['meses'] === 1 ? ' mes' : ' meses'), 'neutral') ?>
                    </div>
                    <p class="muted">Ataca primero la deuda con <strong>menor saldo pendiente</strong> para eliminarla rápido y ganar motivación. Cuando cae, su cuota entera se suma a la siguiente.</p>
                    <dl class="debt-plan">
                        <div><dt>Deuda a atacar hoy</dt><dd><strong><?= escape((string) ($plan['bolaNieve']['primeraDeuda'] ?? '—')) ?></strong></dd></div>
                        <div><dt>Total intereses del plan</dt><dd><?= escape(Dinero::formatear($plan['bolaNieve']['intereses'])) ?></dd></div>
                        <div><dt>Ahorro frente a solo mínimos</dt><dd><?= Ui::money($plan['bolaNieve']['ahorroIntereses'], Dinero::aCentavos($plan['bolaNieve']['ahorroIntereses']) > 0 ? 'in' : 'plain') ?> (<?= $plan['bolaNieve']['mesesAhorrados'] ?> <?= $plan['bolaNieve']['mesesAhorrados'] === 1 ? 'mes menos' : 'meses menos' ?>)</dd></div>
                        <div><dt>Orden de pago</dt><dd class="muted-note"><?= escape(implode(' → ', $plan['bolaNieve']['orden'])) ?></dd></div>
                    </dl>
                </div>

                <div class="space-block">
                    <div class="budget__head">
                        <h3 class="space-block__title">Método Avalancha (máximo ahorro)</h3>
                        <?= Ui::badge($plan['avalancha']['meses'] . ($plan['avalancha']['meses'] === 1 ? ' mes' : ' meses'), 'brand') ?>
                    </div>
                    <p class="muted">Ataca primero la deuda con <strong>mayor tasa de interés</strong>. Es el camino matemáticamente óptimo: reduce al mínimo el dinero que pierdes en intereses.</p>
                    <dl class="debt-plan">
                        <div><dt>Deuda a atacar hoy</dt><dd><strong><?= escape((string) ($plan['avalancha']['primeraDeuda'] ?? '—')) ?></strong></dd></div>
                        <div><dt>Total intereses del plan</dt><dd><?= escape(Dinero::formatear($plan['avalancha']['intereses'])) ?></dd></div>
                        <div><dt>Ahorro frente a solo mínimos</dt><dd><?= Ui::money($plan['avalancha']['ahorroIntereses'], Dinero::aCentavos($plan['avalancha']['ahorroIntereses']) > 0 ? 'in' : 'plain') ?> (<?= $plan['avalancha']['mesesAhorrados'] ?> <?= $plan['avalancha']['mesesAhorrados'] === 1 ? 'mes menos' : 'meses menos' ?>)</dd></div>
                        <div><dt>Orden de pago</dt><dd class="muted-note"><?= escape(implode(' → ', $plan['avalancha']['orden'])) ?></dd></div>
                    </dl>
                </div>
            </div>
        <?= Ui::cardClose() ?>
    <?php endif; ?>

    <div class="cards-grid">
        <?php foreach ($deudas as $d): ?>
            <?php
            $pr = $d->progreso();
            $pagada = $pr['pagada'];
            $subtitulo = ($d->acreedor !== null ? $d->acreedor . ' · ' : '') . 'Tasa: ' . $d->tasaTexto()
                . ($d->diaPago !== null ? ' · Día ' . $d->diaPago . ' de cada mes' : '');
            ?>
            <?= Ui::cardOpen(['tag' => 'article', 'class' => 'goal' . ($pagada ? ' is-done' : '')]) ?>
                <div class="goal__head">
                    <span class="goal__icon"><?= Ui::icon($pagada ? 'check-circle' : 'coins') ?></span>
                    <div class="goal__title">
                        <span class="goal__name"><?= escape($d->nombre) ?></span>
                        <span class="goal__due"><?= escape($subtitulo) ?></span>
                    </div>
                    <?= $pagada ? Ui::badge('Pagada', 'brand', 'check') : Ui::badge($d->tasaTexto(), 'neutral') ?>
                </div>

                <div class="goal__body">
                    <div class="goal__amount">
                        <?= escape(Dinero::formatear($pr['saldo'])) ?>
                        <small>pendientes de <?= escape(Dinero::formatear($d->montoInicial)) ?></small>
                    </div>
                    <?= Ui::progress($pr['percent'], 'Capital pagado de ' . $d->nombre, 'ok') ?>
                    <div class="goal__foot">
                        <span>Cuota mínima: <b><?= escape(Dinero::formatear($d->cuotaMinima)) ?></b> · Interés mes: <?= escape(Dinero::formatear($d->interesMensual())) ?></span>
                        <b><?= $pr['percent'] ?> % pagado</b>
                    </div>
                </div>

                <?php if ($puedeEscribir): ?>
                <div class="account__actions">
                    <?php if (!$pagada): ?>
                        <?= Ui::button('Registrar pago', ['href' => '/deuda-pago.php?id=' . $d->id, 'icon' => 'plus']) ?>
                    <?php endif; ?>
                    <?= Ui::button('Editar', ['href' => '/deuda-form.php?id=' . $d->id, 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'edit', 'attrs' => ['aria-label' => 'Editar deuda ' . $d->nombre]]) ?>
                    <?php if ($d->numPagos === 0): ?>
                        <?= Ui::postForm('/deudas.php', ['id' => $d->id, 'accion' => 'eliminar'], Ui::button('Eliminar', [
                            'type' => 'submit',
                            'variant' => 'secondary',
                            'size' => 'sm',
                            'attrs' => [
                                'aria-label' => 'Eliminar deuda ' . $d->nombre,
                                'data-confirm' => '¿Eliminar la deuda «' . $d->nombre . '»?',
                                'data-confirm-ok' => 'Eliminar',
                            ],
                        ])) ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?= Ui::cardClose('article') ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php if ($puedeEscribir): ?><?= Ui::fab('/deuda-form.php', 'Nueva deuda') ?><?php endif; ?>
