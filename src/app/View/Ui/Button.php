<?php

declare(strict_types=1);

namespace App\View\Ui;

use App\View\Html;
use InvalidArgumentException;

/**
 * Botones, botones de icono, el botón flotante y los grupos de acciones de fila.
 *
 * Parte de «Ui» partido por familias (R7 de docs/AUDITORIA.md). En las vistas se sigue usando la fachada `App\View\Ui`
 * (`Ui::button(...)`), que delega aquí: esta clase es donde vive el código.
 */
final class Button
{
    private const BUTTON_VARIANTS = ['primary', 'secondary', 'dark', 'ghost', 'danger'];

    /**
     * Botón (o enlace con aspecto de botón si se da `href`).
     *
     * @param array{href?: string, type?: string, variant?: string, size?: string, icon?: string, iconEnd?: string, block?: bool, attrs?: array<string, string|bool|null>} $o
     */
    public static function button(string|Html $label, array $o = []): Html
    {
        $variant = $o['variant'] ?? 'primary';
        if (!in_array($variant, self::BUTTON_VARIANTS, true)) {
            throw new InvalidArgumentException("Variante de botón no válida: $variant");
        }

        $classes = ['btn', "btn--$variant"];
        if (($o['size'] ?? 'md') === 'sm') {
            $classes[] = 'btn--sm';
        }
        if (!empty($o['block'])) {
            $classes[] = 'btn--block';
        }

        $inner = (isset($o['icon']) ? Icon::icon($o['icon']) : '') . '<span>' . Html::of($label) . '</span>' . (isset($o['iconEnd']) ? Icon::icon($o['iconEnd']) : '');
        $attrs = ['class' => implode(' ', $classes)] + ($o['attrs'] ?? []);

        if (isset($o['href'])) {
            return new Html('<a' . Attrs::render(['href' => $o['href']] + $attrs) . '>' . $inner . '</a>');
        }

        return new Html('<button' . Attrs::render(['type' => $o['type'] ?? 'button'] + $attrs) . '>' . $inner . '</button>');
    }

    /**
     * Botón cuadrado solo con icono (editar, eliminar...). El `$label` es obligatorio: es su nombre accesible.
     *
     * @param array{href?: string, type?: string, tone?: string, attrs?: array<string, string|bool|null>} $o
     */
    public static function iconButton(string $icon, string $label, array $o = []): Html
    {
        $classes = 'icon-btn' . (($o['tone'] ?? '') === 'danger' ? ' icon-btn--danger' : '');
        $attrs = ['class' => $classes, 'aria-label' => $label, 'title' => $label] + ($o['attrs'] ?? []);
        $inner = Icon::icon($icon);

        if (isset($o['href'])) {
            return new Html('<a' . Attrs::render(['href' => $o['href']] + $attrs) . '>' . $inner . '</a>');
        }

        return new Html('<button' . Attrs::render(['type' => $o['type'] ?? 'button'] + $attrs) . '>' . $inner . '</button>');
    }

    /**
     * Grupo de botones de una fila de tabla.
     *
     * @param list<Html> $items
     */
    public static function actions(array $items): Html
    {
        return new Html('<div class="row-actions">' . Html::join($items) . '</div>');
    }

    /** Botón flotante «nuevo movimiento» (solo se ve en móvil). */
    public static function fab(string $href, string $label): Html
    {
        return new Html('<a class="fab" href="' . escape($href) . '" aria-label="' . escape($label) . '">' . Icon::icon('plus') . '</a>');
    }
}
