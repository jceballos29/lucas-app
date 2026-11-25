<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Response;
use App\Support\Dinero;
use App\Validation\CuentaValidator;
use App\View\View;
use RuntimeException;

/** Cuentas del usuario (RF-05 a RF-07): listado con saldo calculado, alta, edición y desactivación. */
final class CuentaController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;

        try {
            $cuentas = $this->container->cuentas();

            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                require_espacio_write($user);
                $this->toggle($user['id'], $espacioId);
            }

            $lista = $cuentas->listByUser($user['id'], $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        // El saldo total (RF-07) suma solo las cuentas activas.
        $total = Dinero::sumar(array_map(static fn (Cuenta $c): string => $c->saldo, array_filter($lista, static fn (Cuenta $c): bool => $c->activa)));

        View::page('app/cuentas', ['cuentas' => $lista, 'total' => $total, 'flash' => Flash::pull()], 'Cuentas', $user, 'app', 'cuentas');
    }

    /** Alta (sin `id`) o edición (`?id=`) de una cuenta. */
    public function form(): void
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $raw = $isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '');
        $id = is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;

        if ($raw !== '' && $id < 1) {
            Response::plain(404, "No encontrado.\n");
        }

        try {
            $repo = $this->container->cuentas();
            $cuenta = $id > 0 ? $repo->findOwned($user['id'], $id, $espacioId) : null;

            if ($id > 0 && $cuenta === null) {
                Response::plain(404, "No encontrado.\n");
            }

            $errors = [];
            $old = $cuenta === null
                ? ['nombre' => '', 'tipo' => 'efectivo', 'saldo_inicial' => '']
                : ['nombre' => $cuenta->nombre, 'tipo' => $cuenta->tipo, 'saldo_inicial' => Dinero::paraCampo($cuenta->saldoInicial)];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $result = (new CuentaValidator())->validate($_POST);
                $data = $result['data'];
                $errors = $result['errors'];
                $old = [
                    'nombre' => $data['nombre'],
                    'tipo' => $data['tipo'],
                    'saldo_inicial' => is_string($_POST['saldo_inicial'] ?? null) ? trim($_POST['saldo_inicial']) : '',
                ];

                if (!isset($errors['nombre']) && $repo->nameTaken($user['id'], $data['nombre'], $cuenta?->id, $espacioId)) {
                    $errors['nombre'] = 'Ya tienes una cuenta con ese nombre.';
                }

                if ($errors === []) {
                    if ($cuenta === null) {
                        $repo->create($user['id'], $data['nombre'], $data['tipo'], $data['saldo_inicial'], $espacioId);
                        Flash::set('ok', "Se creó la cuenta «{$data['nombre']}».");
                    } else {
                        $repo->update($user['id'], $cuenta->id, $data['nombre'], $data['tipo'], $data['saldo_inicial'], $espacioId);
                        Flash::set('ok', "Se guardaron los cambios de «{$data['nombre']}».");
                    }

                    Response::redirect('/cuentas.php');
                }
            }
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/cuenta-form', ['cuenta' => $cuenta, 'old' => $old, 'errors' => $errors], $cuenta === null ? 'Nueva cuenta' : 'Editar cuenta', $user, 'app', 'cuentas');
    }

    /** POST de activar/desactivar desde el listado. */
    private function toggle(int $userId, ?int $espacioId): never
    {
        reject_foreign_origin();
        require_csrf();

        $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;
        $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

        if ($id < 1 || !in_array($accion, ['activar', 'desactivar'], true)) {
            Response::plain(400, "Solicitud no válida.\n");
        }

        $repo = $this->container->cuentas();
        $cuenta = $repo->findOwned($userId, $id, $espacioId);

        if ($cuenta === null || !$repo->setActiva($userId, $id, $accion === 'activar', $espacioId)) {
            Response::plain(404, "No encontrado.\n");
        }

        Flash::set('ok', $accion === 'activar'
            ? "Se activó la cuenta «{$cuenta->nombre}»."
            : "Se desactivó la cuenta «{$cuenta->nombre}». Su historial se conserva y ya no cuenta en el saldo total.");
        Response::redirect('/cuentas.php');
    }

    private static function fail(RuntimeException $error): never
    {
        [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
        Response::plain($status, $message . "\n");
    }
}
