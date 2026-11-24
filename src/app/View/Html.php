<?php

declare(strict_types=1);

namespace App\View;

use Closure;
use Stringable;

/**
 * Fragmento de HTML ya construido y escapado (lo que devuelven los componentes de {@see Ui}).
 *
 * Sirve para distinguir lo seguro de lo que no: cuando un componente recibe un `Html` lo inserta tal cual; cuando
 * recibe cualquier otra cosa (texto del usuario, de la BD...) lo escapa. Así se pueden anidar componentes sin
 * escapar dos veces y sin abrir la puerta a XSS.
 */
final class Html implements Stringable
{
    public function __construct(private readonly string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }

    /** Une varios fragmentos (o textos, que se escapan) en uno solo. */
    public static function join(iterable $parts): self
    {
        $out = '';
        foreach ($parts as $part) {
            $out .= self::of($part);
        }

        return new self($out);
    }

    /**
     * Convierte un valor en HTML seguro: `Html` pasa igual, un `Closure` se ejecuta y se captura lo que imprime
     * (permite escribir HTML en la vista dentro de la función), `null` es vacío y lo demás se escapa.
     */
    public static function of(mixed $value): string
    {
        if ($value instanceof self) {
            return $value->html;
        }

        if ($value instanceof Closure) {
            ob_start();
            $value();

            return (string) ob_get_clean();
        }

        return $value === null ? '' : escape($value);
    }
}
