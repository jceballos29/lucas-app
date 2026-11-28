<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Support\ResultadoSubida;

/**
 * Lo que llegó del formulario de movimiento, ya leído y validado (R1): si es un gasto/ingreso dividido o normal, los datos para guardar,
 * los errores por campo y lo que hay que volver a mostrar en el formulario. Inmutable y sin I/O.

 */
final class EnvioMovimiento
{
    /**
     * @param array<string, mixed> $datos Normal: los datos validados. Dividido: la parte común («base»).
     * @param list<array<string, mixed>> $partes Solo en un movimiento dividido.
     * @param array<string, string> $errors
     * @param array<string, string> $old Valores para repoblar el formulario.
     * @param list<array{id_categoria: string, monto: string, descripcion: string}> $splitOld
     */
    public function __construct(
        public readonly bool $dividido,
        public readonly array $datos,
        public readonly array $partes,
        public readonly array $errors,
        public readonly array $old,
        public readonly array $splitOld,
        public readonly ResultadoSubida $subida,
    ) {
    }

    public function valido(): bool
    {
        return $this->errors === [];
    }
}
