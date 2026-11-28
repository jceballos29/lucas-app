<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\Dinero;

/** Reglas del formulario de meta de ahorro (RF-18). */
final class MetaValidator
{
    public const NOMBRE_MIN = 2;
    public const NOMBRE_MAX = 80;

    /**
     * @param array<string, mixed> $input Normalmente $_POST (nombre, monto_objetivo, fecha_limite).
     * @param string $hoy Hoy (`AAAA-MM-DD`): una fecha límite NUEVA no puede ser anterior.
     * @param string|null $fechaActual La fecha límite que ya tenía la meta (al editar): conservarla no se reprocha aunque ya haya pasado.
     * @return array{data: array{nombre: string, monto_objetivo: string, fecha_limite: string|null}, errors: array<string, string>}
     */
    public function validate(array $input, string $hoy, ?string $fechaActual = null): array
    {
        $texto = static fn (string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';
        $nombre = trim(preg_replace('/\s+/u', ' ', $texto('nombre')) ?? '');
        $fecha = $texto('fecha_limite');
        $errors = [];

        $largo = mb_strlen($nombre);
        if ($largo < self::NOMBRE_MIN || $largo > self::NOMBRE_MAX) {
            $errors['nombre'] = 'Escribe un nombre para la meta (entre ' . self::NOMBRE_MIN . ' y ' . self::NOMBRE_MAX . ' caracteres).';
        }

        $objetivo = Dinero::parse($texto('monto_objetivo'));
        if ($objetivo === null) {
            $errors['monto_objetivo'] = 'Escribe un monto válido, por ejemplo 3.000.000.' . Dinero::pistaDeFormato($texto('monto_objetivo'));
        } elseif ($objetivo === '0.00' || str_starts_with($objetivo, '-')) {
            $errors['monto_objetivo'] = 'El objetivo debe ser mayor que cero.';
        }

        if ($fecha !== '') {
            if (!MovimientoValidator::validDate($fecha)) {
                $errors['fecha_limite'] = 'Elige una fecha válida o déjala vacía.';
            } elseif ($fecha < $hoy && $fecha !== $fechaActual) {
                $errors['fecha_limite'] = 'La fecha límite no puede ser anterior a hoy.';
            }
        }

        return ['data' => ['nombre' => $nombre, 'monto_objetivo' => $objetivo ?? '0.00', 'fecha_limite' => $fecha === '' ? null : $fecha], 'errors' => $errors];
    }
}
