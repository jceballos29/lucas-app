<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Domain\Recurrente;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Peticion;
use App\Http\Response;
use App\Movimientos\ReglasMovimiento;
use App\Support\Dinero;
use App\Support\Frecuencia;
use App\Validation\RecurrenteValidator;
use App\View\Fecha;
use App\View\View;
use RuntimeException;

/**
 * Movimientos recurrentes y gastos fijos programados (Fase 9):
 *  - listado con resumen mensual equivalente, alertas de vencidos y acciones rápidas (aplicar ahora, saltar periodo, pausar/activar, eliminar);
 *  - formulario de creación y edición.
 */
final class RecurrenteController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;
        $hoy = date('Y-m-d');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            require_espacio_write($user);
            $this->procesarAccion($user['id'], $espacioId);
        }

        try {
            $auto = \App\Espacios\ReglasEspacio::puedeEscribir($user['espacio_rol'] ?? null)
                ? $this->container->gestionRecurrentes()->procesarVencidos($user['id'], $hoy, $espacioId)
                : ['aplicados' => 0, 'retenidosPorSaldo' => 0];
            $recurrentes = $this->container->recurrentes()->listByUser($user['id'], $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        $gastosMensuales = [];
        $ingresosMensuales = [];
        $vencidos = [];

        foreach ($recurrentes as $r) {
            if (!$r->activo) {
                continue;
            }
            $eq = Frecuencia::equivalenteMensual($r->monto, $r->frecuencia);
            if ($r->esIngreso()) {
                $ingresosMensuales[] = $eq;
            } else {
                $gastosMensuales[] = $eq;
            }
            if ($r->vencido($hoy)) {
                $vencidos[] = $r;
            }
        }

        $flash = Flash::pull();
        if ($flash === null && $auto['aplicados'] > 0) {
            $n = $auto['aplicados'];
            $flash = [
                'tone' => 'ok',
                'message' => $n === 1
                    ? 'Se registró automáticamente 1 movimiento recurrente programado para hoy.'
                    : "Se registraron automáticamente $n movimientos recurrentes vencidos.",
            ];
        }

        View::page('app/recurrentes', [
            'recurrentes' => $recurrentes,
            'vencidos' => $vencidos,
            'hoy' => $hoy,
            'totalGastosFijosMes' => Dinero::sumar($gastosMensuales),
            'totalIngresosFijosMes' => Dinero::sumar($ingresosMensuales),
            'retenidosPorSaldo' => $auto['retenidosPorSaldo'],
            'flash' => $flash,
        ], 'Gastos fijos y recurrentes', $user, 'app', 'recurrentes');
    }

    /** Alta (sin `id`) o edición (`?id=`) de un movimiento recurrente. */
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
            $recurrente = $id > 0 ? $this->container->recurrentes()->findOwned($user['id'], $id, $espacioId) : null;

            if ($id > 0 && $recurrente === null) {
                Response::plain(404, "No encontrado.\n");
            }

            $errors = [];
            $old = $recurrente === null
                ? [
                    'tipo' => 'gasto',
                    'monto' => '',
                    'frecuencia' => 'mensual',
                    'proxima_fecha' => date('Y-m-d'),
                    'id_cuenta' => '',
                    'id_categoria' => '',
                    'descripcion' => '',
                    'auto_aplicar' => '1',
                ]
                : [
                    'tipo' => $recurrente->tipo,
                    'monto' => Dinero::paraCampo($recurrente->monto),
                    'frecuencia' => $recurrente->frecuencia,
                    'proxima_fecha' => $recurrente->proximaFecha,
                    'id_cuenta' => (string) $recurrente->idCuenta,
                    'id_categoria' => (string) $recurrente->idCategoria,
                    'descripcion' => $recurrente->descripcion ?? '',
                    'auto_aplicar' => $recurrente->autoAplicar ? '1' : '0',
                ];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $result = (new RecurrenteValidator())->validate($_POST);
                $errors = $result['errors'];
                $old = [
                    'tipo' => $result['data']['tipo'],
                    'monto' => Peticion::post('monto'),
                    'frecuencia' => $result['data']['frecuencia'],
                    'proxima_fecha' => Peticion::post('proxima_fecha'),
                    'id_cuenta' => (string) ($result['data']['id_cuenta'] ?: ''),
                    'id_categoria' => (string) ($result['data']['id_categoria'] ?: ''),
                    'descripcion' => $result['data']['descripcion'] ?? '',
                    'auto_aplicar' => $result['data']['auto_aplicar'] ? '1' : '0',
                ];

                if ($errors === []) {
                    $saved = $this->container->gestionRecurrentes()->guardar($user['id'], $recurrente?->id, $result['data'], $espacioId);

                    switch ($saved['codigo']) {
                        case ReglasMovimiento::OK:
                            $data = $result['data'];
                            $etiqueta = $data['descripcion'] ?? ($data['tipo'] === 'ingreso' ? 'ingreso recurrente' : 'gasto fijo');
                            $mensaje = $recurrente === null
                                ? "Se programó «{$etiqueta}» (" . Dinero::formatear($data['monto']) . ' · ' . (Frecuencia::ETIQUETAS[$data['frecuencia']] ?? $data['frecuencia']) . ').'
                                : 'Se guardaron los cambios del movimiento recurrente.';
                            Flash::set('ok', $mensaje);
                            Response::redirect('/recurrentes.php');

                            // no break
                        case ReglasMovimiento::TIPO_DISCORDANTE:
                            $errors['id_categoria'] = 'Esa categoría no es de ' . ($result['data']['tipo'] === 'ingreso' ? 'ingresos' : 'gastos') . '. Elige otra o cambia el tipo.';
                            break;

                        case ReglasMovimiento::CUENTA_INACTIVA:
                            $errors['id_cuenta'] = "La cuenta «{$saved['cuenta']}» está inactiva. Actívala en Cuentas o elige otra.";
                            break;

                        default:
                            Response::plain(404, "No encontrado.\n");
                    }
                }
            }

            $cuentas = array_values(array_filter(
                $this->container->cuentas()->listByUser($user['id'], $espacioId),
                static fn (Cuenta $c): bool => $c->activa || (string) $c->id === $old['id_cuenta'],
            ));
            $categorias = $this->container->categorias()->listVisible($user['id'], $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/recurrente-form', [
            'recurrente' => $recurrente,
            'old' => $old,
            'errors' => $errors,
            'cuentas' => $cuentas,
            'categorias' => $categorias,
        ], $recurrente === null ? 'Nuevo movimiento recurrente' : 'Editar movimiento recurrente', $user, 'app', 'recurrentes');
    }

    private function procesarAccion(int $userId, ?int $espacioId): never
    {
        reject_foreign_origin();
        require_csrf();

        $id = Peticion::postEntero('id');
        $accion = Peticion::postSinRecortar('accion');

        if ($id < 1 || !in_array($accion, ['aplicar', 'saltar', 'pausar', 'activar', 'eliminar'], true)) {
            Response::plain(400, "Solicitud no válida.\n");
        }

        try {
            $repo = $this->container->recurrentes();
            $rec = $repo->findOwned($userId, $id, $espacioId);

            if ($rec === null) {
                Response::plain(404, "No encontrado.\n");
            }

            $nombre = $rec->nombreVisible();

            switch ($accion) {
                case 'aplicar':
                    $res = $this->container->gestionRecurrentes()->aplicarAhora($userId, $id, $espacioId);
                    if ($res['codigo'] === ReglasMovimiento::OK) {
                        $prox = Fecha::simple((string) $res['proximaFecha']);
                        $mensaje = "Se registró «{$nombre}» (" . Dinero::formatear($rec->monto) . ") en «{$res['cuenta']}». Próxima fecha: $prox.";
                        $aviso = !$rec->esIngreso() ? $this->container->avisoPresupuesto()->para($userId, $rec->idCategoria, $rec->proximaFecha, $espacioId) : null;
                        if (!$rec->esIngreso()) {
                            $this->container->notificadorPresupuesto()->notificarGasto($userId, $rec->idCategoria, $rec->proximaFecha, $espacioId);
                        }
                        Flash::set($aviso === null ? 'ok' : 'warn', $aviso === null ? $mensaje : "$mensaje $aviso");
                    } elseif ($res['codigo'] === ReglasMovimiento::SALDO_INSUFICIENTE) {
                        $disp = Dinero::formatear((string) ($res['saldo'] ?? '0.00'));
                        Flash::set('error', "No se pudo registrar «{$nombre}»: la cuenta «{$res['cuenta']}» tiene $disp disponibles y no puede quedar en saldo negativo.");
                    } elseif ($res['codigo'] === ReglasMovimiento::CUENTA_INACTIVA) {
                        Flash::set('error', "No se pudo registrar «{$nombre}»: la cuenta «{$res['cuenta']}» está inactiva.");
                    } else {
                        Response::plain(404, "No encontrado.\n");
                    }
                    break;

                case 'saltar':
                    $siguiente = $this->container->gestionRecurrentes()->saltarPeriodo($userId, $id, $espacioId);
                    if ($siguiente === null) {
                        Response::plain(404, "No encontrado.\n");
                    }
                    Flash::set('info', "Se saltó este periodo de «{$nombre}». Próxima fecha: " . Fecha::simple($siguiente) . '.');
                    break;

                case 'pausar':
                    $repo->setActivo($userId, $id, false, $espacioId);
                    Flash::set('info', "Se pausó «{$nombre}». No se ejecutará hasta que lo vuelvas a activar.");
                    break;

                case 'activar':
                    $repo->setActivo($userId, $id, true, $espacioId);
                    Flash::set('ok', "Se reactivó «{$nombre}».");
                    break;

                case 'eliminar':
                    $repo->delete($userId, $id, $espacioId);
                    Flash::set('ok', "Se eliminó la programación de «{$nombre}». Los movimientos ya registrados se conservan.");
                    break;
            }
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        Response::redirect('/recurrentes.php');
    }

    private static function fail(RuntimeException $error): never
    {
        [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
        Response::plain($status, $message . "\n");
    }
}
