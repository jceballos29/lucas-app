<?php

declare(strict_types=1);

namespace App\View\Ui;

use App\View\Html;

/**
 * Campos de formulario (texto, lista, segmentado) y el formulario POST con token CSRF.
 *
 * Parte de «Ui» partido por familias (R7 de docs/AUDITORIA.md). En las vistas se sigue usando la fachada `App\View\Ui`
 * (`Ui::button(...)`), que delega aquí: esta clase es donde vive el código.
 */
final class Form
{
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

        if ($type === 'textarea') {
            $attrs = [
                'class' => 'input input--textarea',
                'id' => $id,
                'name' => $o['name'] ?? $id,
                'placeholder' => $o['placeholder'] ?? null,
                'required' => !empty($o['required']),
                'aria-invalid' => $error !== null ? 'true' : null,
                'aria-describedby' => $describedBy !== [] ? implode(' ', $describedBy) : null,
                'rows' => '5',
            ] + ($o['attrs'] ?? []);
            $control = '<textarea' . Attrs::render($attrs) . '>' . escape((string) ($o['value'] ?? '')) . '</textarea>';
        } else {
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

            $control = '<input' . Attrs::render($attrs) . '>';
            if ($type === 'file') {
                // U6: el selector nativo escribe «Browse…/No file chosen» en el idioma del navegador y rompe el estilo. El <input> sigue ahí
                // (oculto a la vista, enfocable y con su etiqueta) y se pinta un botón en español; app.js muestra el nombre elegido.
                $attrs['class'] = 'file-input__native sr-only';
                $control = '<div class="file-input"><input' . Attrs::render($attrs) . '>'
                    . '<label class="btn btn--secondary btn--sm" for="' . escape($id) . '">Elegir archivo</label>'
                    . '<span class="file-input__name muted" data-file-name data-vacio="Ningún archivo seleccionado">Ningún archivo seleccionado</span></div>';
            }
            if ($type === 'password') {
                $control = '<div class="field__control">' . $control
                    . '<button class="field__toggle" type="button" data-toggle-password="' . escape($id) . '" aria-pressed="false" aria-label="Mostrar contraseña">'
                    . Icon::icon('eye', 'field__toggle-show') . Icon::icon('eye-off', 'field__toggle-hide') . '</button></div>';
            }
        }

        return new Html('<div class="field' . (!empty($o['flush']) ? ' field--flush' : '') . '">'
            . '<label class="field__label" for="' . escape($id) . '">' . escape($o['label']) . '</label>'
            . $control
            . ($hint !== null ? '<p class="field__hint" id="h-' . escape($id) . '">' . escape($hint) . '</p>' : '')
            . ($error !== null ? '<p class="field__error" id="e-' . escape($id) . '">' . Icon::icon('alert') . '<span>' . escape($error) . '</span></p>' : '')
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
            $html .= '<option value="' . escape($value) . '"' . Attrs::render($o['optionAttrs'][$value] ?? []) . (($o['selected'] ?? null) === $value ? ' selected' : '') . '>' . escape($text) . '</option>';
        }

        $error = $o['error'] ?? null;
        $attrs = ['class' => 'select', 'id' => $id, 'name' => $o['name'] ?? $id, 'aria-invalid' => $error !== null ? 'true' : null, 'aria-describedby' => $error !== null ? "e-$id" : null] + ($o['attrs'] ?? []);

        return new Html('<div class="field' . (!empty($o['flush']) ? ' field--flush' : '') . '">'
            . '<label class="field__label" for="' . escape($id) . '">' . escape($o['label']) . '</label>'
            . '<div class="field__control"><select' . Attrs::render($attrs) . '>' . $html . '</select>' . Icon::icon('chevron-down', 'field__chevron') . '</div>'
            . ($error !== null ? '<p class="field__error" id="e-' . escape($id) . '">' . Icon::icon('alert') . '<span>' . escape($error) . '</span></p>' : '')
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
}
