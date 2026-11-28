<?php

declare(strict_types=1);

namespace App\View\Ui;

use App\View\Html;
use InvalidArgumentException;

/**
 * Componentes de presentación: etiquetas, avatar, importes, avisos, cabeceras de página, tarjetas, tablas y paginación.
 *
 * Parte de «Ui» partido por familias (R7 de docs/AUDITORIA.md). En las vistas se sigue usando la fachada `App\View\Ui`
 * (`Ui::button(...)`), que delega aquí: esta clase es donde vive el código.
 */
final class Display
{
    private const BADGE_TONES = ['neutral', 'brand', 'dark', 'warn', 'danger', 'outline'];

    private const NOTICE_TONES = ['info', 'ok', 'warn', 'error'];

    /** Etiqueta pequeña de estado o categoría. El estado se dice con texto, nunca solo con color. */
    public static function badge(string|Html $text, string $tone = 'neutral', ?string $icon = null): Html
    {
        if (!in_array($tone, self::BADGE_TONES, true)) {
            throw new InvalidArgumentException("Tono de etiqueta no válido: $tone");
        }

        return new Html('<span class="badge badge--' . $tone . '">' . ($icon !== null ? Icon::icon($icon) : '') . Html::of($text) . '</span>');
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
    public static function money(int|float|string $amount, string $kind = 'plain', ?string $moneda = null): Html
    {
        // Una cadena es un monto exacto de la BD («1250.50»): se formatea sin pasar por float (RNF-16).
        if (is_string($amount)) {
            $negative = str_starts_with($amount, '-');
            $text = \App\Support\Dinero::formatearEn(ltrim($amount, '-'), $moneda);
        } else {
            $negative = $amount < 0;
            $text = \App\Support\Dinero::simboloDe($moneda) . number_format(abs($amount), 0, ',', '.');
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

        return new Html('<div class="notice notice--' . $tone . '" role="' . ($tone === 'error' ? 'alert' : 'status') . '">' . Icon::icon($icon) . '<p>' . Html::of($message) . '</p></div>');
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
        $icon = isset($o['icon']) ? '<span class="stat__icon stat__icon--' . (($o['tone'] ?? '') === 'brand' ? 'brand' : 'default') . '">' . Icon::icon($o['icon']) . '</span>' : '';

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

        return new Html('<' . $tag . (isset($o['id']) ? ' id="' . escape($o['id']) . '"' : '') . ' class="card' . (isset($o['class']) ? ' ' . escape($o['class']) : '') . '">' . $head);
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
}
