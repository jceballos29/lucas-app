<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Peticion;
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
        $espacioId = $user['espacio_id'] ?? null;

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                require_espacio_write($user);
                $this->eliminar($user['id'], $espacioId);
            }

            $repo = $this->container->transferencias();
            $monedaBase = Dinero::monedaActual();
            $tasas = $this->container->tasasCambio()->listMap($user['id'], $espacioId);
            $total = $repo->countByUser($user['id'], $espacioId);
            $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
            $pagina = min($paginas, self::page($_GET['pagina'] ?? 1));
            $filas = $repo->listByUser($user['id'], self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA, $espacioId);
            $montoTotal = $repo->totalByUser($user['id'], $espacioId, $monedaBase, $tasas);
            $cuentasActivas = array_values(array_filter(
                $this->container->cuentas()->listByUser($user['id'], $espacioId),
                static fn (Cuenta $c): bool => $c->activa,
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
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $raw = $isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '');
        $id = self::id($raw);

        if ($raw !== '' && $id < 1) {
            Response::plain(404, "No encontrado.\n");
        }

        try {
            $transferencia = $id > 0 ? $this->container->transferencias()->findOwned($user['id'], $id, $espacioId) : null;

            if ($id > 0 && $transferencia === null) {
                Response::plain(404, "No encontrado.\n");
            }

            $errors = [];
            $old = $transferencia === null
                ? [
                    'id_cuenta_origen' => (string) (self::id($_GET['origen'] ?? '') ?: ''),
                    'id_cuenta_destino' => (string) (self::id($_GET['destino'] ?? '') ?: ''),
                    'monto' => '',
                    'monto_destino' => '',
                    'tasa_cambio' => '',
                    'fecha' => date('Y-m-d'),
                    'descripcion' => '',
                ]
                : [
                    'id_cuenta_origen' => (string) $transferencia->idCuentaOrigen,
                    'id_cuenta_destino' => (string) $transferencia->idCuentaDestino,
                    'monto' => Dinero::paraCampo($transferencia->monto),
                    'monto_destino' => $transferencia->montoDestino !== null ? Dinero::paraCampo($transferencia->montoDestino) : '',
                    'tasa_cambio' => '',
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
                    'monto' => Peticion::post('monto'),
                    'monto_destino' => Peticion::post('monto_destino'),
                    'tasa_cambio' => Peticion::post('tasa_cambio'),
                    'fecha' => Peticion::post('fecha'),
                    'descripcion' => $data['descripcion'] ?? '',
                ];

                if ($errors === []) {
                    $saved = $this->container->gestionTransferencias()->guardar($user['id'], $transferencia?->id, $data, $espacioId);

                    switch ($saved['codigo']) {
                        case ReglasTransferencia::OK:
                            if ($transferencia === null) {
                                $montoTxt = ($saved['monto_destino'] ?? null) !== null
                                    ? Dinero::formatearEn($data['monto'], $saved['moneda_origen'] ?? null) . ' → ' . Dinero::formatearEn((string) $saved['monto_destino'], $saved['moneda_destino'] ?? null)
                                    : Dinero::formatearEn($data['monto'], $saved['moneda_origen'] ?? null);
                                $mensaje = "Se transfirieron $montoTxt de «{$saved['origen']}» a «{$saved['destino']}».";
                            } else {
                                $mensaje = 'Se guardaron los cambios de la transferencia.';
                            }
                            Flash::set('ok', $mensaje);
                            Response::redirect('/transferencias.php');

                            // no break
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
                            $disponible = Dinero::formatearEn((string) ($saved['saldo'] ?? '0.00'), $saved['moneda_origen'] ?? null);
                            $errors['monto'] = "La cuenta «{$saved['origen']}» tiene $disponible disponibles: no alcanza para realizar esta operación sin dejarla en negativo.";
                            break;

                        case ReglasTransferencia::FALTA_CONVERSION:
                            $mOrig = (string) ($saved['moneda_origen'] ?? '');
                            $mDest = (string) ($saved['moneda_destino'] ?? '');
                            $errors['monto_destino'] = "Las cuentas usan monedas distintas ($mOrig → $mDest). Escribe el monto que llega en $mDest, indica la tasa aplicada o registra una tasa de referencia en Cuentas.";
                            break;

                        default:
                            Response::plain(404, "No encontrado.\n");
                    }
                }
            }

            $cuentas = array_values(array_filter(
                $this->container->cuentas()->listByUser($user['id'], $espacioId),
                static fn (Cuenta $c): bool => $c->activa
                    || (string) $c->id === $old['id_cuenta_origen']
                    || (string) $c->id === $old['id_cuenta_destino'],
            ));
            $tasasMapa = $this->container->tasasCambio()->listMap($user['id'], $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/transferencia-form', [
            'transferencia' => $transferencia,
            'old' => $old,
            'errors' => $errors,
            'cuentas' => $cuentas,
            'tasasMapa' => $tasasMapa,
            'monedaBase' => Dinero::monedaActual(),
        ], $transferencia === null ? 'Nueva transferencia' : 'Editar transferencia', $user, 'app', 'transferencias');
    }

    /** POST de eliminación desde el listado. */
    private function eliminar(int $userId, ?int $espacioId): never
    {
        reject_foreign_origin();
        require_csrf();

        $id = self::id($_POST['id'] ?? '');
        $accion = Peticion::postSinRecortar('accion');

        if ($id < 1 || $accion !== 'eliminar') {
            Response::plain(400, "Solicitud no válida.\n");
        }

        $res = $this->container->gestionTransferencias()->eliminar($userId, $id, $espacioId);

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
