<?php

declare(strict_types=1);

use App\Domain\Cuenta;
use App\Support\Dinero;
use App\View\Html;
use App\View\Ui;

/**
 * Cuentas del usuario con su saldo actual calculado (RF-05 a RF-07).
 *
 * @var list<Cuenta> $cuentas
 * @var string $total Saldo total de las cuentas activas («1950000.00»).
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$resumenesTarjeta = $resumenesTarjeta ?? [];
$puedeEscribir = \App\Espacios\ReglasEspacio::puedeEscribir($currentUser['espacio_rol'] ?? null);
$acciones = $puedeEscribir ? Html::join([
    Ui::button('Transferir', ['href' => '/transferencia-form.php', 'variant' => 'secondary', 'icon' => 'transfer']),
    Ui::button('Nueva cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']),
]) : null;
?>
<?= Ui::pageHead('Cuentas', 'Dónde tienes tu dinero · Saldo total ' . Dinero::formatear($total), $acciones) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<?php if ($cuentas === []): ?>
    <?= Ui::cardOpen() ?>
        <p>Aún no tienes cuentas. Crea la primera (efectivo, una cuenta bancaria, una billetera...) para empezar a registrar tus movimientos.</p>
        <?php if ($puedeEscribir): ?><p><?= Ui::button('Crear mi primera cuenta', ['href' => '/cuenta-form.php', 'icon' => 'plus']) ?></p><?php endif; ?>
    <?= Ui::cardClose() ?>
<?php else: ?>
<div class="cards-grid cards-grid--3">
    <?php foreach ($cuentas as $c): ?>
        <?php
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
                <?= Ui::badge($c->activa ? $c->tipoTexto() : 'Inactiva', $c->activa ? 'neutral' : 'outline') ?>
            </div>
            <div class="account__balance"><small>Saldo actual</small><strong><?= escape(Dinero::formatear($c->saldo)) ?></strong></div>

            <?php if ($mostrarResumenTarjeta): ?>
            <dl class="card-cycle">
                <?php if (Dinero::aCentavos($resTarjeta['cuotaProximoCorte']) > 0 || $c->diaCorte !== null): ?>
                    <div>
                        <dt>Cuota próximo corte</dt>
                        <dd>
                            <strong><?= escape(Dinero::formatear($resTarjeta['cuotaProximoCorte'])) ?></strong>
                            <?php if (Dinero::aCentavos($resTarjeta['interesProximoCorte'] ?? '0.00') > 0): ?>
                                <span class="cell-sub">Capital <?= escape(Dinero::formatear($resTarjeta['capitalProximoCorte'])) ?> + Interés <?= escape(Dinero::formatear($resTarjeta['interesProximoCorte'])) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (Dinero::aCentavos($resTarjeta['deudaDiferidaFutura']) > 0): ?>
                    <div>
                        <dt>Diferido a meses futuros</dt>
                        <dd>
                            <?= escape(Dinero::formatear($resTarjeta['deudaDiferidaFutura'])) ?>
                            <?php if (Dinero::aCentavos($resTarjeta['interesDiferidoFuturo'] ?? '0.00') > 0): ?>
                                <span class="cell-sub">Incluye <?= escape(Dinero::formatear($resTarjeta['interesDiferidoFuturo'])) ?> de interés</span>
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
                        <dd>Día <?= (int) $c->diaCorte ?><?= $resTarjeta['proximoCorte'] !== null ? ' (' . escape(\App\View\Fecha::diaMes($resTarjeta['proximoCorte']['fecha'])) . ')' : '' ?></dd>
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
<?php if ($puedeEscribir): ?><?= Ui::fab('/movimiento-form.php', 'Nuevo movimiento') ?><?php endif; ?>
