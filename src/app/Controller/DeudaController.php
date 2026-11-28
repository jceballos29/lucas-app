<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Deudas\GestionDeudas;
use App\Deudas\PlanificadorDeudas;
use App\Deudas\ReglasPagoDeuda;
use App\Domain\Cuenta;
use App\Http\Flash;
use App\Http\Peticion;
use App\Http\Response;
use App\Support\Dinero;
use App\Validation\DeudaValidator;
use App\View\View;

/**
 * Módulo de Deudas, cálculo de intereses y estrategias de pago Bola de Nieve / Avalancha (Fase 11).
 */
final class DeudaController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            require_espacio_write($user);
            $this->eliminar($user['id'], $espacioId);
        }

        $deudas = $this->container->deudas()->listByUser($user['id'], $espacioId);

        $extraTexto = Peticion::get('extra');
        $extraMonto = ($extraTexto !== '' ? Dinero::parse($extraTexto) : null) ?? '0.00';
        if (str_starts_with($extraMonto, '-')) {
            $extraMonto = '0.00';
        }

        $plan = PlanificadorDeudas::comparar($deudas, $extraMonto);

        View::page('app/deudas', [
            'deudas' => $deudas,
            'plan' => $plan,
            'extraCampo' => Dinero::aCentavos($extraMonto) > 0 ? Dinero::paraCampo($extraMonto) : '',
            'extraMonto' => $extraMonto,
            'flash' => Flash::pull(),
        ], 'Deudas y plan de salida', $user, 'app', 'deudas');
    }

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

        $deuda = $id > 0 ? $this->container->deudas()->findOwned($user['id'], $id, $espacioId) : null;

        if ($id > 0 && $deuda === null) {
            Response::plain(404, "No encontrado.\n");
        }

        $errors = [];
        $old = $deuda === null
            ? [
                'nombre' => '',
                'acreedor' => '',
                'monto_inicial' => '',
                'tasa_mensual' => '0',
                'cuota_minima' => '',
                'dia_pago' => '',
            ]
            : [
                'nombre' => $deuda->nombre,
                'acreedor' => $deuda->acreedor ?? '',
                'monto_inicial' => Dinero::paraCampo($deuda->montoInicial),
                'tasa_mensual' => $deuda->tasaParaCampo(),
                'cuota_minima' => Dinero::paraCampo($deuda->cuotaMinima),
                'dia_pago' => $deuda->diaPago === null ? '' : (string) $deuda->diaPago,
            ];

        if ($isPost) {
            reject_foreign_origin();
            require_csrf();

            $result = (new DeudaValidator())->validateDeuda($_POST);
            $errors = $result['errors'];
            $data = $result['data'];
            $old = [
                'nombre' => $data['nombre'],
                'acreedor' => $data['acreedor'] ?? '',
                'monto_inicial' => Peticion::post('monto_inicial'),
                'tasa_mensual' => Peticion::post('tasa_mensual', '0'),
                'cuota_minima' => Peticion::post('cuota_minima'),
                'dia_pago' => Peticion::post('dia_pago'),
            ];

            if ($errors === []) {
                if ($deuda === null) {
                    $this->container->deudas()->create($user['id'], $data, $espacioId);
                    Flash::set('ok', "Se registró la deuda «{$data['nombre']}». Revisa tu plan Bola de Nieve y Avalancha abajo.");
                    Response::redirect('/deudas.php');
                }

                $saved = $this->container->gestionDeudas()->editar($user['id'], $deuda->id, $data, $espacioId);
                if ($saved['codigo'] === GestionDeudas::OK) {
                    Flash::set('ok', "Se guardaron los cambios de «{$data['nombre']}»." . ($saved['pagada'] ? ' ¡La deuda quedó pagada!' : ''));
                    Response::redirect('/deudas.php');
                }

                Response::plain(404, "No encontrado.\n");
            }
        }

        View::page('app/deuda-form', [
            'deuda' => $deuda,
            'old' => $old,
            'errors' => $errors,
        ], $deuda === null ? 'Nueva deuda' : 'Editar deuda', $user, 'app', 'deudas');
    }

    public function pago(): void
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $deudaId = self::id($isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? ''));

        $deuda = $deudaId > 0 ? $this->container->deudas()->findOwned($user['id'], $deudaId, $espacioId) : null;

        if ($deuda === null) {
            Response::plain(404, "No encontrado.\n");
        }

        if (!$deuda->activa()) {
            Flash::set('info', "La deuda «{$deuda->nombre}» ya está totalmente pagada.");
            Response::redirect('/deudas.php');
        }

        $interesSugerido = $deuda->interesMensual();
        $saldoCapital = $deuda->saldoCapital();
        $totalParaLiquidar = Dinero::sumar([$saldoCapital, $interesSugerido]);
        $cuotaSugerida = Dinero::aCentavos($deuda->cuotaMinima) > Dinero::aCentavos($totalParaLiquidar)
            ? $totalParaLiquidar
            : $deuda->cuotaMinima;

        $errors = [];
        $old = [
            'id_cuenta' => '',
            'monto_total' => Dinero::paraCampo($cuotaSugerida),
            'modo_pago' => 'cuota',
            'fecha' => date('Y-m-d'),
            'descripcion' => '',
        ];

        if ($isPost) {
            reject_foreign_origin();
            require_csrf();

            $result = (new DeudaValidator())->validatePago($_POST);
            $errors = $result['errors'];
            $data = $result['data'];
            $old = [
                'id_cuenta' => (string) ($data['id_cuenta'] ?: ''),
                'monto_total' => Peticion::post('monto_total'),
                'modo_pago' => $data['modo_pago'],
                'fecha' => Peticion::post('fecha'),
                'descripcion' => $data['descripcion'] ?? '',
            ];

            if ($errors === []) {
                $saved = $this->container->gestionDeudas()->registrarPago($user['id'], $deuda->id, $data, $espacioId);

                switch ($saved['codigo']) {
                    case ReglasPagoDeuda::OK:
                        $msg = 'Se registró el pago de ' . Dinero::formatear($data['monto_total']) . " a «{$deuda->nombre}» desde «{$saved['cuenta']}» "
                            . '(Capital amortizado: ' . Dinero::formatear($saved['abono_capital']) . ' · Intereses: ' . Dinero::formatear($saved['pago_interes']) . ').'
                            . ($saved['pagada'] ? ' ¡Felicitaciones, liquidaste esta deuda!' : '');
                        Flash::set('ok', $msg);
                        Response::redirect('/deudas.php');

                        // no break
                    case ReglasPagoDeuda::SALDO_INSUFICIENTE:
                        $disp = Dinero::formatear((string) ($saved['saldo_cuenta'] ?? '0.00'));
                        $errors['monto_total'] = "La cuenta «{$saved['cuenta']}» tiene $disp disponibles: no alcanza para realizar este pago.";
                        break;

                    case ReglasPagoDeuda::SUPERA_SALDO_DEUDA:
                        $maximo = $data['modo_pago'] === 'capital' ? Dinero::formatear($saldoCapital) : Dinero::formatear($totalParaLiquidar);
                        $errors['monto_total'] = "El abono a capital supera el saldo pendiente de la deuda. Para liquidarla hoy el máximo es $maximo.";
                        break;

                    case ReglasPagoDeuda::CUENTA_INACTIVA:
                        $errors['id_cuenta'] = "La cuenta «{$saved['cuenta']}» está inactiva. Actívala en Cuentas o elige otra.";
                        break;

                    case ReglasPagoDeuda::DEUDA_PAGADA:
                        Flash::set('info', 'Esa deuda ya está pagada.');
                        Response::redirect('/deudas.php');

                        // no break
                    default:
                        Response::plain(404, "No encontrado.\n");
                }
            }
        }

        $cuentas = array_values(array_filter(
            $this->container->cuentas()->listByUser($user['id'], $espacioId),
            static fn (Cuenta $c): bool => $c->activa || (string) $c->id === $old['id_cuenta'],
        ));
        $historial = $this->container->deudas()->listPagos($user['id'], $deuda->id, 30, $espacioId);

        View::page('app/deuda-pago', [
            'deuda' => $deuda,
            'cuentas' => $cuentas,
            'historial' => $historial,
            'old' => $old,
            'errors' => $errors,
        ], 'Pago de deuda', $user, 'app', 'deudas');
    }

    private function eliminar(int $userId, ?int $espacioId): never
    {
        reject_foreign_origin();
        require_csrf();

        $id = self::id($_POST['id'] ?? '');
        $accion = Peticion::postSinRecortar('accion');

        if ($id < 1 || $accion !== 'eliminar') {
            Response::plain(400, "Solicitud no válida.\n");
        }

        $deuda = $this->container->deudas()->findOwned($userId, $id, $espacioId);
        if ($deuda === null) {
            Response::plain(404, "No encontrado.\n");
        }

        $res = $this->container->gestionDeudas()->eliminar($userId, $id, $espacioId);
        if ($res === GestionDeudas::TIENE_PAGOS) {
            Flash::set('error', "No se puede eliminar «{$deuda->nombre}» porque ya tiene pagos registrados que descontaron saldo de tus cuentas.");
        } elseif ($res === GestionDeudas::OK) {
            Flash::set('ok', "Se eliminó la deuda «{$deuda->nombre}».");
        } else {
            Response::plain(404, "No encontrado.\n");
        }

        Response::redirect('/deudas.php');
    }

    private static function id(mixed $raw): int
    {
        return is_string($raw) && ctype_digit($raw) && strlen($raw) < 10 ? (int) $raw : 0;
    }

}
