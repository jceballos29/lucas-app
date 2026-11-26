<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Response;
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

        $limitador->perdonar('clave', $correo);

        return [];
    }

    private static function field(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    }
}
