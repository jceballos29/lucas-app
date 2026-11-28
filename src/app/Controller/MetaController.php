<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Peticion;
use App\Http\Response;
use App\Metas\GestionMetas;
use App\Metas\ReglasAporte;
use App\Support\Dinero;
use App\Validation\AporteValidator;
use App\Validation\MetaValidator;
use App\View\View;
use RuntimeException;

/** Metas de ahorro (RF-18 a RF-20): listado con avance, alta, edición, aportes desde una cuenta, cancelación y baja. */
final class MetaController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                require_espacio_write($user);
                $this->accion($user['id'], $espacioId);
            }

            $metas = $this->container->metas()->listByUser($user['id'], $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/metas', ['metas' => $metas, 'hoy' => date('Y-m-d'), 'flash' => Flash::pull()], 'Metas de ahorro', $user, 'app', 'metas');
    }

    /** Alta (sin `id`) o edición (`?id=`) de una meta en curso. */
    public function form(): void
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $raw = $isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '');
        $id = self::id($raw);

        if ($raw !== '' && $id < 1) {
            Response::plain(404, "No encontrado.\n");
        }

        try {
            $meta = $id > 0 ? $this->container->metas()->findOwned($user['id'], $id, $espacioId) : null;

            if ($id > 0 && $meta === null) {
                Response::plain(404, "No encontrado.\n");
            }

            // Una meta lograda o cancelada ya no se edita.
            if ($meta !== null && !$meta->enCurso()) {
                Flash::set('error', 'Esa meta ya no se puede editar: está ' . strtolower(\App\Domain\Meta::ESTADOS[$meta->estado]) . '.');
                Response::redirect('/metas.php');
            }

            $errors = [];
            $old = $meta === null
                ? ['nombre' => '', 'monto_objetivo' => '', 'fecha_limite' => '']
                : ['nombre' => $meta->nombre, 'monto_objetivo' => Dinero::paraCampo($meta->montoObjetivo), 'fecha_limite' => $meta->fechaLimite ?? ''];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $result = (new MetaValidator())->validate($_POST, date('Y-m-d'), $meta?->fechaLimite);
                $errors = $result['errors'];
                $data = $result['data'];
                $old = [
                    'nombre' => $data['nombre'],
                    'monto_objetivo' => Peticion::post('monto_objetivo'),
                    'fecha_limite' => $data['fecha_limite'] ?? '',
                ];

                if ($errors === []) {
                    if ($meta === null) {
                        $this->container->metas()->create($user['id'], $data['nombre'], $data['monto_objetivo'], $data['fecha_limite'], $espacioId);
                        Flash::set('ok', "Se creó la meta «{$data['nombre']}». ¡Ahora añade tu primer aporte!");
                        Response::redirect('/metas.php');
                    }

                    $saved = $this->container->gestionMetas()->editar($user['id'], $meta->id, $data, $espacioId);

                    if ($saved['codigo'] === GestionMetas::OK) {
                        Flash::set('ok', "Se guardaron los cambios de «{$data['nombre']}»." . ($saved['lograda'] ? ' Con el nuevo objetivo, ¡la meta ya está lograda!' : ''));
                    } else {
                        Flash::set('error', 'Esa meta ya no se puede editar.');
                    }

                    Response::redirect('/metas.php');
                }
            }
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/meta-form', ['meta' => $meta, 'old' => $old, 'errors' => $errors], $meta === null ? 'Nueva meta' : 'Editar meta', $user, 'app', 'metas');
    }

    /** Formulario y registro de un aporte a una meta (`?id=` de la meta), con el historial de aportes. */
    public function aporte(): void
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $metaId = self::id($isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? ''));

        try {
            $meta = $metaId > 0 ? $this->container->metas()->findOwned($user['id'], $metaId, $espacioId) : null;

            if ($meta === null) {
                Response::plain(404, "No encontrado.\n");
            }

            if (!$meta->enCurso()) {
                Flash::set('error', 'Esa meta ya no admite aportes: está ' . strtolower(\App\Domain\Meta::ESTADOS[$meta->estado]) . '.');
                Response::redirect('/metas.php');
            }

            $errors = [];
            $old = ['id_cuenta' => '', 'monto' => '', 'fecha' => date('Y-m-d')];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $result = (new AporteValidator())->validate($_POST);
                $errors = $result['errors'];
                $data = $result['data'];
                $old = [
                    'id_cuenta' => (string) ($data['id_cuenta'] ?: ''),
                    'monto' => Peticion::post('monto'),
                    'fecha' => $data['fecha'],
                ];

                if ($errors === []) {
                    $saved = $this->container->gestionMetas()->aportar($user['id'], $meta->id, $data, $espacioId);

                    switch ($saved['codigo']) {
                        case ReglasAporte::OK:
                            Flash::set('ok', 'Se aportaron ' . Dinero::formatear($data['monto']) . " a «{$meta->nombre}» desde «{$saved['cuenta']}»." . ($saved['lograda'] ? ' ¡Lograste tu meta!' : ''));
                            Response::redirect('/metas.php');

                            // no break
                        case ReglasAporte::SALDO_INSUFICIENTE:
                            $errors['monto'] = "La cuenta «{$saved['cuenta']}» tiene " . Dinero::formatear((string) $saved['saldo']) . ': no alcanza para aportar ' . Dinero::formatear($data['monto']) . '.';
                            break;

                        case ReglasAporte::CUENTA_INACTIVA:
                            $errors['id_cuenta'] = "La cuenta «{$saved['cuenta']}» está inactiva. Actívala en Cuentas o elige otra.";
                            break;

                        case ReglasAporte::META_CERRADA:
                            Flash::set('error', 'Esa meta ya no admite aportes.');
                            Response::redirect('/metas.php');

                            // Una cuenta o una meta que no son tuyas: como si no existieran (RNF-04).
                            // no break
                        default:
                            Response::plain(404, "No encontrado.\n");
                    }
                }
            }

            // Cuentas activas; si el formulario trae una que ya no lo es, también esa (marcada), para que el error se vea en el campo.
            $cuentas = array_values(array_filter(
                $this->container->cuentas()->listByUser($user['id'], $espacioId),
                static fn (Cuenta $c): bool => $c->activa || (string) $c->id === $old['id_cuenta'],
            ));
            $historial = $this->container->aportes()->listByMeta($user['id'], $meta->id, 20, $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/meta-aporte', ['meta' => $meta, 'cuentas' => $cuentas, 'historial' => $historial, 'old' => $old, 'errors' => $errors], 'Aporte a una meta', $user, 'app', 'metas');
    }

    /** POST de cancelar o eliminar desde el listado. */
    private function accion(int $userId, ?int $espacioId): never
    {
        reject_foreign_origin();
        require_csrf();

        $id = self::id($_POST['id'] ?? '');
        $accion = Peticion::postSinRecortar('accion');

        if ($id < 1 || !in_array($accion, ['cancelar', 'eliminar'], true)) {
            Response::plain(400, "Solicitud no válida.\n");
        }

        $meta = $this->container->metas()->findOwned($userId, $id, $espacioId);

        if ($meta === null) {
            Response::plain(404, "No encontrado.\n");
        }

        $gestion = $this->container->gestionMetas();

        if ($accion === 'cancelar') {
            $r = $gestion->cancelar($userId, $id, $espacioId);
            [$tone, $message] = match ($r['codigo']) {
                GestionMetas::OK => ['ok', "Se canceló la meta «{$meta->nombre}»." . (Dinero::aCentavos($r['devuelto']) > 0 ? ' Los ' . Dinero::formatear($r['devuelto']) . ' aportados volvieron a sus cuentas.' : '')],
                GestionMetas::CERRADA => ['error', 'Esa meta ya no se puede cancelar.'],
                default => ['error', 'Esa meta ya no existe.'],
            };
        } else {
            [$tone, $message] = match ($gestion->eliminar($userId, $id, $espacioId)) {
                GestionMetas::OK => ['ok', "Se eliminó la meta «{$meta->nombre}»."],
                GestionMetas::TIENE_APORTES => ['error', "«{$meta->nombre}» tiene aportes: cancélala primero para devolver el dinero a tus cuentas."],
                default => ['error', 'Esa meta ya no existe.'],
            };
        }

        Flash::set($tone, $message);
        Response::redirect('/metas.php');
    }

    private static function id(mixed $raw): int
    {
        return is_string($raw) && ctype_digit($raw) && strlen($raw) < 10 ? (int) $raw : 0;
    }

    private static function fail(RuntimeException $error): never
    {
        [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
        Response::plain($status, $message . "\n");
    }
}
