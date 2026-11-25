<?php

declare(strict_types=1);

use App\Domain\Meta;
use App\Support\Dinero;
use App\View\Fecha;
use App\View\Ui;

/**
 * Metas de ahorro con su avance (RF-18 a RF-20).
 *
 * @var list<Meta> $metas
 * @var string $hoy Hoy (`AAAA-MM-DD`), para marcar las metas vencidas.
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$puedeEscribir = \App\Espacios\ReglasEspacio::puedeEscribir($currentUser['espacio_rol'] ?? null);
?>
<?= Ui::pageHead('Metas de ahorro', 'Sigue el avance de lo que quieres lograr', $puedeEscribir ? Ui::button('Nueva meta', ['href' => '/meta-form.php', 'icon' => 'plus']) : null) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<?php if ($metas === []): ?>
    <?= Ui::cardOpen() ?>
        <p>Aún no tienes metas. Crea una (un viaje, un computador, un fondo de emergencia) y ve apartando dinero de tus cuentas.</p>
        <?php if ($puedeEscribir): ?><p><?= Ui::button('Crear mi primera meta', ['href' => '/meta-form.php', 'icon' => 'plus']) ?></p><?php endif; ?>
    <?= Ui::cardClose() ?>
<?php else: ?>
<div class="cards-grid">
    <?php foreach ($metas as $m): ?>
        <?php
        $pr = $m->progreso();
        $lograda = $m->estado === 'lograda';
        $cancelada = $m->estado === 'cancelada';
        $vencida = $m->vencida($hoy);
        $limite = $m->fechaLimite !== null ? 'Límite: ' . Fecha::simple($m->fechaLimite) : 'Sin fecha límite';
        ?>
        <?= Ui::cardOpen(['tag' => 'article', 'class' => 'goal' . ($lograda ? ' is-done' : '') . ($cancelada ? ' account--off' : '')]) ?>
            <div class="goal__head">
                <span class="goal__icon"><?= Ui::icon($lograda ? 'check-circle' : 'target') ?></span>
                <div class="goal__title"><span class="goal__name"><?= escape($m->nombre) ?></span><span class="goal__due"><?= escape($limite) ?></span></div>
                <?php if ($lograda): ?><?= Ui::badge('Lograda', 'brand', 'check') ?>
                <?php elseif ($cancelada): ?><?= Ui::badge('Cancelada', 'outline') ?>
                <?php elseif ($vencida): ?><?= Ui::badge('Vencida', 'danger', 'alert') ?><?php endif; ?>
            </div>
            <div class="goal__body">
                <div class="goal__amount"><?= escape(Dinero::formatear($m->ahorrado)) ?> <small>de <?= escape(Dinero::formatear($m->montoObjetivo)) ?></small></div>
                <?= Ui::progress($pr['percent'], 'Avance de la meta ' . $m->nombre, 'ok') ?>
                <div class="goal__foot"><span><?= $pr['lograda'] ? 'Objetivo alcanzado' : 'Faltan ' . escape(Dinero::formatear($pr['faltante'])) ?></span><b><?= $pr['percent'] ?> %</b></div>
            </div>
            <?php if ($puedeEscribir): ?>
            <div class="account__actions">
                <?php if ($m->enCurso()): ?>
                    <?= Ui::button('Añadir aporte', ['href' => '/meta-aporte.php?id=' . $m->id, 'icon' => 'plus']) ?>
                    <?= Ui::button('Editar', ['href' => '/meta-form.php?id=' . $m->id, 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'edit', 'attrs' => ['aria-label' => 'Editar la meta ' . $m->nombre]]) ?>
                    <?= Ui::postForm('/metas.php', ['id' => $m->id, 'accion' => 'cancelar'], Ui::button('Cancelar meta', [
                        'type' => 'submit',
                        'variant' => 'secondary',
                        'size' => 'sm',
                        'attrs' => ['aria-label' => 'Cancelar la meta ' . $m->nombre, 'data-confirm' => '¿Cancelar la meta «' . $m->nombre . '»?' . ($m->numAportes > 0 ? ' Los ' . Dinero::formatear($m->ahorrado) . ' aportados volverán a sus cuentas y se borrará el historial de aportes.' : ''), 'data-confirm-ok' => 'Cancelar meta'],
                    ])) ?>
                <?php endif; ?>
                <?php if ($m->numAportes === 0): ?>
                    <?= Ui::postForm('/metas.php', ['id' => $m->id, 'accion' => 'eliminar'], Ui::button('Eliminar', [
                        'type' => 'submit',
                        'variant' => 'secondary',
                        'size' => 'sm',
                        'attrs' => ['aria-label' => 'Eliminar la meta ' . $m->nombre, 'data-confirm' => '¿Eliminar la meta «' . $m->nombre . '»? Esta acción no se puede deshacer.', 'data-confirm-ok' => 'Eliminar'],
                    ])) ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?= Ui::cardClose('article') ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($puedeEscribir): ?><?= Ui::fab('/movimiento-form.php', 'Nuevo movimiento') ?><?php endif; ?>
