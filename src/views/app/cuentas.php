<?php

declare(strict_types=1);

use App\Domain\Cuenta;
use App\Support\ConversorMoneda;
use App\Support\Dinero;
use App\View\Fecha;
use App\View\Html;
use App\View\Ui;

/**
 * Cuentas del usuario con su saldo actual calculado (RF-05 a RF-07, Fase 18: multimoneda y tasas de cambio).
 *
 * @var list<Cuenta> $cuentas
 * @var string $total Saldo total consolidado de las cuentas activas («1950000.00»).
 * @var array<int, string|null> $porCuentaConsolidado
 * @var list<string> $monedasSinTasa
 * @var list<array{id: int, moneda_origen: string, moneda_destino: string, tasa_micros: int, actualizado_en: string}> $tasas
 * @var string $monedaBase
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$resumenesTarjeta = $resumenesTarjeta ?? [];
$porCuentaConsolidado = $porCuentaConsolidado ?? [];
$monedasSinTasa = $monedasSinTasa ?? [];
$tasas = $tasas ?? [];
$monedaBase = $monedaBase ?? Dinero::monedaActual();
$puedeEscribir = \App\Espacios\ReglasEspacio::puedeEscribir($currentUser['espacio_rol'] ?? null);
$acciones = $puedeEscribir ? Html::join([
    Ui::button('Transferir', ['href' => '/transferencia-form.php', 'variant' => 'secondary', 'icon' => 'transfer']),
    Ui::button('Nueva cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']),
]) : null;
$tieneOtraMoneda = false;
foreach ($cuentas as $cCheck) {
    if ($cCheck->monedaEfectiva($monedaBase) !== $monedaBase) {
        $tieneOtraMoneda = true;
        break;
    }
}
$subtituloTotal = $tieneOtraMoneda
    ? 'Dónde tienes tu dinero · Saldo total consolidado ' . Dinero::formatear($total) . ' (' . $monedaBase . ')'
    : 'Dónde tienes tu dinero · Saldo total ' . Dinero::formatear($total);
?>
<?= Ui::pageHead('Cuentas', $subtituloTotal, $acciones) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<?php if ($monedasSinTasa !== []): ?>
    <?= Ui::notice(new Html('Tienes cuentas activas en <strong>' . escape(implode(', ', $monedasSinTasa)) . '</strong> sin tasa de cambio hacia <strong>' . escape($monedaBase) . '</strong>. <a href="#tasas-cambio">Registra la tasa de referencia abajo</a> para incluirlas en el saldo total consolidado.'), 'warn') ?>
<?php endif; ?>

<?php if ($cuentas === []): ?>
    <?= Ui::cardOpen() ?>
        <p>Aún no tienes cuentas. Crea la primera (efectivo, una cuenta bancaria, una billetera...) para empezar a registrar tus movimientos.</p>
        <?php if ($puedeEscribir): ?><p><?= Ui::button('Crear mi primera cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']) ?></p><?php endif; ?>
    <?= Ui::cardClose() ?>
<?php else: ?>
<div class="cards-grid cards-grid--3">
    <?php foreach ($cuentas as $c): ?>
        <?php
        $monedaCuenta = $c->monedaEfectiva($monedaBase);
        $esOtraMoneda = $monedaCuenta !== $monedaBase;
        $equivBase = $porCuentaConsolidado[$c->id] ?? null;
        $resTarjeta = $c->tipo === 'tarjeta_credito' ? ($resumenesTarjeta[$c->id] ?? null) : null;
        $mostrarResumenTarjeta = $resTarjeta !== null && (
            $c->diaCorte !== null
            || $c->diaPagoLimite !== null
            || $c->tasaMensualBps > 0
            || Dinero::aCentavos($resTarjeta['cuotaProximoCorte']) > 0
            || Dinero::aCentavos($resTarjeta['deudaDiferidaFutura']) > 0
        );
        ?>
        <?= Ui::cardOpen(['tag' => 'article', 'class' => 'account' . ($c->activa ? '' : ' account--off')]) ?>
            <div class="account__head">
                <span class="account__icon"><?= Ui::icon($c->icono()) ?></span>
                <span class="account__name"><?= escape($c->nombre) ?></span>
                <?php if ($esOtraMoneda): ?><?= Ui::badge($monedaCuenta, 'brand') ?><?php endif; ?>
                <?= Ui::badge($c->activa ? $c->tipoTexto() : 'Inactiva', $c->activa ? 'neutral' : 'outline') ?>
            </div>
            <div class="account__balance">
                <small>Saldo actual<?= $esOtraMoneda ? ' (' . escape($monedaCuenta) . ')' : '' ?></small>
                <strong><?= escape(Dinero::formatearEn($c->saldo, $monedaCuenta)) ?></strong>
                <?php if ($esOtraMoneda): ?>
                    <span class="cell-sub"><?= $equivBase !== null ? '≈ ' . escape(Dinero::formatear($equivBase)) . ' ' . escape($monedaBase) : 'Sin tasa hacia ' . escape($monedaBase) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($mostrarResumenTarjeta): ?>
            <dl class="card-cycle">
                <?php if (Dinero::aCentavos($resTarjeta['cuotaProximoCorte']) > 0 || $c->diaCorte !== null): ?>
                    <div>
                        <dt>Cuota próximo corte</dt>
                        <dd>
                            <strong><?= escape(Dinero::formatearEn($resTarjeta['cuotaProximoCorte'], $monedaCuenta)) ?></strong>
                            <?php if (Dinero::aCentavos($resTarjeta['interesProximoCorte'] ?? '0.00') > 0): ?>
                                <span class="cell-sub">Capital <?= escape(Dinero::formatearEn($resTarjeta['capitalProximoCorte'], $monedaCuenta)) ?> + Interés <?= escape(Dinero::formatearEn($resTarjeta['interesProximoCorte'], $monedaCuenta)) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (Dinero::aCentavos($resTarjeta['deudaDiferidaFutura']) > 0): ?>
                    <div>
                        <dt>Diferido a meses futuros</dt>
                        <dd>
                            <?= escape(Dinero::formatearEn($resTarjeta['deudaDiferidaFutura'], $monedaCuenta)) ?>
                            <?php if (Dinero::aCentavos($resTarjeta['interesDiferidoFuturo'] ?? '0.00') > 0): ?>
                                <span class="cell-sub">Incluye <?= escape(Dinero::formatearEn($resTarjeta['interesDiferidoFuturo'], $monedaCuenta)) ?> de interés</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if ($c->tasaMensualBps > 0): ?>
                    <div>
                        <dt>Tasa mensual</dt>
                        <dd><?= escape($c->tasaTexto()) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($c->diaCorte !== null): ?>
                    <div>
                        <dt>Día de corte</dt>
                        <dd>Día <?= (int) $c->diaCorte ?><?= $resTarjeta['proximoCorte'] !== null ? ' (' . escape(Fecha::diaMes($resTarjeta['proximoCorte']['fecha'])) . ')' : '' ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($c->diaPagoLimite !== null): ?>
                    <div>
                        <dt>Fecha límite de pago</dt>
                        <dd>Día <?= (int) $c->diaPagoLimite ?><?= $resTarjeta['proximoPago'] !== null ? ' (en ' . (int) $resTarjeta['proximoPago']['dias'] . ((int) $resTarjeta['proximoPago']['dias'] === 1 ? ' día' : ' días') . ')' : '' ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
            <?php endif; ?>

            <?php if ($puedeEscribir): ?>
            <div class="account__actions">
                <?php if ($c->activa && $c->tipo === 'tarjeta_credito' && Dinero::aCentavos($c->saldo) < 0): ?>
                    <?= Ui::button('Abonar', ['href' => '/transferencia-form.php?destino=' . $c->id, 'variant' => 'primary', 'size' => 'sm', 'icon' => 'transfer', 'attrs' => ['aria-label' => 'Abonar a ' . $c->nombre]]) ?>
                <?php endif; ?>
                <?php if ($c->activa): ?>
                    <?= Ui::button('Conciliar', ['href' => '/cuenta-conciliar.php?id=' . $c->id, 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'check', 'attrs' => ['aria-label' => 'Conciliar saldo de ' . $c->nombre]]) ?>
                <?php endif; ?>
                <?= Ui::button('Editar', ['href' => '/cuenta-form.php?id=' . $c->id, 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'edit', 'attrs' => ['aria-label' => 'Editar ' . $c->nombre]]) ?>
                <?= Ui::postForm('/cuentas.php', ['id' => $c->id, 'accion' => $c->activa ? 'desactivar' : 'activar'], Ui::button($c->activa ? 'Desactivar' : 'Activar', [
                    'type' => 'submit',
                    'variant' => 'secondary',
                    'size' => 'sm',
                    'attrs' => ['aria-label' => ($c->activa ? 'Desactivar ' : 'Activar ') . $c->nombre] + ($c->activa ? ['data-confirm' => '¿Desactivar «' . $c->nombre . '»? Su historial se conserva, pero ya no podrás usarla ni contará en el saldo total.'] : []),
                ])) ?>
            </div>
            <?php endif; ?>
        <?= Ui::cardClose('article') ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($cuentas !== [] || $tasas !== []): ?>
<?php
$monedaOrigenDef = $monedaBase === 'COP' ? 'USD' : 'COP';
$filasTasas = array_map(static function (array $t) use ($puedeEscribir): array {
    return [
        'par' => new Html('<div>' . Ui::badge($t['moneda_origen'] . ' → ' . $t['moneda_destino'], 'brand') . '</div>'),
        'tasa' => '1 ' . $t['moneda_origen'] . ' = ' . ConversorMoneda::formatearTasa($t['tasa_micros']) . ' ' . $t['moneda_destino'],
        'actualizada' => Fecha::simple(substr($t['actualizado_en'], 0, 10)),
        'acciones' => $puedeEscribir ? Ui::actions([
            Ui::postForm('/cuentas.php', ['accion' => 'eliminar_tasa', 'id_tasa' => $t['id']], Ui::iconButton('trash', 'Eliminar tasa ' . $t['moneda_origen'] . ' a ' . $t['moneda_destino'], [
                'type' => 'submit',
                'tone' => 'danger',
                'attrs' => [
                    'data-confirm' => '¿Eliminar la tasa de cambio ' . $t['moneda_origen'] . ' → ' . $t['moneda_destino'] . '?',
                    'data-confirm-ok' => 'Eliminar',
                ],
            ])),
        ]) : new Html('<span class="muted-note">Solo lectura</span>'),
    ];
}, $tasas);
?>
<div id="tasas-cambio" class="mt-4">
    <?= Ui::cardOpen(['title' => 'Tasas de cambio de referencia', 'subtitle' => 'Convierten tus cuentas, reportes, presupuestos y transferencias multimoneda a tu moneda base (' . $monedaBase . ')']) ?>
        <?php if ($filasTasas !== []): ?>
            <?= Ui::table([
                'caption' => 'Tasas de cambio guardadas',
                'columns' => [
                    ['key' => 'par', 'label' => 'Par'],
                    ['key' => 'tasa', 'label' => 'Equivalencia'],
                    ['key' => 'actualizada', 'label' => 'Actualizada'],
                    ['key' => 'acciones', 'label' => 'Acciones', 'kind' => 'actions'],
                ],
                'rows' => $filasTasas,
            ]) ?>
        <?php else: ?>
            <p class="muted">Aún no has registrado tasas de cambio. Si manejas cuentas en dólares, euros u otra moneda, guarda aquí la tasa de referencia para ver tu patrimonio consolidado.</p>
        <?php endif; ?>

        <?php if ($puedeEscribir): ?>
        <form action="/cuentas.php" method="post" class="mt-4" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="guardar_tasa">
            <div class="form-grid">
                <?= Ui::select(['id' => 'tasa_origen', 'name' => 'moneda_origen', 'label' => '1 unidad de (origen)', 'options' => Dinero::MONEDAS_NOMBRES, 'selected' => $monedaOrigenDef]) ?>
                <?= Ui::select(['id' => 'tasa_destino', 'name' => 'moneda_destino', 'label' => 'Equivale en (destino)', 'options' => Dinero::MONEDAS_NOMBRES, 'selected' => $monedaBase]) ?>
            </div>
            <?= Ui::field([
                'id' => 'tasa_valor',
                'name' => 'tasa',
                'label' => 'Tasa de cambio',
                'placeholder' => 'Ej.: 4.200 o 0,925',
                'hint' => 'Se guarda en precisión exacta. También funciona en sentido inverso automáticamente.',
                'required' => true,
                'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off'],
            ]) ?>
            <div class="form-actions">
                <?= Ui::button('Guardar tasa', ['type' => 'submit', 'variant' => 'secondary', 'icon' => 'check']) ?>
            </div>
        </form>
        <?php endif; ?>
    <?= Ui::cardClose() ?>
</div>
<?php endif; ?>
<?php if ($puedeEscribir): ?><?= Ui::fab('/movimiento-form.php', 'Nuevo movimiento') ?><?php endif; ?>
