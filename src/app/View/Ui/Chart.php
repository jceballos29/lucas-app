<?php

declare(strict_types=1);

namespace App\View\Ui;

use App\View\Html;
use InvalidArgumentException;

/**
 * Gráficos y barras de progreso (SVG y CSS, sin librerías ni estilos en línea): donut, barras, barra partida, progreso.
 *
 * Parte de «Ui» partido por familias (R7 de docs/AUDITORIA.md). En las vistas se sigue usando la fachada `App\View\Ui`
 * (`Ui::button(...)`), que delega aquí: esta clase es donde vive el código.
 */
final class Chart
{
    private const SLICE_TONES = ['teal', 'navy', 'sky', 'ink', 'soft'];

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
}
