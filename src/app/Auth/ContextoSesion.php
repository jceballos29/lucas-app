<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\EspacioRepository;
use App\Repository\SesionRepository;
use App\Repository\UsuarioRepository;

/**
 * La lógica de «quién está conectado» (R5 de docs/AUDITORIA.md), sin superglobales ni `Container::shared()`: recibe sus repositorios y
 * trabaja sobre un arreglo de sesión que se le pasa. Así se prueba con datos de verdad pero sin cookies ni `$_SESSION`, y se puede reutilizar
 * desde consola. `app/Security/session.php` es la fachada fina que la conecta con `$_SESSION`, las cookies y `Container`.
 *
 * Reglas, en cada petición: la sesión caduca por inactividad; el usuario se vuelve a leer de la BD (si lo desactivaron, o cambió su
 * contraseña desde que se abrió, la sesión deja de valer; si le cambiaron el rol, vale al instante); la sesión debe seguir en el registro
 * de sesiones (cierre remoto, tope de 10, vida máxima de 12 h); y el espacio compartido activo se conserva solo si sigue siendo miembro.
 */
final class ContextoSesion
{
    /** Segundos de inactividad tras los que una sesión caduca. */
    public const INACTIVIDAD = 1800;

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly SesionRepository $sesiones,
        private readonly EspacioRepository $espacios,
        private readonly int $inactividad = self::INACTIVIDAD,
    ) {
    }

    /** Huella corta del hash de una contraseña (no lo revela): sirve para saber si la contraseña cambió desde que se abrió la sesión. */
    public static function huella(string $hashContrasena): string
    {
        return substr(hash('sha256', $hashContrasena), 0, 32);
    }

    /** ¿Caducó por inactividad? Una marca ausente o del futuro (el reloj retrocedió) tampoco es de fiar. */
    public static function haCaducado(mixed $ultimaActividad, int $ahora, int $inactividad = self::INACTIVIDAD): bool
    {
        if (!is_int($ultimaActividad)) {
            return true;
        }

        return $ultimaActividad > $ahora || $ahora - $ultimaActividad >= $inactividad;
    }

    /**
     * Evalúa la sesión. Si sigue viva, renueva en `$sesion` la marca de actividad y la copia del nombre, correo y rol, y descarta el
     * espacio activo si ya no es miembro. No cierra nada: devuelve qué hacer ({@see EstadoSesion::cerrar()}).
     *
     * @param array<string, mixed> $sesion El contenido de `$_SESSION` (se modifica).
     * @param string $idSesionHash Hash del id de sesión de PHP; vacío si no hay (entonces no se consulta el registro).
     */
    public function resolver(array &$sesion, string $idSesionHash, int $ahora): EstadoSesion
    {
        if (!isset($sesion['user_id'])) {
            return EstadoSesion::anonima();
        }

        if (self::haCaducado($sesion['last_seen'] ?? null, $ahora, $this->inactividad)) {
            return EstadoSesion::caducada();
        }

        $actual = $this->usuarios->findById((int) $sesion['user_id']);

        if ($actual === null || !$actual->activo) {
            return EstadoSesion::revocada();
        }

        // La sesión guarda una huella del hash de la contraseña con la que se abrió: si cambió, las demás sesiones abiertas dejan de valer
        // (quien robó una sesión la pierde al cambiar la clave).
        if (!hash_equals((string) ($sesion['pw_fp'] ?? ''), self::huella($actual->contrasenaHash))) {
            return EstadoSesion::revocada();
        }

        if ($idSesionHash !== '' && !$this->sesiones->validarYRenovar($actual->id, $idSesionHash)) {
            return EstadoSesion::revocada();
        }

        // Espacio compartido activo (Fase 10): si ya no es miembro, o el espacio se eliminó, se vuelve al espacio personal.
        $espacioId = null;
        $espacioNombre = null;
        $espacioRol = null;
        $moneda = $actual->moneda;

        $pedido = $sesion['espacio_id'] ?? null;
        if (is_int($pedido) && $pedido > 0) {
            $espacio = $this->espacios->findForMember($actual->id, $pedido);
            if ($espacio !== null) {
                $espacioId = $espacio->id;
                $espacioNombre = $espacio->nombre;
                $espacioRol = $espacio->rolUsuario;
                $moneda = $espacio->moneda;
            } else {
                unset($sesion['espacio_id']);
            }
        }

        $sesion['last_seen'] = $ahora;
        $sesion['user_name'] = $actual->nombre;
        $sesion['user_email'] = $actual->correo;
        $sesion['user_role'] = $actual->rol;

        return EstadoSesion::activa([
            'id' => $actual->id,
            'name' => $actual->nombre,
            'email' => $actual->correo,
            'role' => $actual->rol,
            'verified' => $actual->correoVerificado,
            'dos_pasos' => $actual->dosPasos,
            'espacio_id' => $espacioId,
            'espacio_nombre' => $espacioNombre,
            'espacio_rol' => $espacioRol,
        ], $moneda);
    }

    /** Anota una sesión recién abierta en el registro (cierre remoto, tope, vida máxima). */
    public function registrarInicio(int $userId, string $idSesionHash, string $ip, string $userAgent): void
    {
        $this->sesiones->registrar($userId, $idSesionHash, $ip, $userAgent, $this->inactividad);
    }

    /** Quita una sesión del registro (al cerrarla). */
    public function olvidar(string $idSesionHash): void
    {
        $this->sesiones->eliminarPorHash($idSesionHash);
    }
}
