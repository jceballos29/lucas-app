<?php

declare(strict_types=1);

namespace App\View;

use InvalidArgumentException;

/**
 * Componentes de interfaz reutilizables («design system» de Lucas).
 *
 * Cada método devuelve un {@see Html} ya escapado, así que en una vista se usa con `<?= Ui::badge('Activo') ?>` y se
 * pueden anidar. Todo texto que reciben como `string` se escapa; solo se inserta tal cual lo que ya es `Html`.
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
    /** Iconos de trazo (rejilla de 24 px, estilo Lucide). El contenido es constante del proyecto, no entrada del usuario. */
    private const ICONS = [
        'dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'transfer' => '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
        'wallet' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'pie' => '<path d="M21 12c.552 0 1.005-.449.95-.998a10 10 0 0 0-8.953-8.951c-.55-.055-.998.398-.998.95v8a1 1 0 0 0 1 1z"/><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/>',
        'chart' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'edit' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'eye' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'close' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'laptop' => '<path d="M20 16V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v9m16 0H4m16 0 1.28 2.55a1 1 0 0 1-.9 1.45H3.62a1 1 0 0 1-.9-1.45L4 16"/>',
        'plane' => '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'book' => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
        'trend-up' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
        'trend-down' => '<polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'filter' => '<line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/>',
        'bank' => '<line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
        'card' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
        'coins' => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    ];

    /**
     * Marca de Lucas: los ejes de un gráfico forman la «L» y tres barras suben dentro; la última (el mes actual) va en blanco.
     * Los colores salen del CSS (`.logo__bg`, `.logo__axis`, `.logo__bar`). Misma figura que public/favicon.svg: si cambia una, cambia la otra.
     */
    private const LOGO_MARK = '<svg class="logo__mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
        . '<rect class="logo__bg" width="32" height="32" rx="9"/>'
        . '<path class="logo__axis" d="M8.2 8v16h16"/>'
        . '<rect class="logo__bar" x="11.8" y="17" width="3.4" height="5" rx="1.2"/>'
        . '<rect class="logo__bar" x="16.6" y="13.5" width="3.4" height="8.5" rx="1.2"/>'
        . '<rect class="logo__bar logo__bar--now" x="21.4" y="9.5" width="3.4" height="12.5" rx="1.2"/>'
        . '</svg>';

    private const BUTTON_VARIANTS = ['primary', 'secondary', 'dark', 'ghost', 'danger'];
    private const BADGE_TONES = ['neutral', 'brand', 'dark', 'warn', 'danger', 'outline'];
    private const NOTICE_TONES = ['info', 'ok', 'warn', 'error'];
    private const SLICE_TONES = ['teal', 'navy', 'sky', 'ink', 'soft'];

    // ------------------------------------------------------------------------------------------------
    //  Átomos
    // ------------------------------------------------------------------------------------------------

    /** Icono SVG decorativo (aria-hidden). El significado lo da el texto o el aria-label del control que lo contiene. */
    public static function icon(string $name, string $class = ''): Html
    {
        if (!isset(self::ICONS[$name])) {
            throw new InvalidArgumentException("Icono desconocido: $name");
        }

        $cls = trim('icon ' . $class);

        return new Html('<svg class="' . escape($cls) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . self::ICONS[$name] . '</svg>');
    }

    /** Marca de Lucas. `$variant`: 'light' (sobre fondo claro) u 'dark' (sobre fondo oscuro). */
    public static function logo(string $href, string $variant = 'light'): Html
    {
        $variant = $variant === 'dark' ? 'dark' : 'light';

        return new Html('<a class="logo logo--' . $variant . '" href="' . escape($href) . '" aria-label="Lucas, ir al inicio">' . self::LOGO_MARK . '<span class="logo__word">Lucas</span></a>');
    }

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

        $inner = (isset($o['icon']) ? self::icon($o['icon']) : '') . '<span>' . Html::of($label) . '</span>' . (isset($o['iconEnd']) ? self::icon($o['iconEnd']) : '');
        $attrs = ['class' => implode(' ', $classes)] + ($o['attrs'] ?? []);

        if (isset($o['href'])) {
            return new Html('<a' . self::attrs(['href' => $o['href']] + $attrs) . '>' . $inner . '</a>');
        }

        return new Html('<button' . self::attrs(['type' => $o['type'] ?? 'button'] + $attrs) . '>' . $inner . '</button>');
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
        $inner = self::icon($icon);

        if (isset($o['href'])) {
            return new Html('<a' . self::attrs(['href' => $o['href']] + $attrs) . '>' . $inner . '</a>');
        }

        return new Html('<button' . self::attrs(['type' => $o['type'] ?? 'button'] + $attrs) . '>' . $inner . '</button>');
    }

    /** Etiqueta pequeña de estado o categoría. El estado se dice con texto, nunca solo con color. */
    public static function badge(string|Html $text, string $tone = 'neutral', ?string $icon = null): Html
    {
        if (!in_array($tone, self::BADGE_TONES, true)) {
            throw new InvalidArgumentException("Tono de etiqueta no válido: $tone");
        }

        return new Html('<span class="badge badge--' . $tone . '">' . ($icon !== null ? self::icon($icon) : '') . Html::of($text) . '</span>');
    }

    /** Círculo con las iniciales de la persona. `$size`: 'md' o 'lg'. */
    public static function avatar(string $name, string $size = 'md'): Html
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = mb_strtoupper(implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1, 'UTF-8'), array_slice($words, 0, 2))), 'UTF-8');

        return new Html('<span class="avatar avatar--' . ($size === 'lg' ? 'lg' : 'md') . '" aria-hidden="true">' . escape($initials !== '' ? $initials : '?') . '</span>');
    }

    /**
     * Cantidad de dinero en pesos con separador de miles. `$kind`: 'plain' (neutra), 'in' (ingreso, «+») o 'out' (gasto, «−»).
     * El signo va en el texto además del color, para que no dependa de verlo.
     */
    public static function money(int|float|string $amount, string $kind = 'plain'): Html
    {
        // Una cadena es un monto exacto de la BD («1250.50»): se formatea sin pasar por float (RNF-16).
        if (is_string($amount)) {
            $negative = str_starts_with($amount, '-');
            $text = \App\Support\Dinero::formatear(ltrim($amount, '-'));
        } else {
            $negative = $amount < 0;
            $text = \App\Support\Dinero::simbolo() . number_format(abs($amount), 0, ',', '.');
        }

        $sign = match ($kind) {
            'in' => '+',
            'out' => '−',
            default => $negative ? '−' : '',
        };
        $tone = in_array($kind, ['in', 'out'], true) ? $kind : 'plain';

        return new Html('<span class="money money--' . $tone . '">' . $sign . $text . '</span>');
    }

    /** Pesos sin signo ni envoltorio, para frases («$330.000 de $400.000»). */
    public static function pesos(int|float $amount): string
    {
        return \App\Support\Dinero::simbolo() . number_format(abs($amount), 0, ',', '.');
    }

    /**
     * Barra de progreso accesible. `$tone`: 'ok', 'warn', 'over' o 'auto' (según el porcentaje: <80 ok, <100 warn, ≥100 over).
     * El ancho se limita a 0–100 pero `aria-valuenow` conserva el valor real (p. ej. 125 %).
     */
    public static function progress(int|float $percent, string $label, string $tone = 'ok'): Html
    {
        if ($tone === 'auto') {
            $tone = self::budgetTone($percent);
        }
        if (!in_array($tone, ['ok', 'warn', 'over'], true)) {
            throw new InvalidArgumentException("Tono de progreso no válido: $tone");
        }

        $width = max(0, min(100, $percent));

        return new Html('<div class="progress progress--' . $tone . '" data-v="' . escape((string) $width) . '" role="progressbar" aria-valuenow="' . escape((string) round($percent)) . '" aria-valuemin="0" aria-valuemax="100" aria-label="' . escape($label) . '"><span class="progress__fill"></span></div>');
    }

    /** Tono según el consumo de un presupuesto: avisa al 80 % y al 100 %. */
    public static function budgetTone(int|float $percent): string
    {
        return $percent >= 100 ? 'over' : ($percent >= 80 ? 'warn' : 'ok');
    }

    // ------------------------------------------------------------------------------------------------
    //  Moléculas
    // ------------------------------------------------------------------------------------------------

    /**
     * Campo de formulario: etiqueta + control + ayuda + error, enlazados con for/aria-describedby/aria-invalid.
     *
     * @param array{id: string, name?: string, label: string, type?: string, value?: string, placeholder?: string, hint?: string,
     *              error?: string|null, required?: bool, autocomplete?: string, size?: string, flush?: bool, attrs?: array<string, string|bool|null>} $o
     */
    public static function field(array $o): Html
    {
        $id = $o['id'];
        $type = $o['type'] ?? 'text';
        $error = $o['error'] ?? null;
        $hint = $o['hint'] ?? null;

        $describedBy = array_filter([$hint !== null ? "h-$id" : null, $error !== null ? "e-$id" : null]);
        $attrs = [
            'class' => 'input' . (($o['size'] ?? '') === 'lg' ? ' input--lg' : ''),
            'type' => $type,
            'id' => $id,
            'name' => $o['name'] ?? $id,
            'value' => $type === 'password' ? null : ($o['value'] ?? null),
            'placeholder' => $o['placeholder'] ?? null,
            'autocomplete' => $o['autocomplete'] ?? null,
            'required' => !empty($o['required']),
            'aria-invalid' => $error !== null ? 'true' : null,
            'aria-describedby' => $describedBy !== [] ? implode(' ', $describedBy) : null,
        ] + ($o['attrs'] ?? []);

        $control = '<input' . self::attrs($attrs) . '>';
        if ($type === 'password') {
            $control = '<div class="field__control">' . $control
                . '<button class="field__toggle" type="button" data-toggle-password="' . escape($id) . '" aria-pressed="false" aria-label="Mostrar contraseña">'
                . self::icon('eye', 'field__toggle-show') . self::icon('eye-off', 'field__toggle-hide') . '</button></div>';
        }

        return new Html('<div class="field' . (!empty($o['flush']) ? ' field--flush' : '') . '">'
            . '<label class="field__label" for="' . escape($id) . '">' . escape($o['label']) . '</label>'
            . $control
            . ($hint !== null ? '<p class="field__hint" id="h-' . escape($id) . '">' . escape($hint) . '</p>' : '')
            . ($error !== null ? '<p class="field__error" id="e-' . escape($id) . '">' . self::icon('alert') . '<span>' . escape($error) . '</span></p>' : '')
            . '</div>');
    }

    /**
     * Lista desplegable. `options`: lista de textos (valor = texto) o `[valor => texto]`.
     *
     * @param array{id: string, name?: string, label: string, options?: array<int|string, string>, selected?: string, blank?: string, flush?: bool, error?: string|null, optionAttrs?: array<int|string, array<string, string>>, attrs?: array<string, string|bool|null>} $o
     *        `optionAttrs`: atributos extra por valor de opción (p. ej. `data-tipo`); `error`: mensaje bajo el campo.
     */
    public static function select(array $o): Html
    {
        $id = $o['id'];
        $options = $o['options'] ?? [];
        $isList = array_is_list($options);

        $html = '';
        if (isset($o['blank'])) {
            $html .= '<option value="">' . escape($o['blank']) . '</option>';
        }
        foreach ($options as $value => $text) {
            $value = $isList ? $text : (string) $value;
            $html .= '<option value="' . escape($value) . '"' . self::attrs($o['optionAttrs'][$value] ?? []) . (($o['selected'] ?? null) === $value ? ' selected' : '') . '>' . escape($text) . '</option>';
        }

        $error = $o['error'] ?? null;
        $attrs = ['class' => 'select', 'id' => $id, 'name' => $o['name'] ?? $id, 'aria-invalid' => $error !== null ? 'true' : null, 'aria-describedby' => $error !== null ? "e-$id" : null] + ($o['attrs'] ?? []);

        return new Html('<div class="field' . (!empty($o['flush']) ? ' field--flush' : '') . '">'
            . '<label class="field__label" for="' . escape($id) . '">' . escape($o['label']) . '</label>'
            . '<div class="field__control"><select' . self::attrs($attrs) . '>' . $html . '</select>' . self::icon('chevron-down', 'field__chevron') . '</div>'
            . ($error !== null ? '<p class="field__error" id="e-' . escape($id) . '">' . self::icon('alert') . '<span>' . escape($error) . '</span></p>' : '')
            . '</div>');
    }

    /**
     * Selector de una opción entre pocas (gasto / ingreso). Usa radios reales: funciona con teclado y lectores de pantalla.
     *
     * @param array{name: string, legend: string, options: array<string, string>, checked?: string} $o
     */
    public static function segmented(array $o): Html
    {
        $html = '';
        foreach ($o['options'] as $value => $text) {
            $id = $o['name'] . '-' . $value;
            $html .= '<input type="radio" name="' . escape($o['name']) . '" id="' . escape($id) . '" value="' . escape((string) $value) . '"' . (($o['checked'] ?? array_key_first($o['options'])) === (string) $value ? ' checked' : '') . '>'
                . '<label for="' . escape($id) . '">' . escape($text) . '</label>';
        }

        return new Html('<div class="segmented" role="radiogroup" aria-label="' . escape($o['legend']) . '">' . $html . '</div>');
    }

    /** Mensaje en línea. `$tone`: 'info', 'ok', 'warn' o 'error'. Los errores usan role="alert"; el resto, "status". */
    public static function notice(string|Html $message, string $tone = 'info'): Html
    {
        if (!in_array($tone, self::NOTICE_TONES, true)) {
            throw new InvalidArgumentException("Tono de aviso no válido: $tone");
        }

        $icon = match ($tone) {
            'ok' => 'check-circle',
            'warn', 'error' => 'alert',
            default => 'info',
        };

        return new Html('<div class="notice notice--' . $tone . '" role="' . ($tone === 'error' ? 'alert' : 'status') . '">' . self::icon($icon) . '<p>' . Html::of($message) . '</p></div>');
    }

    /** Cabecera de página: título, subtítulo y acciones a la derecha. */
    public static function pageHead(string $title, ?string $subtitle = null, string|Html|null $actions = null): Html
    {
        return new Html('<header class="page-head"><div class="page-head__text"><h1>' . escape($title) . '</h1>'
            . ($subtitle !== null ? '<p class="muted">' . escape($subtitle) . '</p>' : '') . '</div>'
            . ($actions !== null ? '<div class="page-head__actions">' . Html::of($actions) . '</div>' : '') . '</header>');
    }

    /**
     * Indicador numérico (KPI).
     *
     * @param array{icon?: string, tone?: string, hint?: string|Html} $o `tone`: 'default' o 'brand' (icono teal)
     */
    public static function stat(string $label, string|Html $value, array $o = []): Html
    {
        $icon = isset($o['icon']) ? '<span class="stat__icon stat__icon--' . (($o['tone'] ?? '') === 'brand' ? 'brand' : 'default') . '">' . self::icon($o['icon']) . '</span>' : '';

        return new Html('<div class="card stat"><div class="stat__top"><span class="stat__label">' . escape($label) . '</span>' . $icon . '</div>'
            . '<strong class="stat__value">' . Html::of($value) . '</strong>'
            . (isset($o['hint']) ? '<span class="stat__hint">' . Html::of($o['hint']) . '</span>' : '') . '</div>');
    }

    /** Fila de lista: título + detalle a la izquierda, dato o etiqueta a la derecha. */
    public static function listItem(string|Html $title, string|Html|null $meta = null, string|Html|null $trailing = null, ?string $avatarName = null): Html
    {
        return new Html('<div class="list-item">'
            . ($avatarName !== null ? self::avatar($avatarName) : '')
            . '<div class="list-item__text"><span class="list-item__title">' . Html::of($title) . '</span>' . ($meta !== null ? '<span class="list-item__meta">' . Html::of($meta) . '</span>' : '') . '</div>'
            . ($trailing !== null ? '<div class="list-item__trailing">' . Html::of($trailing) . '</div>' : '') . '</div>');
    }

    /**
     * Paginación con enlaces. `$hrefPattern` lleva `%d` donde va el número de página. Con muchas páginas muestra la primera,
     * la última y las vecinas de la actual (con «…» en los huecos) en vez de cientos de enlaces.
     */
    public static function pagination(int $current, int $total, string $hrefPattern = '?pagina=%d', string $label = 'Paginación'): Html
    {
        $visible = array_unique(array_filter([1, $total, ...range($current - 2, $current + 2)], static fn (int $p): bool => $p >= 1 && $p <= $total));
        sort($visible);

        $html = '';
        $previous = 0;
        foreach ($visible as $page) {
            if ($previous !== 0 && $page - $previous > 1) {
                $html .= '<span class="pagination__gap" aria-hidden="true">…</span>';
            }
            $href = str_contains($hrefPattern, '%d') ? sprintf($hrefPattern, $page) : $hrefPattern;
            $html .= '<a href="' . escape($href) . '"' . ($page === $current ? ' aria-current="page"' : '') . ' aria-label="Página ' . $page . '">' . $page . '</a>';
            $previous = $page;
        }

        return new Html('<nav class="pagination" aria-label="' . escape($label) . '">' . $html . '</nav>');
    }

    // ------------------------------------------------------------------------------------------------
    //  Organismos
    // ------------------------------------------------------------------------------------------------

    /**
     * Abre una tarjeta; se cierra con {@see cardClose()}. Con `title` lleva cabecera (y `action` a la derecha).
     *
     * @param array{title?: string, subtitle?: string, action?: string|Html, class?: string, tag?: string} $o
     */
    public static function cardOpen(array $o = []): Html
    {
        $tag = ($o['tag'] ?? 'section') === 'article' ? 'article' : 'section';
        $head = '';
        if (isset($o['title'])) {
            $head = '<div class="card__head"><div><h2 class="card__title">' . escape($o['title']) . '</h2>'
                . (isset($o['subtitle']) ? '<p class="card__subtitle">' . escape($o['subtitle']) . '</p>' : '') . '</div>'
                . (isset($o['action']) ? Html::of($o['action']) : '') . '</div>';
        }

        return new Html('<' . $tag . ' class="card' . (isset($o['class']) ? ' ' . escape($o['class']) : '') . '">' . $head);
    }

    /** Cierra la tarjeta abierta con cardOpen() (mismo `tag`). */
    public static function cardClose(string $tag = 'section'): Html
    {
        return new Html($tag === 'article' ? '</article>' : '</section>');
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
        $head = '';
        foreach ($o['columns'] as $col) {
            $head .= '<th scope="col"' . (($col['align'] ?? '') === 'end' ? ' class="is-end"' : '') . '>' . escape($col['label']) . '</th>';
        }

        $body = '';
        foreach ($o['rows'] as $row) {
            $body .= '<tr>';
            foreach ($o['columns'] as $col) {
                $isActions = ($col['kind'] ?? '') === 'actions';
                $class = trim(($isActions ? 'is-actions ' : '') . (($col['align'] ?? '') === 'end' ? 'is-end' : ''));
                $body .= '<td' . ($class !== '' ? ' class="' . $class . '"' : '') . ($isActions ? '' : ' data-label="' . escape($col['label']) . '"') . '>' . Html::of($row[$col['key']] ?? '') . '</td>';
            }
            $body .= '</tr>';
        }

        return new Html('<div class="table-wrap"><table class="table"><caption class="sr-only">' . escape($o['caption']) . '</caption><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>');
    }

    /**
     * Gráfico circular con SVG (sin librerías ni estilos en línea) y su leyenda.
     *
     * @param list<array{label: string, value: int|float, tone?: string}> $slices `value` en porcentaje (la suma debería ser 100).
     */
    public static function donut(array $slices, string $ariaLabel, string $centerValue = '', string $centerLabel = ''): Html
    {
        $circles = '';
        $legend = '';
        $offset = 25.0; // el trazo de un círculo SVG arranca a las 3 en punto; 25 lo sube a las 12
        foreach ($slices as $i => $slice) {
            $tone = $slice['tone'] ?? self::SLICE_TONES[$i % count(self::SLICE_TONES)];
            if (!in_array($tone, self::SLICE_TONES, true)) {
                throw new InvalidArgumentException("Tono de gráfico no válido: $tone");
            }
            $length = max(0.0, (float) $slice['value'] - 0.8); // 0,8 de hueco entre porciones
            $circles .= '<circle class="donut__slice donut__slice--' . $tone . '" cx="21" cy="21" r="15.9155" fill="none" stroke-width="5" stroke-dasharray="' . round($length, 2) . ' ' . round(100 - $length, 2) . '" stroke-dashoffset="' . round($offset, 2) . '"/>';
            $offset -= (float) $slice['value'];
            $legend .= '<li><i class="donut__swatch donut__swatch--' . $tone . '" aria-hidden="true"></i><span>' . escape($slice['label']) . '</span><b>' . escape((string) round($slice['value'])) . ' %</b></li>';
        }

        return new Html('<div class="donut"><div class="donut__chart" role="img" aria-label="' . escape($ariaLabel) . '">'
            . '<svg viewBox="0 0 42 42" focusable="false"><circle class="donut__track" cx="21" cy="21" r="15.9155" fill="none" stroke-width="5"/>' . $circles . '</svg>'
            . ($centerValue !== '' ? '<div class="donut__center"><strong>' . escape($centerValue) . '</strong><span>' . escape($centerLabel) . '</span></div>' : '')
            . '</div><ul class="donut__legend">' . $legend . '</ul></div>');
    }

    /**
     * Gráfico de barras en CSS. La altura se calcula respecto al valor máximo; la última barra queda resaltada.
     *
     * @param list<array{label: string, value: int|float, display?: string}> $items `display` = texto sobre la barra (p. ej. «$1,1 M»).
     */
    public static function bars(array $items, string $ariaLabel): Html
    {
        $max = max(1, ...array_map(static fn (array $i): float => (float) $i['value'], $items));
        $last = count($items) - 1;

        $html = '';
        foreach ($items as $i => $item) {
            $height = round(max(4.0, (float) $item['value'] / $max * 100), 1);
            $html .= '<div class="bars__col' . ($i === $last ? ' is-current' : '') . '">'
                . (isset($item['display']) ? '<span class="bars__value">' . escape($item['display']) . '</span>' : '')
                . '<span class="bars__bar" data-h="' . $height . '%"></span><span class="bars__label">' . escape($item['label']) . '</span></div>';
        }

        return new Html('<div class="bars" role="img" aria-label="' . escape($ariaLabel) . '">' . $html . '</div>');
    }

    /**
     * Barra partida en segmentos proporcionales (p. ej. ingresos frente a gastos del mes).
     *
     * @param list<array{label: string, value: int|float, tone?: string}> $parts `tone`: 'primary' o 'secondary'
     */
    public static function splitBar(array $parts, string $ariaLabel): Html
    {
        $total = max(1.0, array_sum(array_map(static fn (array $p): float => (float) $p['value'], $parts)));

        $segments = '';
        foreach ($parts as $i => $part) {
            $segments .= '<span class="split__seg split__seg--' . (($part['tone'] ?? ($i === 0 ? 'primary' : 'secondary')) === 'secondary' ? 'secondary' : 'primary') . '" data-v="' . round((float) $part['value'] / $total * 100, 1) . '"></span>';
        }

        return new Html('<div class="split" role="img" aria-label="' . escape($ariaLabel) . '">' . $segments . '</div>');
    }

    /**
     * Formulario POST con token CSRF y campos ocultos (eliminar, activar/desactivar...). Todo POST de la app debe llevar CSRF.
     *
     * @param array<string, string|int> $hidden Campos ocultos `nombre => valor`.
     */
    public static function postForm(string $action, array $hidden, string|Html $content): Html
    {
        $fields = '';
        foreach ($hidden as $name => $value) {
            $fields .= '<input type="hidden" name="' . escape($name) . '" value="' . escape((string) $value) . '">';
        }

        return new Html('<form action="' . escape($action) . '" method="post">' . csrf_field() . $fields . Html::of($content) . '</form>');
    }

    /** Grupo de botones de una fila de tabla. @param list<Html> $items */
    public static function actions(array $items): Html
    {
        return new Html('<div class="row-actions">' . Html::join($items) . '</div>');
    }

    /** Botón flotante «nuevo movimiento» (solo se ve en móvil). */
    public static function fab(string $href, string $label): Html
    {
        return new Html('<a class="fab" href="' . escape($href) . '" aria-label="' . escape($label) . '">' . self::icon('plus') . '</a>');
    }

    // ------------------------------------------------------------------------------------------------
    //  Interno
    // ------------------------------------------------------------------------------------------------

    /**
     * Atributos HTML escapados. `true` = atributo booleano; `false` y `null` = se omite.
     *
     * @param array<string, string|bool|null> $attrs
     */
    private static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9:-]*$/i', $name) !== 1) {
                throw new InvalidArgumentException("Nombre de atributo no válido: $name");
            }
            $out .= $value === true ? " $name" : " $name=\"" . escape($value) . '"';
        }

        return $out;
    }
}
