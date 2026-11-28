<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Usuario;

/**
 * Detecta el administrador de DESARROLLO (admin@lucas.local) funcionando con su clave pública fuera de un entorno local (C1 de
 * docs/AUDITORIA.md, RNF-23). La seed ya no lo crea en producción (database/04-dev-admin.sh), pero una base antigua o un
 * volcado restaurado desde desarrollo sí podrían traerlo: entonces salud.php responde 503 y el dashboard de administración
 * muestra un aviso, hasta que se cambie la clave o se desactive la cuenta.
 *
 * Es puro (recibe el usuario y el entorno) para probarlo sin base de datos.
 */
final class AdminSemilla
{
    public const CORREO = 'admin@lucas.local';

    /** Hash bcrypt de la clave pública «Admin#2026dev»; es el mismo de database/dev/admin.sql (lo comprueba unit.php). */
    public const HASH_PUBLICO = '$2y$12$sIpi.EhC27iIqx2CgHUppuMiS8/IP67c5bVybuAVVIQ/jxBd/4Una';

    /**
     * ¿Está el administrador de la seed ACTIVO y con la clave pública en un entorno que no es «local»?
     * Si alguien cambió su contraseña (el hash ya no es el de la seed) o lo desactivó, ya no es un riesgo.
     */
    public static function expuesto(?Usuario $usuario, string $entorno): bool
    {
        return $entorno !== 'local'
            && $usuario !== null
            && $usuario->activo
            && $usuario->esAdmin()
            && hash_equals(self::HASH_PUBLICO, $usuario->contrasenaHash);
    }
}
