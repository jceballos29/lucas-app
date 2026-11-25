<?php

declare(strict_types=1);

namespace App\Espacios;

/**
 * Reglas puras para espacios compartidos / cuenta familiar (Fase 10).
 * Garantiza que un espacio compartido nunca quede sin al menos un propietario y valida permisos según el rol del miembro.
 */
final class ReglasEspacio
{
    public const OK = 'ok';
    public const SIN_PERMISO = 'sin_permiso';
    public const NO_EXISTE = 'no_existe';
    public const ULTIMO_PROPIETARIO = 'ultimo_propietario';
    public const YA_ES_MIEMBRO = 'ya_es_miembro';
    public const ROL_INVALIDO = 'rol_invalido';

    /**
     * ¿Puede un miembro con `$rolActor` escribir datos financieros (cuentas, movimientos, presupuestos, metas...) en el espacio?
     * En el espacio personal (`$rolActor === null`) siempre puede.
     */
    public static function puedeEscribir(?string $rolActor): bool
    {
        return $rolActor === null || in_array($rolActor, ['propietario', 'editor'], true);
    }

    /**
     * Evalúa si `$actorId` (con rol `$rolActor`) puede cambiar el rol de `$objetivoId` (actualmente `$rolActualObjetivo`) a `$nuevoRol`.
     *
     * @param list<int> $idsPropietarios Lista de `id_usuario` que actualmente tienen rol `propietario` en el espacio.
     */
    public static function evaluarCambioRol(
        ?string $rolActor,
        int $objetivoId,
        ?string $rolActualObjetivo,
        string $nuevoRol,
        array $idsPropietarios,
    ): string {
        if ($rolActor !== 'propietario') {
            return self::SIN_PERMISO;
        }

        if ($rolActualObjetivo === null) {
            return self::NO_EXISTE;
        }

        if (!in_array($nuevoRol, ['propietario', 'editor', 'lector'], true)) {
            return self::ROL_INVALIDO;
        }

        if ($rolActualObjetivo === 'propietario' && $nuevoRol !== 'propietario') {
            $quedan = array_values(array_diff($idsPropietarios, [$objetivoId]));
            if ($quedan === []) {
                return self::ULTIMO_PROPIETARIO;
            }
        }

        return self::OK;
    }

    /**
     * Evalúa si `$actorId` (con rol `$rolActor`) puede retirar a `$objetivoId` (o si `$actorId === $objetivoId` puede abandonar el espacio).
     *
     * @param list<int> $idsPropietarios Lista de `id_usuario` con rol `propietario` en el espacio.
     */
    public static function evaluarSalidaMiembro(
        int $actorId,
        ?string $rolActor,
        int $objetivoId,
        ?string $rolObjetivo,
        array $idsPropietarios,
    ): string {
        if ($rolObjetivo === null) {
            return self::NO_EXISTE;
        }

        $esElMismo = $actorId === $objetivoId;
        if (!$esElMismo && $rolActor !== 'propietario') {
            return self::SIN_PERMISO;
        }

        if ($rolObjetivo === 'propietario') {
            $quedan = array_values(array_diff($idsPropietarios, [$objetivoId]));
            if ($quedan === []) {
                return self::ULTIMO_PROPIETARIO;
            }
        }

        return self::OK;
    }
}
