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
            $hoy = date('Y-m-d');
            $resumenesTarjeta = [];
            $cuotasRepo = $this->container->cuotasTarjeta();
            foreach ($lista as $c) {
                if ($c->tipo === 'tarjeta_credito') {
                    $resumenesTarjeta[$c->id] = $cuotasRepo->resumenTarjeta($c->id, $c->diaCorte, $c->diaPagoLimite, $hoy);
                }
            }
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        // El saldo total (RF-07) suma solo las cuentas activas.
        $total = Dinero::sumar(array_map(static fn (Cuenta $c): string => $c->saldo, array_filter($lista, static fn (Cuenta $c): bool => $c->activa)));

        View::page('app/cuentas', [
            'cuentas' => $lista,
            'resumenesTarjeta' => $resumenesTarjeta,
            'total' => $total,
            'flash' => Flash::pull(),
        ], 'Cuentas', $user, 'app', 'cuentas');
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
                ? ['nombre' => '', 'tipo' => 'efectivo', 'saldo_inicial' => '', 'dia_corte' => '', 'dia_pago_limite' => '', 'tasa_mensual' => '']
                : [
                    'nombre' => $cuenta->nombre,
                    'tipo' => $cuenta->tipo,
                    'saldo_inicial' => Dinero::paraCampo($cuenta->saldoInicial),
                    'dia_corte' => $cuenta->diaCorte !== null ? (string) $cuenta->diaCorte : '',
                    'dia_pago_limite' => $cuenta->diaPagoLimite !== null ? (string) $cuenta->diaPagoLimite : '',
                    'tasa_mensual' => $cuenta->tasaParaCampo(),
                ];

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
                    'dia_corte' => is_string($_POST['dia_corte'] ?? null) ? trim($_POST['dia_corte']) : '',
                    'dia_pago_limite' => is_string($_POST['dia_pago_limite'] ?? null) ? trim($_POST['dia_pago_limite']) : '',
                    'tasa_mensual' => is_string($_POST['tasa_mensual'] ?? null) ? trim($_POST['tasa_mensual']) : '',
                ];

                if (!isset($errors['nombre']) && $repo->nameTaken($user['id'], $data['nombre'], $cuenta?->id, $espacioId)) {
                    $errors['nombre'] = 'Ya tienes una cuenta con ese nombre.';
                }

                if ($errors === []) {
                    if ($cuenta === null) {
                        $repo->create(
                            $user['id'],
                            $data['nombre'],
                            $data['tipo'],
                            $data['saldo_inicial'],
                            $espacioId,
                            $data['dia_corte'],
                            $data['dia_pago_limite'],
                            $data['tasa_mensual_bps'],
                        );
                        Flash::set('ok', "Se creó la cuenta «{$data['nombre']}».");
                    } else {
                        $repo->update(
                            $user['id'],
                            $cuenta->id,
                            $data['nombre'],
                            $data['tipo'],
                            $data['saldo_inicial'],
                            $espacioId,
                            $data['dia_corte'],
                            $data['dia_pago_limite'],
                            $data['tasa_mensual_bps'],
                        );
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

    /** Conciliación rápida del saldo real frente al saldo calculado en Lucas (Fase 13). */
    public function conciliar(): void
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $raw = $isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '');
        $id = is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;

        if ($id < 1) {
            Response::plain(404, "No encontrado.\n");
        }

        try {
            $cuenta = $this->container->cuentas()->findOwned($user['id'], $id, $espacioId);
            if ($cuenta === null) {
                Response::plain(404, "No encontrado.\n");
            }
            if (!$cuenta->activa) {
                Flash::set('error', "La cuenta «{$cuenta->nombre}» está inactiva. Actívala primero para conciliar su saldo.");
                Response::redirect('/cuentas.php');
            }

            $categorias = $this->container->categorias()->listVisible($user['id'], $espacioId);
            $catGastoDef = 0;
            $catIngresoDef = 0;
            foreach ($categorias as $k) {
                if ($k->tipo === 'gasto' && ($catGastoDef === 0 || mb_strtolower($k->nombre) === 'otros gastos')) {
                    $catGastoDef = $k->id;
                }
                if ($k->tipo === 'ingreso' && ($catIngresoDef === 0 || mb_strtolower($k->nombre) === 'otros ingresos')) {
                    $catIngresoDef = $k->id;
                }
            }

            $errors = [];
            $old = [
                'saldo_real' => Dinero::paraCampo($cuenta->saldo),
                'id_categoria_gasto' => (string) $catGastoDef,
                'id_categoria_ingreso' => (string) $catIngresoDef,
                'fecha' => date('Y-m-d'),
                'descripcion' => 'Ajuste por conciliación de saldo',
            ];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $saldoTexto = is_string($_POST['saldo_real'] ?? null) ? trim($_POST['saldo_real']) : '';
                $catGastoId = is_string($_POST['id_categoria_gasto'] ?? null) && ctype_digit($_POST['id_categoria_gasto']) ? (int) $_POST['id_categoria_gasto'] : $catGastoDef;
                $catIngresoId = is_string($_POST['id_categoria_ingreso'] ?? null) && ctype_digit($_POST['id_categoria_ingreso']) ? (int) $_POST['id_categoria_ingreso'] : $catIngresoDef;
                $fecha = is_string($_POST['fecha'] ?? null) ? trim($_POST['fecha']) : '';
                $descripcion = is_string($_POST['descripcion'] ?? null) ? trim($_POST['descripcion']) : '';

                $old = [
                    'saldo_real' => $saldoTexto,
                    'id_categoria_gasto' => (string) $catGastoId,
                    'id_categoria_ingreso' => (string) $catIngresoId,
                    'fecha' => $fecha,
                    'descripcion' => $descripcion,
                ];

                $saldoReal = Dinero::parse($saldoTexto);
                if ($saldoReal === null) {
                    $errors['saldo_real'] = 'Escribe un monto válido, por ejemplo 1.250.000.';
                } elseif ($cuenta->tipo !== 'tarjeta_credito' && str_starts_with($saldoReal, '-')) {
                    $errors['saldo_real'] = 'El saldo de esta cuenta no puede ser negativo (solo las tarjetas de crédito lo admiten).';
                }

                if (!\App\Validation\MovimientoValidator::validDate($fecha)) {
                    $errors['fecha'] = 'Elige una fecha válida.';
                }

                if ($errors === [] && $saldoReal !== null) {
                    $res = $this->container->conciliacionCuenta()->conciliar(
                        $user['id'],
                        $cuenta->id,
                        $saldoReal,
                        $catIngresoId,
                        $catGastoId,
                        $fecha,
                        $descripcion,
                        $espacioId,
                    );

                    switch ($res['codigo']) {
                        case \App\Cuentas\ConciliacionCuenta::OK:
                            $tipoTxt = $res['tipo_ajuste'] === 'ingreso' ? 'un ingreso de ajuste' : 'un gasto de ajuste';
                            Flash::set('ok', "Se concilió el saldo de «{$cuenta->nombre}» registrando $tipoTxt por " . Dinero::formatear($res['diferencia']) . '.');
                            Response::redirect('/cuentas.php');

                        case \App\Cuentas\ConciliacionCuenta::SIN_DIFERENCIA:
                            Flash::set('info', "El saldo de «{$cuenta->nombre}» ya coincide con " . Dinero::formatear($saldoReal) . ': no hizo falta ningún ajuste.');
                            Response::redirect('/cuentas.php');

                        case \App\Cuentas\ConciliacionCuenta::SALDO_NEGATIVO_NO_PERMITIDO:
                            $errors['saldo_real'] = 'El saldo de esta cuenta no puede ser negativo.';
                            break;

                        default:
                            Response::plain(404, "No encontrado.\n");
                    }
                }
            }
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/cuenta-conciliar', [
            'cuenta' => $cuenta,
            'categorias' => $categorias,
            'old' => $old,
            'errors' => $errors,
        ], 'Conciliar saldo', $user, 'app', 'cuentas');
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
