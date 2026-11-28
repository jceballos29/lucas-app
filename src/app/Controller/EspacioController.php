<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Espacio;
use App\Espacios\ReglasEspacio;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Response;
use App\Validation\EspacioValidator;
use App\View\View;
use RuntimeException;

/**
 * Gestión de espacios compartidos / cuenta familiar (Fase 10):
 *  - cambiar entre «Mi espacio personal» y un espacio compartido (`accion=cambiar`);
 *  - crear o editar un espacio compartido (`accion=crear`, `accion=editar`);
 *  - invitar personas por correo electrónico y cancelar invitaciones (`accion=invitar`, `accion=cancelar_invitacion`);
 *  - aceptar invitaciones recibidas por enlace (`?token=...`) o desde la pantalla (`accion=aceptar_invitacion`);
 *  - cambiar el rol de un miembro, retirarlo o abandonar el espacio (`accion=cambiar_rol`, `accion=retirar`, `accion=salir`);
 *  - eliminar el espacio compartido (`accion=eliminar_espacio`).
 */
final class EspacioController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();

        $errorsCrear = [];
        $oldCrear = ['nombre' => '', 'moneda' => 'COP'];
        $errorsInvitar = [];
        $oldInvitar = ['correo' => '', 'rol' => 'editor'];
        $errorsEditar = [];

        try {
            $repo = $this->container->espacios();
            $gestion = $this->container->gestionEspacios();

            // Si viene un token de invitación por GET, intentar aceptarla si corresponde al correo del usuario en sesión.
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['token']) && is_string($_GET['token'])) {
                $acepta = $gestion->aceptarInvitacion($user['id'], $user['email'], null, trim($_GET['token']));
                if ($acepta['codigo'] === ReglasEspacio::OK && $acepta['id_espacio'] !== null) {
                    set_active_espacio($acepta['id_espacio']);
                    Flash::set('ok', "Te uniste al espacio compartido «{$acepta['nombre']}» y ya está activo.");
                } elseif ($acepta['codigo'] === ReglasEspacio::CORREO_NO_VERIFICADO) {
                    Flash::set('error', 'Primero verifica tu correo (en Mi perfil) y vuelve a abrir el enlace de la invitación: solo se puede aceptar con un correo verificado.');
                } else {
                    Flash::set('error', 'El enlace de invitación no es válido, ya caducó o fue enviado a otro correo electrónico.');
                }
                Response::redirect('/espacios.php');
            }

            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                reject_foreign_origin();
                require_csrf();

                $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

                switch ($accion) {
                    case 'cambiar':
                        $espacioId = self::id($_POST['id_espacio'] ?? '');
                        if ($espacioId === 0) {
                            set_active_espacio(null);
                            Flash::set('info', 'Volviste a tu espacio personal.');
                            Response::redirect('/panel.php');
                        }
                        $esp = $repo->findForMember($user['id'], $espacioId);
                        if ($esp === null) {
                            Response::plain(404, "No encontrado.\n");
                        }
                        set_active_espacio($esp->id);
                        Flash::set('ok', "Cambiaste al espacio compartido «{$esp->nombre}».");
                        Response::redirect('/panel.php');

                        // no break
                    case 'crear':
                        $val = (new EspacioValidator())->validateEspacio($_POST);
                        $errorsCrear = $val['errors'];
                        $oldCrear = $val['data'];
                        if ($errorsCrear === []) {
                            $nuevoId = $gestion->crear($user['id'], $oldCrear['nombre'], $oldCrear['moneda']);
                            set_active_espacio($nuevoId);
                            Flash::set('ok', "Se creó el espacio compartido «{$oldCrear['nombre']}» y quedó activo. ¡Ahora puedes invitar a tu familia!");
                            Response::redirect('/espacios.php');
                        }
                        break;

                    case 'editar':
                        $espacioId = self::id($_POST['id_espacio'] ?? '');
                        $val = (new EspacioValidator())->validateEspacio($_POST);
                        $errorsEditar = $val['errors'];
                        if ($espacioId < 1) {
                            Response::plain(400, "Solicitud no válida.\n");
                        }
                        if ($errorsEditar === []) {
                            $codigo = $gestion->editar($user['id'], $espacioId, $val['data']['nombre'], $val['data']['moneda']);
                            if ($codigo === ReglasEspacio::NO_EXISTE) {
                                Response::plain(404, "No encontrado.\n");
                            }
                            if ($codigo === ReglasEspacio::SIN_PERMISO) {
                                Response::plain(403, "Solo un propietario puede editar el espacio compartido.\n");
                            }
                            Flash::set('ok', "Se guardaron los cambios de «{$val['data']['nombre']}».");
                            Response::redirect('/espacios.php');
                        }
                        break;

                    case 'invitar':
                        $espacioId = self::id($_POST['id_espacio'] ?? '');
                        if ($espacioId < 1) {
                            Response::plain(400, "Solicitud no válida.\n");
                        }
                        $val = (new EspacioValidator())->validateInvitacion($_POST);
                        $errorsInvitar = $val['errors'];
                        $oldInvitar = $val['data'];

                        if ($errorsInvitar === []) {
                            $res = $gestion->invitar($user['id'], $user['name'], $espacioId, $oldInvitar['correo'], $oldInvitar['rol']);
                            switch ($res['codigo']) {
                                case ReglasEspacio::OK:
                                    Flash::set('ok', "Se envió la invitación a {$oldInvitar['correo']} para unirse a «{$res['espacioNombre']}».");
                                    Response::redirect('/espacios.php');

                                    // no break
                                case ReglasEspacio::YA_ES_MIEMBRO:
                                    $errorsInvitar['correo'] = 'Esa persona ya es miembro de este espacio compartido.';
                                    break;

                                case ReglasEspacio::CORREO_NO_VERIFICADO:
                                    $errorsInvitar['correo'] = 'Verifica tu correo (en Mi perfil) antes de invitar a otras personas.';
                                    break;

                                case ReglasEspacio::LIMITE_USUARIO:
                                    http_response_code(429);
                                    $errorsInvitar['correo'] = 'Alcanzaste el límite de invitaciones de hoy (' . ReglasEspacio::INVITACIONES_POR_USUARIO . ' cada 24 horas). Inténtalo más tarde.';
                                    break;

                                case ReglasEspacio::LIMITE_DESTINO:
                                    http_response_code(429);
                                    $errorsInvitar['correo'] = 'Esa dirección ya recibió varias invitaciones hoy. Espera 24 horas antes de volver a invitarla.';
                                    break;

                                case ReglasEspacio::LIMITE_PENDIENTES:
                                    http_response_code(429);
                                    $errorsInvitar['correo'] = 'Este espacio ya tiene ' . ReglasEspacio::INVITACIONES_PENDIENTES_POR_ESPACIO . ' invitaciones pendientes. Cancela alguna o espera a que caduquen.';
                                    break;

                                case ReglasEspacio::SIN_PERMISO:
                                    Response::plain(403, "Solo un propietario puede invitar personas al espacio.\n");

                                    // no break
                                default:
                                    Response::plain(404, "No encontrado.\n");
                            }
                        }
                        break;

                    case 'cancelar_invitacion':
                        $espacioId = self::id($_POST['id_espacio'] ?? '');
                        $invitacionId = self::id($_POST['id_invitacion'] ?? '');
                        if ($espacioId < 1 || $invitacionId < 1) {
                            Response::plain(400, "Solicitud no válida.\n");
                        }
                        $codigo = $gestion->cancelarInvitacion($user['id'], $espacioId, $invitacionId);
                        if ($codigo === ReglasEspacio::SIN_PERMISO) {
                            Response::plain(403, "Solo un propietario puede cancelar invitaciones.\n");
                        }
                        if ($codigo === ReglasEspacio::NO_EXISTE) {
                            Response::plain(404, "No encontrado.\n");
                        }
                        Flash::set('info', 'Se canceló la invitación.');
                        Response::redirect('/espacios.php');

                        // no break
                    case 'aceptar_invitacion':
                        $invitacionId = self::id($_POST['id_invitacion'] ?? '');
                        if ($invitacionId < 1) {
                            Response::plain(400, "Solicitud no válida.\n");
                        }
                        $acepta = $gestion->aceptarInvitacion($user['id'], $user['email'], $invitacionId, null);
                        if ($acepta['codigo'] === ReglasEspacio::CORREO_NO_VERIFICADO) {
                            Flash::set('error', 'Primero verifica tu correo (en Mi perfil): solo se puede aceptar una invitación con un correo verificado.');
                            Response::redirect('/espacios.php');
                        }
                        if ($acepta['codigo'] !== ReglasEspacio::OK || $acepta['id_espacio'] === null) {
                            Response::plain(404, "No encontrado.\n");
                        }
                        set_active_espacio($acepta['id_espacio']);
                        Flash::set('ok', "Te uniste al espacio compartido «{$acepta['nombre']}» y quedó seleccionado como espacio activo.");
                        Response::redirect('/espacios.php');

                        // no break
                    case 'cambiar_rol':
                        $espacioId = self::id($_POST['id_espacio'] ?? '');
                        $miembroId = self::id($_POST['id_usuario'] ?? '');
                        $nuevoRol = is_string($_POST['rol'] ?? null) ? $_POST['rol'] : '';
                        if ($espacioId < 1 || $miembroId < 1 || !array_key_exists($nuevoRol, Espacio::ROLES)) {
                            Response::plain(400, "Solicitud no válida.\n");
                        }
                        $codigo = $gestion->cambiarRol($user['id'], $espacioId, $miembroId, $nuevoRol);
                        if ($codigo === ReglasEspacio::SIN_PERMISO) {
                            Response::plain(403, "Solo un propietario puede cambiar roles.\n");
                        }
                        if ($codigo === ReglasEspacio::NO_EXISTE) {
                            Response::plain(404, "No encontrado.\n");
                        }
                        if ($codigo === ReglasEspacio::ULTIMO_PROPIETARIO) {
                            Flash::set('error', 'El espacio compartido debe tener siempre al menos un propietario. Asigna a otra persona como propietario primero.');
                        } else {
                            Flash::set('ok', 'Se actualizó el rol del miembro.');
                        }
                        Response::redirect('/espacios.php');

                        // no break
                    case 'retirar':
                    case 'salir':
                        $espacioId = self::id($_POST['id_espacio'] ?? '');
                        $miembroId = $accion === 'salir' ? $user['id'] : self::id($_POST['id_usuario'] ?? '');
                        if ($espacioId < 1 || $miembroId < 1) {
                            Response::plain(400, "Solicitud no válida.\n");
                        }
                        $codigo = $gestion->retirarMiembro($user['id'], $espacioId, $miembroId);
                        if ($codigo === ReglasEspacio::SIN_PERMISO) {
                            Response::plain(403, "Solo un propietario puede retirar miembros.\n");
                        }
                        if ($codigo === ReglasEspacio::NO_EXISTE) {
                            Response::plain(404, "No encontrado.\n");
                        }
                        if ($codigo === ReglasEspacio::ULTIMO_PROPIETARIO) {
                            Flash::set('error', 'No puedes salir siendo el único propietario del espacio. Nombra primero a otro miembro como propietario o elimina el espacio.');
                            Response::redirect('/espacios.php');
                        }
                        if ($miembroId === $user['id']) {
                            if (($user['espacio_id'] ?? null) === $espacioId) {
                                set_active_espacio(null);
                            }
                            Flash::set('info', 'Saliste del espacio compartido.');
                        } else {
                            Flash::set('ok', 'Se retiró al miembro del espacio compartido.');
                        }
                        Response::redirect('/espacios.php');

                        // no break
                    case 'eliminar_espacio':
                        $espacioId = self::id($_POST['id_espacio'] ?? '');
                        if ($espacioId < 1) {
                            Response::plain(400, "Solicitud no válida.\n");
                        }
                        $codigo = $gestion->eliminarEspacio($user['id'], $espacioId);
                        if ($codigo === ReglasEspacio::SIN_PERMISO) {
                            Response::plain(403, "Solo un propietario puede eliminar el espacio compartido.\n");
                        }
                        if ($codigo === ReglasEspacio::NO_EXISTE) {
                            Response::plain(404, "No encontrado.\n");
                        }
                        if (($user['espacio_id'] ?? null) === $espacioId) {
                            set_active_espacio(null);
                        }
                        Flash::set('ok', 'Se eliminó el espacio compartido y sus datos.');
                        Response::redirect('/espacios.php');

                        // no break
                    default:
                        Response::plain(400, "Solicitud no válida.\n");
                }
            }

            // Recargar sesión tras posibles cambios
            $user = current_user() ?? $user;
            $espacios = $repo->listByUser($user['id']);
            // A1: sin correo verificado no se listan las invitaciones dirigidas a esa dirección (revelarían nombres de espacios ajenos a quien solo
            // la registró).
            $invitacionesRecibidas = ($user['verified'] ?? false) ? $repo->listInvitationsForEmail($user['email']) : [];

            $detallesEspacios = [];
            foreach ($espacios as $e) {
                $detallesEspacios[$e->id] = [
                    'miembros' => $repo->listMembers($e->id),
                    'invitaciones' => $e->esPropietario() ? $repo->listInvitationsByEspacio($e->id) : [],
                ];
            }
        } catch (RuntimeException $error) {
            [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
            Response::plain($status, $message . "\n");
        }

        View::page('app/espacios', [
            'espacios' => $espacios,
            'detallesEspacios' => $detallesEspacios,
            'invitacionesRecibidas' => $invitacionesRecibidas,
            'espacioActivoId' => $user['espacio_id'] ?? null,
            'oldCrear' => $oldCrear,
            'errorsCrear' => $errorsCrear,
            'oldInvitar' => $oldInvitar,
            'errorsInvitar' => $errorsInvitar,
            'errorsEditar' => $errorsEditar,
            'flash' => Flash::pull(),
        ], 'Espacios y Cuenta Familiar', $user, 'app', 'espacios');
    }

    private static function id(mixed $raw): int
    {
        return is_string($raw) && ctype_digit($raw) && strlen($raw) < 10 ? (int) $raw : 0;
    }
}
