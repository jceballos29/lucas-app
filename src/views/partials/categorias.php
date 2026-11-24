<?php

declare(strict_types=1);

use App\Domain\Categoria;
use App\View\Html;
use App\View\Ui;

/**
 * Gestión de categorías (la usan categorias.php y admin/categorias.php).
 *
 * @var string $base Dirección de la página (para los formularios y enlaces).
 * @var bool $isAdmin true en la página del administrador: todas las categorías son predeterminadas y editables.
 * @var list<Categoria> $items
 * @var Categoria|null $editing La categoría que se edita (null = formulario de alta).
 * @var array{nombre: string, tipo: string, color: string} $old
 * @var array<string, string> $errors
 * @var array{tone: string, message: string}|null $flash
 */
$flash = $flash ?? null;
$editando = $editing !== null;

$secciones = [];
foreach (Categoria::TIPOS as $tipo => $titulo) {
    $rows = [];
    foreach ($items as $c) {
        if ($c->tipo !== $tipo) {
            continue;
        }

        $editable = $isAdmin || !$c->esPredeterminada();
        $rows[] = [
            'nombre' => new Html('<div class="cell-person"><span class="swatch" data-color="' . escape($c->color) . '" aria-hidden="true"></span><span class="cell-title">' . escape($c->nombre) . '</span></div>'),
            'origen' => $c->esPredeterminada() ? Ui::badge('Predeterminada', 'outline') : Ui::badge('Propia', 'brand'),
            'acciones' => $editable
                ? Ui::actions([
                    Ui::button('Editar', ['href' => $base . '?editar=' . $c->id, 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'edit', 'attrs' => ['aria-label' => 'Editar ' . $c->nombre]]),
                    Ui::postForm($base, ['id' => $c->id, 'accion' => 'eliminar'], Ui::button('Eliminar', [
                        'type' => 'submit',
                        'variant' => 'secondary',
                        'size' => 'sm',
                        'attrs' => ['aria-label' => 'Eliminar ' . $c->nombre, 'data-confirm' => '¿Eliminar la categoría «' . $c->nombre . '»? Solo se puede si no tiene movimientos ni presupuestos.', 'data-confirm-ok' => 'Eliminar'],
                    ])),
                ])
                : new Html('<span class="muted-note">Solo la edita un administrador</span>'),
        ];
    }

    $secciones[$tipo] = ['titulo' => $titulo, 'rows' => $rows];
}
?>
<?= Ui::pageHead(
    $isAdmin ? 'Categorías predeterminadas' : 'Categorías',
    $isAdmin ? 'Las ven todas las personas al registrar movimientos' : 'Organiza tus ingresos y gastos'
) ?>

<?php if ($flash !== null): ?><?= Ui::notice($flash['message'], $flash['tone']) ?><?php endif; ?>

<?= Ui::cardOpen(['title' => $editando ? 'Editar categoría' : ($isAdmin ? 'Nueva categoría predeterminada' : 'Nueva categoría'), 'class' => 'form-card']) ?>
    <form action="<?= escape($base) ?>" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="<?= $editando ? 'guardar' : 'crear' ?>">
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $editing->id ?>"><?php endif; ?>
        <?php if ($editando): ?>
            <p class="muted">Tipo: <strong><?= escape(Categoria::TIPOS[$editing->tipo] ?? $editing->tipo) ?></strong> (no se puede cambiar porque los movimientos ya guardados dependen de él).</p>
        <?php else: ?>
            <?= Ui::segmented(['name' => 'tipo', 'legend' => 'Tipo de categoría', 'options' => Categoria::TIPOS, 'checked' => $old['tipo']]) ?>
            <?php if (isset($errors['tipo'])): ?><?= Ui::notice($errors['tipo'], 'error') ?><?php endif; ?>
        <?php endif; ?>
        <?= Ui::field(['id' => 'nombre', 'label' => 'Nombre', 'value' => $old['nombre'], 'placeholder' => 'Ej.: Mascotas', 'required' => true, 'error' => $errors['nombre'] ?? null, 'attrs' => ['maxlength' => '50']]) ?>
        <?= Ui::field(['id' => 'color', 'label' => 'Color (para los gráficos)', 'type' => 'color', 'value' => $old['color'], 'error' => $errors['color'] ?? null]) ?>
        <div class="form-actions">
            <?= Ui::button($editando ? 'Guardar cambios' : 'Crear categoría', ['type' => 'submit']) ?>
            <?php if ($editando): ?><?= Ui::button('Cancelar', ['href' => $base, 'variant' => 'secondary']) ?><?php endif; ?>
        </div>
    </form>
<?= Ui::cardClose() ?>

<?php foreach ($secciones as $s): ?>
    <h2 class="section-title"><?= escape($s['titulo']) ?></h2>
    <?php if ($s['rows'] === []): ?>
        <p class="muted">Todavía no hay categorías de este tipo.</p>
    <?php else: ?>
        <?= Ui::table([
            'caption' => 'Categorías de ' . strtolower($s['titulo']),
            'columns' => [
                ['key' => 'nombre', 'label' => 'Nombre'],
                ['key' => 'origen', 'label' => 'Origen'],
                ['key' => 'acciones', 'label' => 'Acciones', 'kind' => 'actions'],
            ],
            'rows' => $s['rows'],
        ]) ?>
    <?php endif; ?>
<?php endforeach; ?>
