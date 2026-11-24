<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\Usuario;
use InvalidArgumentException;

/**
 * Reglas de RF-33, puras (sin BD): un administrador no puede desactivarse ni quitarse el rol a sí mismo, ni dejar al
 * sistema sin ningún administrador activo. Quien las aplica (GestionUsuarios) le pasa los datos ya BLOQUEADOS en una
 * transacción; aquí solo se decide.
 */
final class ReglasAdmin
{
    public const OK = 'ok';
    public const NO_EXISTE = 'no_existe';
    public const PROPIO = 'propio';
    public const ULTIMO_ADMIN = 'ultimo_admin';
    public const SIN_PERMISO = 'sin_permiso';
    public const SIN_CAMBIO = 'sin_cambio';

    public const ACCIONES = ['activar', 'desactivar', 'hacer_admin', 'quitar_admin'];

    /**
     * @param list<int> $adminsActivos Ids de los administradores activos en este momento (bloqueados).
     * @param string $accion Una de {@see ACCIONES}.
     * @return string Una de las constantes de resultado.
     */
    public static function evaluar(array $adminsActivos, int $actorId, ?Usuario $objetivo, string $accion): string
    {
        if (!in_array($accion, self::ACCIONES, true)) {
            throw new InvalidArgumentException("Acción no válida: $accion");
        }

        // Quien actúa tiene que seguir siendo admin ACTIVO ahora mismo: si otro administrador lo desactivó mientras
        // tanto, esta petición ya no tiene permiso (la sesión se le cerrará en su siguiente petición).
        if (!in_array($actorId, $adminsActivos, true)) {
            return self::SIN_PERMISO;
        }

        if ($objetivo === null) {
            return self::NO_EXISTE;
        }

        if ($objetivo->id === $actorId) {
            return self::PROPIO;
        }

        $cambia = match ($accion) {
            'activar' => !$objetivo->activo,
            'desactivar' => $objetivo->activo,
            'hacer_admin' => !$objetivo->esAdmin(),
            'quitar_admin' => $objetivo->esAdmin(),
        };

        if (!$cambia) {
            return self::SIN_CAMBIO;
        }

        $pierdeAdmin = in_array($accion, ['desactivar', 'quitar_admin'], true) && in_array($objetivo->id, $adminsActivos, true);

        if ($pierdeAdmin && array_diff($adminsActivos, [$objetivo->id]) === []) {
            return self::ULTIMO_ADMIN;
        }

        return self::OK;
    }
}
