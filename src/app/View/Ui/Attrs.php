<?php

declare(strict_types=1);

namespace App\View\Ui;

use App\View\Html;
use InvalidArgumentException;

/**
 * Atributos HTML escapados (interno de los componentes).
 *
 * Parte de «Ui» partido por familias (R7 de docs/AUDITORIA.md). En las vistas se sigue usando la fachada `App\View\Ui`
 * (`Ui::button(...)`), que delega aquí: esta clase es donde vive el código.
 */
final class Attrs
{
    /**
     * Atributos HTML escapados. `true` = atributo booleano; `false` y `null` = se omite.
     *
     * @param array<string, string|bool|null> $attrs
     */
    public static function render(array $attrs): string
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
