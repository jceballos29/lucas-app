<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\VerificacionCorreo;
use App\Container;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Response;
use App\Mail\MailException;
use App\Support\Mes;
use App\Usuarios\EliminarCuenta;
use App\Validation\ClaveValidator;
use App\Validation\PerfilValidator;
use App\View\View;
use RuntimeException;

/** Perfil del usuario (RF-03, RNF-20): nombre y moneda, cambio de contraseña y eliminación de la cuenta con todos sus datos. */
final class PerfilController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();
        $errors = [];
        $notice = null;
        $codigosNuevos = null;

        try {
            $usuario = $this->container->usuarios()->findById($user['id']);

            if ($usuario === null) {
                Response::plain(404, "No encontrado.\n");
            }

            $old = ['nombre' => $usuario->nombre, 'moneda' => $usuario->moneda];

            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                reject_foreign_origin();
                require_csrf();

                switch (is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '') {
                    case 'datos':
                        $result = (new PerfilValidator())->validate($_POST);
                        $errors = $result['errors'];
                        $old = $result['data'];

                        if ($errors === []) {
                            $this->container->usuarios()->updateProfile($usuario->id, $old['nombre'], $old['moneda']);
                            Flash::set('ok', 'Se guardaron tus datos.');
                            Response::redirect('/perfil.php');
                        }
                        break;

                    case 'reenviar_verificacion':
                        Flash::set(...match ($this->container->verificacionCorreo()->enviar($usuario)) {
                            VerificacionCorreo::OK => ['ok', "Te enviamos un enlace nuevo a {$usuario->correo}. Revisa también la carpeta de spam; vale " . VerificacionCorreo::VIGENCIA_HORAS . ' horas.'],
                            VerificacionCorreo::YA_VERIFICADO => ['info', 'Tu correo ya está verificado.'],
                            default => ['error', 'Ya te enviamos varios enlaces en la última hora. Revisa tu correo (y el spam) o inténtalo de nuevo más tarde.'],
                        });
                        Response::redirect('/perfil.php');

                    case 'dos_pasos_iniciar':
                        // El secreto de la configuración en curso vive solo en la sesión hasta que el usuario lo confirma con un código.
                        if ($usuario->dosPasos) {
                            Response::redirect('/perfil.php#dos-pasos');
                        }
                        $this->container->dosPasos(); // falla con un error claro si falta APP_KEY, antes de enseñar un secreto que no se podría guardar
                        $_SESSION['dos_pasos_config'] = \App\Auth\Totp::generarSecreto();
                        Response::redirect('/perfil.php#dos-pasos');

                    case 'dos_pasos_cancelar':
                        unset($_SESSION['dos_pasos_config']);
                        Response::redirect('/perfil.php#dos-pasos');

                    case 'dos_pasos_activar':
                        $secreto = is_string($_SESSION['dos_pasos_config'] ?? null) ? $_SESSION['dos_pasos_config'] : '';
                        if ($usuario->dosPasos || $secreto === '') {
                            Response::redirect('/perfil.php#dos-pasos');
                        }

                        $errors = $this->limitarCodigoDosPasos($usuario->correo, 'dp_codigo');
                        if ($errors === []) {
                            $codigosNuevos = $this->container->dosPasos()->activar($usuario->id, $secreto, self::field('codigo'));
                            if ($codigosNuevos === null) {
                                $this->container->limitador()->registrar('login', '2fa:' . $usuario->correo, self::ip());
                                $errors['dp_codigo'] = 'El código no coincide. Revisa que añadiste la clave correcta y que la hora de tu teléfono es automática.';
                            } else {
                                unset($_SESSION['dos_pasos_config']);
                                // Quien tuviera otra sesión abierta (p. ej. robada) la pierde: a partir de ahora se exige el segundo factor.
                                $this->container->sesiones()->eliminarOtrasDeUsuario($usuario->id, \App\Repository\SesionRepository::hashId((string) session_id()));
                                $usuario = $this->container->usuarios()->findById($usuario->id) ?? $usuario;
                            }
                        }
                        break;

                    case 'dos_pasos_regenerar':
                    case 'dos_pasos_desactivar':
                        $accion = (string) $_POST['accion'];
                        if (!$usuario->dosPasos) {
                            Response::redirect('/perfil.php#dos-pasos');
                        }

                        $errors = $this->verificarClave($usuario->correo, $usuario->contrasenaHash, self::field('dp_clave'), 'dp_clave');
                        if ($errors === []) {
                            $errors = $this->limitarCodigoDosPasos($usuario->correo, 'dp_codigo2');
                        }
                        if ($errors === [] && $this->container->dosPasos()->comprobar($usuario->id, self::field('codigo')) === null) {
                            $this->container->limitador()->registrar('login', '2fa:' . $usuario->correo, self::ip());
                            $errors['dp_codigo2'] = 'El código no es correcto.';
                        }

                        if ($errors === [] && $accion === 'dos_pasos_regenerar') {
                            $codigosNuevos = $this->container->dosPasos()->regenerarCodigos($usuario->id);
                        } elseif ($errors === [] && $this->adminObligatorio($usuario)) {
                            $errors['dp_codigo2'] = 'Los administradores no pueden desactivar la verificación en dos pasos.';
                        } elseif ($errors === []) {
                            $this->container->dosPasos()->desactivar($usuario->id);
                            Flash::set('ok', 'Desactivaste la verificación en dos pasos. Puedes volver a activarla cuando quieras.');
                            Response::redirect('/perfil.php#dos-pasos');
                        }
                        break;

                    case 'notificaciones':
                        $quierePresupuestos = ($_POST['notif_presupuestos'] ?? '') === '1';
                        $quiereResumen = ($_POST['notif_resumen_mensual'] ?? '') === '1';

                        // A1: no se activan avisos por correo hacia una dirección que aún no demostró ser del usuario (desactivarlos sí se puede).
                        if (!$usuario->correoVerificado && (($quierePresupuestos && !$usuario->notifPresupuestos) || ($quiereResumen && !$usuario->notifResumenMensual))) {
                            Flash::set('error', 'Verifica tu correo antes de activar los avisos: abre el enlace que te enviamos o pide otro más abajo.');
                            Response::redirect('/perfil.php#correo');
                        }

                        $this->container->usuarios()->updateNotificaciones($usuario->id, $quierePresupuestos, $quiereResumen);
                        Flash::set('ok', 'Se guardaron tus preferencias de notificaciones por correo.');
                        Response::redirect('/perfil.php');

                    case 'resumen_prueba':
                        if (!$usuario->correoVerificado) {
                            Flash::set('error', 'Verifica tu correo antes de pedir el resumen de prueba.');
                            Response::redirect('/perfil.php#correo');
                        }

                        // Límite anti-abuso del correo: un resumen de prueba por usuario y hora.
                        $claveTope = sprintf('resumen-prueba:u%d:%s', $usuario->id, gmdate('YmdH'));

                        if (!$this->container->notificaciones()->reservar($usuario->id, null, $claveTope)) {
                            Flash::set('error', 'Ya te enviamos un resumen de prueba hace poco. Inténtalo de nuevo dentro de una hora.');
                            Response::redirect('/perfil.php');
                        }

                        $anterior = Mes::anterior((int) date('n'), (int) date('Y'));

                        try {
                            $enviado = $this->container->resumenMensual()->enviar($usuario, $anterior['mes'], $anterior['anio'], true);
                        } catch (MailException) {
                            $enviado = false;
                        }

                        if (!$enviado) {
                            $this->container->notificaciones()->liberar($claveTope);
                        }

                        Flash::set($enviado ? 'ok' : 'error', $enviado
                            ? 'Te enviamos el resumen de ' . Mes::etiqueta($anterior['mes'], $anterior['anio']) . " a {$usuario->correo}."
                            : 'No se pudo enviar el correo. Revisa la configuración de correo e inténtalo de nuevo.');
                        Response::redirect('/perfil.php');

                    case 'clave':
                        $actual = self::field('clave_actual');
                        $nueva = self::field('clave_nueva');
                        $errors = $this->verificarClave($usuario->correo, $usuario->contrasenaHash, $actual, 'clave_actual');

                        if ($errors === []) {
                            $errors = ClaveValidator::errores($nueva, self::field('clave_nueva2'), 'clave_nueva', 'clave_nueva2');
                        }

                        if ($errors === [] && hash_equals($actual, $nueva)) {
                            $errors['clave_nueva'] = 'La contraseña nueva debe ser distinta de la actual.';
                        }

                        if ($errors === []) {
                            $hash = password_hash($nueva, PASSWORD_DEFAULT);
                            $this->container->usuarios()->updatePasswordHash($usuario->id, $hash);
                            // Esta sesión sigue abierta (se renueva su id y su huella); las demás sesiones de la cuenta se cierran.
                            $oldSidHash = \App\Repository\SesionRepository::hashId((string) session_id());
                            refresh_session_fingerprint($hash);
                            session_regenerate_id(true);
                            $newSidHash = \App\Repository\SesionRepository::hashId((string) session_id());
                            $sesionesRepo = $this->container->sesiones();
                            $sesionesRepo->rotarId(
                                $usuario->id,
                                $oldSidHash,
                                $newSidHash,
                                (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
                                (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Navegador web'),
                            );
                            $sesionesRepo->eliminarOtrasDeUsuario($usuario->id, $newSidHash);
                            Flash::set('ok', 'Tu contraseña se cambió. Las demás sesiones abiertas de tu cuenta se cerraron.');
                            Response::redirect('/perfil.php');
                        }
                        break;

                    case 'cerrar_sesion':
                        $idSesion = self::field('id_sesion');
                        $actualHash = \App\Repository\SesionRepository::hashId((string) session_id());
                        if (preg_match('/^[a-f0-9]{64}$/', $idSesion) !== 1) {
                            Response::plain(400, "Solicitud no válida.\n");
                        }
                        if (hash_equals($actualHash, $idSesion)) {
                            log_out_user();
                            Response::redirect('/login.php');
                        }
                        if (!$this->container->sesiones()->eliminarDeUsuario($usuario->id, $idSesion)) {
                            Response::plain(404, "No encontrado.\n");
                        }
                        Flash::set('ok', 'Se cerró la sesión del dispositivo seleccionado.');
                        Response::redirect('/perfil.php');

                    case 'cerrar_otras_sesiones':
                        $actualHash = \App\Repository\SesionRepository::hashId((string) session_id());
                        $cerradas = $this->container->sesiones()->eliminarOtrasDeUsuario($usuario->id, $actualHash);
                        Flash::set('ok', $cerradas === 1
                            ? 'Se cerró la otra sesión activa de tu cuenta.'
                            : "Se cerraron las demás sesiones activas ($cerradas).");
                        Response::redirect('/perfil.php');

                    case 'eliminar':
                        $errors = $this->verificarClave($usuario->correo, $usuario->contrasenaHash, self::field('eliminar_clave'), 'eliminar_clave');

                        if ($errors === []) {
                            $resultado = $this->container->eliminarCuenta()->eliminar($usuario->id);

                            if ($resultado === EliminarCuenta::OK) {
                                log_out_user();
                                Response::redirect('/login.php?msg=deleted');
                            }

                            $errors['eliminar_clave'] = $resultado === EliminarCuenta::ES_ADMIN
                                ? 'Un administrador no puede eliminar su propia cuenta: pídele a otro administrador que te quite el rol primero.'
                                : 'No se pudo eliminar la cuenta.';
                        }
                        break;

                    default:
                        Response::plain(400, "Solicitud no válida.\n");
                }
            }
            $dosPasosSecreto = is_string($_SESSION['dos_pasos_config'] ?? null) && !$usuario->dosPasos ? $_SESSION['dos_pasos_config'] : null;
            $codigosQuedan = $usuario->dosPasos ? $this->container->dosPasos()->codigosDisponibles($usuario->id) : 0;
            $sesionesActivas = $this->container->sesiones()->listByUser($usuario->id, SESSION_IDLE_TIMEOUT);
            $sesionActualHash = \App\Repository\SesionRepository::hashId((string) session_id());
        } catch (RuntimeException $error) {
            [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
            Response::plain($status, $message . "\n");
        }

        View::page('app/perfil', [
            'usuario' => $usuario,
            'old' => $old,
            'errors' => $errors,
            'sesiones' => $sesionesActivas,
            'sesionActualHash' => $sesionActualHash,
            'flash' => Flash::pull(),
            'dosPasosSecreto' => $dosPasosSecreto,
            'codigosNuevos' => $codigosNuevos,
            'codigosQuedan' => $codigosQuedan,
            'adminObligatorio' => $this->adminObligatorio($usuario),
        ], 'Mi perfil', $user, $user['role'] === 'admin' ? 'admin' : 'app', 'perfil');
    }

    /**
     * Comprueba la contraseña ACTUAL con límite de intentos (RNF-24): quien tenga una sesión abierta no puede usar este formulario
     * para adivinar la contraseña.
     *
     * @return array<string, string> Vacío si es correcta.
     */
    private function verificarClave(string $correo, string $hash, string $clave, string $campo): array
    {
        $limitador = $this->container->limitador();
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        if ($limitador->bloqueado('clave', $correo, $ip)) {
            http_response_code(429);
            header('Retry-After: 900');

            return [$campo => 'Demasiados intentos. Espera unos minutos antes de volver a intentarlo.'];
        }

        if (!password_verify($clave, $hash)) {
            $limitador->registrar('clave', $correo, $ip);

            return [$campo => 'La contraseña actual no es correcta.'];
        }

        $limitador->perdonar('clave', $correo, $ip);

        return [];
    }

    /** ¿Es un administrador al que se le exige el segundo factor (no puede desactivarlo)? */
    private function adminObligatorio(\App\Domain\Usuario $usuario): bool
    {
        return $usuario->esAdmin() && \App\Auth\DosPasos::obligatorioParaAdmin((string) getenv('APP_ENV'), getenv('ADMIN_2FA_OBLIGATORIO') === false ? null : (string) getenv('ADMIN_2FA_OBLIGATORIO'));
    }

    /**
     * Límite de intentos de código del segundo factor desde el perfil (mismos contadores que el inicio de sesión: «2fa:correo»).
     *
     * @return array<string, string>
     */
    private function limitarCodigoDosPasos(string $correo, string $campo): array
    {
        if ($this->container->limitador()->bloqueado('login', '2fa:' . $correo, self::ip(), \App\Auth\LimitadorIntentos::MAX_POR_CORREO_DOS_PASOS)) {
            http_response_code(429);
            header('Retry-After: 900');

            return [$campo => 'Demasiados intentos. Espera unos minutos antes de volver a intentarlo.'];
        }

        return [];
    }

    private static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function field(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    }
}
