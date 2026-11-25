<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Response;
use App\Support\Dinero;
use App\Transferencias\GestionTransferencias;
use App\Transferencias\ReglasTransferencia;
use App\Validation\TransferenciaValidator;
use App\View\View;
use RuntimeException;

/**
 * Transferencias entre cuentas propias y abono a tarjetas de crédito (Fase 8):
 * listado con paginación, alta, edición y eliminación. No cuentan como ingresos ni como gastos del mes.
 */
final class TransferenciaController
{
    public const POR_PAGINA = 20;

    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                $this->eliminar($user['id']);
            }

            $repo = $this->container->transferencias();
            $total = $repo->countByUser($user['id']);
            $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
            $pagina = min($paginas, self::page($_GET['pagina'] ?? 1));
            $filas = $repo->listByUser($user['id'], self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA);
            $montoTotal = $repo->totalByUser($user['id']);
            $cuentasActivas = array_values(array_filter(
                $this->container->cuentas()->listByUser($user['id']),
                static fn (Cuenta $c): bool => $c->activa
            ));
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/transferencias', [
            'rows' => $filas,
            'total' => $total,
            'montoTotal' => $montoTotal,
            'pagina' => $pagina,
            'paginas' => $paginas,
            'numCuentasActivas' => count($cuentasActivas),
            'flash' => Flash::pull(),
        ], 'Transferencias', $user, 'app', 'transferencias');
    }

    /** Alta (sin `id`) o edición (`?id=`) de una transferencia entre dos cuentas. */
    public function form(): void
    {
        $user = require_login();
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $raw = $isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '');
        $id = self::id($raw);

        if ($raw !== '' && $id < 1) {
            Response::plain(404, "No encontrado.\n");
        }

        try {
            $transferencia = $id > 0 ? $this->container->transferencias()->findOwned($user['id'], $id) : null;

            if ($id > 0 && $transferencia === null) {
                Response::plain(404, "No encontrado.\n");
            }

            $errors = [];
            $old = $transferencia === null
                ? [
                    'id_cuenta_origen' => (string) (self::id($_GET['origen'] ?? '') ?: ''),
                    'id_cuenta_destino' => (string) (self::id($_GET['destino'] ?? '') ?: ''),
                    'monto' => '',
                    'fecha' => date('Y-m-d'),
                    'descripcion' => '',
                ]
                : [
                    'id_cuenta_origen' => (string) $transferencia->idCuentaOrigen,
                    'id_cuenta_destino' => (string) $transferencia->idCuentaDestino,
                    'monto' => Dinero::paraCampo($transferencia->monto),
                    'fecha' => $transferencia->fecha,
                    'descripcion' => $transferencia->descripcion ?? '',
                ];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $result = (new TransferenciaValidator())->validate($_POST);
                $errors = $result['errors'];
                $data = $result['data'];
                $old = [
                    'id_cuenta_origen' => (string) ($data['id_cuenta_origen'] ?: ''),
                    'id_cuenta_destino' => (string) ($data['id_cuenta_destino'] ?: ''),
                    'monto' => is_string($_POST['monto'] ?? null) ? trim($_POST['monto']) : '',
                    'fecha' => is_string($_POST['fecha'] ?? null) ? trim($_POST['fecha']) : '',
                    'descripcion' => $data['descripcion'] ?? '',
                ];

                if ($errors === []) {
                    $saved = $this->container->gestionTransferencias()->guardar($user['id'], $transferencia?->id, $data);

                    switch ($saved['codigo']) {
                        case ReglasTransferencia::OK:
                            $mensaje = $transferencia === null
                                ? 'Se transfirieron ' . Dinero::formatear($data['monto']) . " de «{$saved['origen']}» a «{$saved['destino']}»."
                                : 'Se guardaron los cambios de la transferencia.';
                            Flash::set('ok', $mensaje);
                            Response::redirect('/transferencias.php');

                        case ReglasTransferencia::MISMA_CUENTA:
                            $errors['id_cuenta_destino'] = 'La cuenta de destino debe ser distinta de la cuenta de origen.';
                            break;

                        case ReglasTransferencia::ORIGEN_INACTIVA:
                            $errors['id_cuenta_origen'] = "La cuenta «{$saved['origen']}» está inactiva. Actívala en Cuentas o elige otra.";
                            break;

                        case ReglasTransferencia::DESTINO_INACTIVA:
                            $errors['id_cuenta_destino'] = "La cuenta «{$saved['destino']}» está inactiva. Actívala en Cuentas o elige otra.";
                            break;

                        case ReglasTransferencia::SALDO_INSUFICIENTE:
                            $disponible = Dinero::formatear((string) ($saved['saldo'] ?? '0.00'));
                            $errors['monto'] = "La cuenta «{$saved['origen']}» tiene $disponible disponibles: no alcanza para realizar esta operación sin dejarla en negativo.";
                            break;

                        default:
                            Response::plain(404, "No encontrado.\n");
                    }
                }
            }

            $cuentas = array_values(array_filter(
                $this->container->cuentas()->listByUser($user['id']),
                static fn (Cuenta $c): bool => $c->activa
                    || (string) $c->id === $old['id_cuenta_origen']
                    || (string) $c->id === $old['id_cuenta_destino']
            ));
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/transferencia-form', [
            'transferencia' => $transferencia,
            'old' => $old,
            'errors' => $errors,
            'cuentas' => $cuentas,
        ], $transferencia === null ? 'Nueva transferencia' : 'Editar transferencia', $user, 'app', 'transferencias');
    }

    /** POST de eliminación desde el listado. */
    private function eliminar(int $userId): never
    {
        reject_foreign_origin();
        require_csrf();

        $id = self::id($_POST['id'] ?? '');
        $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

        if ($id < 1 || $accion !== 'eliminar') {
            Response::plain(400, "Solicitud no válida.\n");
        }

        $res = $this->container->gestionTransferencias()->eliminar($userId, $id);

        if ($res['codigo'] === GestionTransferencias::NO_EXISTE) {
            Response::plain(404, "No encontrado.\n");
        }

        if ($res['codigo'] === ReglasTransferencia::SALDO_INSUFICIENTE) {
            Flash::set('error', "No se puede eliminar esa transferencia porque «{$res['cuenta']}» ya usó ese dinero y quedaría con saldo negativo.");
            Response::redirect('/transferencias.php');
        }

        $pagina = self::page($_POST['pagina'] ?? 1);
        Flash::set('ok', 'Se eliminó la transferencia.');
        Response::redirect('/transferencias.php' . ($pagina > 1 ? '?pagina=' . $pagina : ''));
    }

    private static function id(mixed $raw): int
    {
        return is_string($raw) && ctype_digit($raw) && strlen($raw) < 10 ? (int) $raw : 0;
    }

    private static function page(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) ? max(1, (int) $value) : 1;
    }

    private static function fail(RuntimeException $error): never
    {
        [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
        Response::plain($status, $message . "\n");
    }
}
