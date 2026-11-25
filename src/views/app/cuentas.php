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
        <?= Ui::cardOpen(['tag' => 'article', 'class' => 'account' . ($c->activa ? '' : ' account--off')]) ?>
            <div class="account__head">
                <span class="account__icon"><?= Ui::icon($c->icono()) ?></span>
                <span class="account__name"><?= escape($c->nombre) ?></span>
                <?= Ui::badge($c->activa ? $c->tipoTexto() : 'Inactiva', $c->activa ? 'neutral' : 'outline') ?>
            </div>
            <div class="account__balance"><small>Saldo actual</small><strong><?= escape(Dinero::formatear($c->saldo)) ?></strong></div>
            <?php if ($puedeEscribir): ?>
            <div class="account__actions">
                <?php if ($c->activa && $c->tipo === 'tarjeta_credito' && Dinero::aCentavos($c->saldo) < 0): ?>
                    <?= Ui::button('Abonar', ['href' => '/transferencia-form.php?destino=' . $c->id, 'variant' => 'primary', 'size' => 'sm', 'icon' => 'transfer', 'attrs' => ['aria-label' => 'Abonar a ' . $c->nombre]]) ?>
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
