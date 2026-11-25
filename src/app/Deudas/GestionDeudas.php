<?php

declare(strict_types=1);

namespace App\Deudas;

use App\Database\Transaction;
use App\Repository\CuentaRepository;
use App\Repository\DeudaRepository;
use App\Support\Dinero;
use PDO;

/**
 * Operaciones transaccionales del módulo de Deudas (Fase 11):
 *  - registrarPago: bloquea la cuenta y la deuda (`FOR UPDATE`), verifica saldo disponible, calcula el desglose
 *    entre intereses y capital con {@see ReglasPagoDeuda}, inserta el pago y si el saldo de capital llega a 0 marca
 *    la deuda como `pagada`;
 *  - editar: actualiza los datos de una deuda activa y recalcula si quedó pagada;
 *  - eliminar: elimina una deuda que aún no tenga pagos registrados.
 */
final class GestionDeudas
{
    public const OK = 'ok';
    public const NO_EXISTE = 'no_existe';
    public const TIENE_PAGOS = 'tiene_pagos';

    public function __construct(
        private readonly PDO $pdo,
        private readonly DeudaRepository $deudas,
        private readonly CuentaRepository $cuentas,
    ) {
    }

    /**
     * @param array{id_cuenta: int, monto_total: string, modo_pago: string, fecha: string, descripcion: string|null} $datos
     * @return array{codigo: string, abono_capital: string, pago_interes: string, nuevo_saldo: string, pagada: bool, cuenta: string|null, saldo_cuenta: string|null}
     */
    public function registrarPago(int $userId, int $deudaId, array $datos, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $deudaId, $datos, $espacioId): array {
            $cuenta = $this->cuentas->lockForWrite($userId, $datos['id_cuenta'], $espacioId);
            $deuda = $this->deudas->lockOwned($userId, $deudaId, $espacioId);
            $saldoCuenta = $cuenta === null ? '0.00' : (string) ($this->cuentas->findOwned($userId, $datos['id_cuenta'], $espacioId)?->saldo ?? '0.00');

            $infoDeuda = $deuda === null ? null : [
                'estado' => $deuda->estado,
                'saldo_capital' => $deuda->saldoCapital(),
                'tasa_mensual_bps' => $deuda->tasaMensualBps,
            ];

            $eval = ReglasPagoDeuda::evaluar(
                $cuenta,
                $infoDeuda,
                $saldoCuenta,
                $datos['monto_total'],
                $datos['modo_pago'],
            );

            if ($eval['codigo'] !== ReglasPagoDeuda::OK || $deuda === null) {
                return [
                    'codigo' => $eval['codigo'],
                    'abono_capital' => '0.00',
                    'pago_interes' => '0.00',
                    'nuevo_saldo' => $deuda?->saldoCapital() ?? '0.00',
                    'pagada' => false,
                    'cuenta' => $cuenta['nombre'] ?? null,
                    'saldo_cuenta' => $cuenta === null ? null : $saldoCuenta,
                ];
            }

            $this->deudas->insertPago(
                $deuda->id,
                $datos['id_cuenta'],
                $userId,
                $datos['monto_total'],
                $eval['abono_capital'],
                $eval['pago_interes'],
                $datos['fecha'],
                $datos['descripcion'],
            );

            $pagada = Dinero::aCentavos($eval['nuevo_saldo_capital']) <= 0;
            if ($pagada) {
                $this->deudas->setEstado($userId, $deuda->id, 'pagada', $espacioId);
            }

            return [
                'codigo' => ReglasPagoDeuda::OK,
                'abono_capital' => $eval['abono_capital'],
                'pago_interes' => $eval['pago_interes'],
                'nuevo_saldo' => $eval['nuevo_saldo_capital'],
                'pagada' => $pagada,
                'cuenta' => $cuenta['nombre'] ?? null,
                'saldo_cuenta' => $saldoCuenta,
            ];
        });
    }

    /**
     * @param array{nombre: string, acreedor: string|null, monto_inicial: string, tasa_mensual_bps: int, cuota_minima: string, dia_pago: int|null} $datos
     * @return array{codigo: string, pagada: bool}
     */
    public function editar(int $userId, int $deudaId, array $datos, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $deudaId, $datos, $espacioId): array {
            $deuda = $this->deudas->lockOwned($userId, $deudaId, $espacioId);

            if ($deuda === null) {
                return ['codigo' => self::NO_EXISTE, 'pagada' => false];
            }

            $this->deudas->update($userId, $deudaId, $datos, $espacioId);
            $pagada = Dinero::aCentavos($deuda->capitalAbonado) >= Dinero::aCentavos($datos['monto_inicial']);
            $this->deudas->setEstado($userId, $deudaId, $pagada ? 'pagada' : 'activa', $espacioId);

            return ['codigo' => self::OK, 'pagada' => $pagada];
        });
    }

    public function eliminar(int $userId, int $deudaId, ?int $espacioId = null): string
    {
        return Transaction::run($this->pdo, function () use ($userId, $deudaId, $espacioId): string {
            $deuda = $this->deudas->lockOwned($userId, $deudaId, $espacioId);

            if ($deuda === null) {
                return self::NO_EXISTE;
            }

            if ($deuda->numPagos > 0) {
                return self::TIENE_PAGOS;
            }

            $this->deudas->delete($userId, $deudaId, $espacioId);

            return self::OK;
        });
    }
}
