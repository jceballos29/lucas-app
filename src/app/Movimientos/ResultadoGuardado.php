<?php

declare(strict_types=1);

namespace App\Movimientos;

/** Resultado de guardar un movimiento desde el formulario (R1): guardado (con su aviso), errores por campo o «no encontrado». */
final class ResultadoGuardado
{
    /** @param array<string, string> $errors */
    private function __construct(
        public readonly bool $ok,
        public readonly bool $noEncontrado,
        public readonly string $tono,
        public readonly string $mensaje,
        public readonly array $errors,
    ) {
    }

    public static function guardado(string $tono, string $mensaje): self
    {
        return new self(true, false, $tono, $mensaje, []);
    }

    /** @param array<string, string> $errors */
    public static function conErrores(array $errors): self
    {
        return new self(false, false, 'error', '', $errors);
    }

    /** Un movimiento, cuenta o categoría que no son del usuario: como si no existieran (RNF-04). */
    public static function noEncontrado(): self
    {
        return new self(false, true, 'error', '', []);
    }
}
