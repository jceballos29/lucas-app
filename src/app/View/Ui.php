<?php

declare(strict_types=1);

namespace App\View;

use App\View\Ui\Attrs;
use App\View\Ui\Button;
use App\View\Ui\Chart;
use App\View\Ui\Display;
use App\View\Ui\Form;
use App\View\Ui\Icon;

/**
 * Componentes de interfaz reutilizables («design system» de Lucas).
 *
 * Cada método devuelve un {@see Html} ya escapado, así que en una vista se usa con `<?= Ui::badge('Activo') ?>` y se
 * pueden anidar. Todo texto que reciben como `string` se escapa; solo se inserta tal cual lo que ya es `Html`.
 *
 * Es una FACHADA (R7 de docs/AUDITORIA.md): cada método delega en la clase de su familia, en `App\View\Ui\` (Icon, Button, Form, Chart,
 * Display y Attrs), donde está el código. Las vistas no cambian.
 *
 * Organización (de lo simple a lo compuesto):
 *  - Átomos:     icon, logo, button, iconButton, badge, avatar, money, progress
 *  - Moléculas:  field, select, segmented, notice, stat, listItem, pageHead, pagination
 *  - Organismos: card (cardOpen/cardClose), table, donut, bars, splitBar, fab
 *
 * Los estilos de cada uno viven en public/css/components.css con el mismo nombre de clase (BEM: `.bloque__elemento--variante`).
 * Los valores dinámicos (anchos, alturas) van en `data-v` / `data-h`: la CSP no permite `style=""` y app.js los aplica.
 */
final class Ui
{
    /** Icono SVG decorativo (aria-hidden). El significado lo da el texto o el aria-label del control que lo contiene. */
    public static function icon(string $name, string $class = ''): Html
    {
        return Icon::icon($name, $class);
    }

    /** Marca de Lucas. `$variant`: 'light' (sobre fondo claro) u 'dark' (sobre fondo oscuro). */
    public static function logo(string $href, string $variant = 'light'): Html
    {
        return Icon::logo($href, $variant);
    }

    /**
     * Botón (o enlace con aspecto de botón si se da `href`).
     *
     * @param array{href?: string, type?: string, variant?: string, size?: string, icon?: string, iconEnd?: string, block?: bool, attrs?: array<string, string|bool|null>} $o
     */
    public static function button(string|Html $label, array $o = []): Html
    {
        return Button::button($label, $o);
    }

    /**
     * Botón cuadrado solo con icono (editar, eliminar...). El `$label` es obligatorio: es su nombre accesible.
     *
     * @param array{href?: string, type?: string, tone?: string, attrs?: array<string, string|bool|null>} $o
     */
    public static function iconButton(string $icon, string $label, array $o = []): Html
    {
        return Button::iconButton($icon, $label, $o);
    }

    /** Etiqueta pequeña de estado o categoría. El estado se dice con texto, nunca solo con color. */
    public static function badge(string|Html $text, string $tone = 'neutral', ?string $icon = null): Html
    {
        return Display::badge($text, $tone, $icon);
    }

    /** Círculo con las iniciales de la persona. `$size`: 'md' o 'lg'. */
    public static function avatar(string $name, string $size = 'md'): Html
    {
        return Display::avatar($name, $size);
    }

    /**
     * Cantidad de dinero en pesos con separador de miles. `$kind`: 'plain' (neutra), 'in' (ingreso, «+») o 'out' (gasto, «−»).
     * El signo va en el texto además del color, para que no dependa de verlo.
     */
    public static function money(int|float|string $amount, string $kind = 'plain', ?string $moneda = null): Html
    {
        return Display::money($amount, $kind, $moneda);
    }

    /** Pesos sin signo ni envoltorio, para frases («$330.000 de $400.000»). */
    public static function pesos(int|float $amount): string
    {
        return Display::pesos($amount);
    }

    /**
     * Barra de progreso accesible. `$tone`: 'ok', 'warn', 'over' o 'auto' (según el porcentaje: <80 ok, <100 warn, ≥100 over).
     * El ancho se limita a 0–100 pero `aria-valuenow` conserva el valor real (p. ej. 125 %).
     */
    public static function progress(int|float $percent, string $label, string $tone = 'ok'): Html
    {
        return Chart::progress($percent, $label, $tone);
    }

    /** Tono según el consumo de un presupuesto: avisa al 80 % y al 100 %. */
    public static function budgetTone(int|float $percent): string
    {
        return Chart::budgetTone($percent);
    }

    /**
     * Campo de formulario: etiqueta + control + ayuda + error, enlazados con for/aria-describedby/aria-invalid.
     *
     * @param array{id: string, name?: string, label: string, type?: string, value?: string, placeholder?: string, hint?: string,
     *              error?: string|null, required?: bool, autocomplete?: string, size?: string, flush?: bool, attrs?: array<string, string|bool|null>} $o
     */
    public static function field(array $o): Html
    {
        return Form::field($o);
    }

    /**
     * Lista desplegable. `options`: lista de textos (valor = texto) o `[valor => texto]`.
     *
     * @param array{id: string, name?: string, label: string, options?: array<int|string, string>, selected?: string, blank?: string, flush?: bool, error?: string|null, optionAttrs?: array<int|string, array<string, string>>, attrs?: array<string, string|bool|null>} $o
     *        `optionAttrs`: atributos extra por valor de opción (p. ej. `data-tipo`); `error`: mensaje bajo el campo.
     */
    public static function select(array $o): Html
    {
        return Form::select($o);
    }

    /**
     * Selector de una opción entre pocas (gasto / ingreso). Usa radios reales: funciona con teclado y lectores de pantalla.
     *
     * @param array{name: string, legend: string, options: array<string, string>, checked?: string} $o
     */
    public static function segmented(array $o): Html
    {
        return Form::segmented($o);
    }

    /** Mensaje en línea. `$tone`: 'info', 'ok', 'warn' o 'error'. Los errores usan role="alert"; el resto, "status". */
    public static function notice(string|Html $message, string $tone = 'info'): Html
    {
        return Display::notice($message, $tone);
    }

    /** Cabecera de página: título, subtítulo y acciones a la derecha. */
    public static function pageHead(string $title, ?string $subtitle = null, string|Html|null $actions = null): Html
    {
        return Display::pageHead($title, $subtitle, $actions);
    }

    /**
     * Indicador numérico (KPI).
     *
     * @param array{icon?: string, tone?: string, hint?: string|Html} $o `tone`: 'default' o 'brand' (icono teal)
     */
    public static function stat(string $label, string|Html $value, array $o = []): Html
    {
        return Display::stat($label, $value, $o);
    }

    /** Fila de lista: título + detalle a la izquierda, dato o etiqueta a la derecha. */
    public static function listItem(string|Html $title, string|Html|null $meta = null, string|Html|null $trailing = null, ?string $avatarName = null): Html
    {
        return Display::listItem($title, $meta, $trailing, $avatarName);
    }

    /**
     * Paginación con enlaces. `$hrefPattern` lleva `%d` donde va el número de página. Con muchas páginas muestra la primera,
     * la última y las vecinas de la actual (con «…» en los huecos) en vez de cientos de enlaces.
     */
    public static function pagination(int $current, int $total, string $hrefPattern = '?pagina=%d', string $label = 'Paginación'): Html
    {
        return Display::pagination($current, $total, $hrefPattern, $label);
    }

    /**
     * Abre una tarjeta; se cierra con {@see cardClose()}. Con `title` lleva cabecera (y `action` a la derecha).
     *
     * @param array{title?: string, subtitle?: string, action?: string|Html, class?: string, tag?: string} $o
     */
    public static function cardOpen(array $o = []): Html
    {
        return Display::cardOpen($o);
    }

    /** Cierra la tarjeta abierta con cardOpen() (mismo `tag`). */
    public static function cardClose(string $tag = 'section'): Html
    {
        return Display::cardClose($tag);
    }

    /**
     * Tabla que en móvil se convierte en tarjetas (cada celda lleva su etiqueta en `data-label`).
     * Las celdas de texto se escapan; para HTML (etiquetas, botones) pasar un `Html`.
     *
     * @param array{columns: list<array{key: string, label: string, align?: string, kind?: string}>, rows: list<array<string, mixed>>, caption: string} $o
     *        `kind` = 'actions' marca la columna de acciones; `align` = 'end' alinea a la derecha (importes).
     */
    public static function table(array $o): Html
    {
        return Display::table($o);
    }

    /**
     * Gráfico circular con SVG (sin librerías ni estilos en línea) y su leyenda.
     *
     * @param list<array{label: string, value: int|float, tone?: string}> $slices `value` en porcentaje (la suma debería ser 100).
     */
    public static function donut(array $slices, string $ariaLabel, string $centerValue = '', string $centerLabel = ''): Html
    {
        return Chart::donut($slices, $ariaLabel, $centerValue, $centerLabel);
    }

    /**
     * Gráfico de barras en CSS. La altura se calcula respecto al valor máximo; la última barra queda resaltada.
     *
     * @param list<array{label: string, value: int|float, display?: string}> $items `display` = texto sobre la barra (p. ej. «$1,1 M»).
     */
    public static function bars(array $items, string $ariaLabel): Html
    {
        return Chart::bars($items, $ariaLabel);
    }

    /**
     * Barra partida en segmentos proporcionales (p. ej. ingresos frente a gastos del mes).
     *
     * @param list<array{label: string, value: int|float, tone?: string}> $parts `tone`: 'primary' o 'secondary'
     */
    public static function splitBar(array $parts, string $ariaLabel): Html
    {
        return Chart::splitBar($parts, $ariaLabel);
    }

    /**
     * Formulario POST con token CSRF y campos ocultos (eliminar, activar/desactivar...). Todo POST de la app debe llevar CSRF.
     *
     * @param array<string, string|int> $hidden Campos ocultos `nombre => valor`.
     */
    public static function postForm(string $action, array $hidden, string|Html $content): Html
    {
        return Form::postForm($action, $hidden, $content);
    }

    /**
     * Grupo de botones de una fila de tabla.
     *
     * @param list<Html> $items
     */
    public static function actions(array $items): Html
    {
        return Button::actions($items);
    }

    /** Botón flotante «nuevo movimiento» (solo se ve en móvil). */
    public static function fab(string $href, string $label): Html
    {
        return Button::fab($href, $label);
    }
}
