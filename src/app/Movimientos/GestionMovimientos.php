<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Database\Transaction;
use App\Repository\CategoriaRepository;
use App\Repository\CuentaRepository;
use App\Repository\CuotaTarjetaRepository;
use App\Repository\MovimientoRepository;
use App\Support\Dinero;
use App\Tarjetas\PlanCuotas;
use PDO;

/**
 * Crear y editar movimientos respetando D8, tanto en el espacio personal como en un espacio compartido (`$espacioId`).
 */
final class GestionMovimientos
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MovimientoRepository $movimientos,
        private readonly CuentaRepository $cuentas,
        private readonly CategoriaRepository $categorias,
        private readonly ?CuotaTarjetaRepository $cuotasTarjeta = null,
    ) {
    }

    /**
     * @param int|null $movimientoId null = movimiento nuevo
     * @param array{tipo: string, monto: string, fecha: string, id_cuenta: int, id_categoria: int, descripcion: string|null, etiqueta?: string|null, num_cuotas?: int} $datos
     * @return array{codigo: string, id: int|null, cuenta: string|null, saldo?: string|null}
     */
    public function guardar(int $userId, ?int $movimientoId, array $datos, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $movimientoId, $datos, $espacioId): array {
            $anterior = $movimientoId !== null ? $this->movimientos->findOwned($userId, $movimientoId, $espacioId) : null;

            if ($movimientoId !== null && $anterior === null) {
                return ['codigo' => 'no_existe', 'id' => null, 'cuenta' => null, 'saldo' => null];
            }

            if ($anterior !== null && $anterior->idCuenta !== $datos['id_cuenta']) {
                $this->cuentas->lockForWrite($userId, $anterior->idCuenta, $espacioId);
            }

            $cuenta = $this->cuentas->lockForWrite($userId, $datos['id_cuenta'], $espacioId);
            $categoria = $this->categorias->findById($datos['id_categoria']);

            $saldoDisponible = null;
            if ($cuenta !== null) {
                $saldoActual = (string) ($this->cuentas->findOwned($userId, $datos['id_cuenta'], $espacioId)?->saldo ?? '0.00');
                if ($anterior !== null && $anterior->idCuenta === $datos['id_cuenta']) {
                    $efectoRevertido = $anterior->esIngreso() ? '-' . $anterior->monto : $anterior->monto;
                    $saldoDisponible = Dinero::sumar([$saldoActual, $efectoRevertido]);
                } else {
                    $saldoDisponible = $saldoActual;
                }
            }

            $codigo = ReglasMovimiento::evaluar($cuenta, $categoria, $userId, $datos['tipo'], $saldoDisponible, $datos['monto'], $espacioId);

            if ($codigo !== ReglasMovimiento::OK) {
                return ['codigo' => $codigo, 'id' => null, 'cuenta' => $cuenta['nombre'] ?? null, 'saldo' => $saldoDisponible];
            }

            if ($anterior !== null && $anterior->esIngreso()) {
                if ($anterior->idCuenta !== $datos['id_cuenta']) {
                    $cuentaOrigen = $this->cuentas->findOwned($userId, $anterior->idCuenta, $espacioId);
                    if ($cuentaOrigen !== null && $cuentaOrigen->tipo !== 'tarjeta_credito') {
                        $saldoOrigenSinIngreso = Dinero::sumar([$cuentaOrigen->saldo, '-' . $anterior->monto]);
                        if (Dinero::aCentavos($saldoOrigenSinIngreso) < 0) {
                            return ['codigo' => ReglasMovimiento::SALDO_INSUFICIENTE, 'id' => null, 'cuenta' => $cuentaOrigen->nombre, 'saldo' => $cuentaOrigen->saldo];
                        }
                    }
                } elseif ($datos['tipo'] === 'ingreso' && ($cuenta['tipo'] ?? '') !== 'tarjeta_credito') {
                    $saldoFinal = Dinero::sumar([(string) $saldoDisponible, $datos['monto']]);
                    if (Dinero::aCentavos($saldoFinal) < 0) {
                        return ['codigo' => ReglasMovimiento::SALDO_INSUFICIENTE, 'id' => null, 'cuenta' => $cuenta['nombre'] ?? null, 'saldo' => $saldoDisponible];
                    }
                }
            }

            $esGastoTarjeta = ($cuenta['tipo'] ?? '') === 'tarjeta_credito' && $datos['tipo'] === 'gasto';
            $numCuotas = $esGastoTarjeta ? max(1, min(36, (int) ($datos['num_cuotas'] ?? 1))) : 1;
            $conIntereses = $esGastoTarjeta && $numCuotas > 1 ? (bool) ($datos['con_intereses'] ?? true) : false;
            $tasaBps = ($conIntereses && $numCuotas > 1) ? (int) ($cuenta['tasa_mensual_bps'] ?? 0) : 0;

            if ($movimientoId === null) {
                $id = $this->movimientos->insert(
                    $userId,
                    $datos['id_cuenta'],
                    $datos['id_categoria'],
                    $datos['tipo'],
                    $datos['monto'],
                    $datos['fecha'],
                    $datos['descripcion'],
                    $espacioId,
                    $datos['etiqueta'] ?? null,
                    null,
                    $numCuotas,
                    $conIntereses,
                    $tasaBps,
                );
            } else {
                $this->movimientos->update(
                    $userId,
                    $movimientoId,
                    $datos['id_cuenta'],
                    $datos['id_categoria'],
                    $datos['tipo'],
                    $datos['monto'],
                    $datos['fecha'],
                    $datos['descripcion'],
                    $espacioId,
                    $datos['etiqueta'] ?? null,
                    $numCuotas,
                    $conIntereses,
                    $tasaBps,
                );
                $id = $movimientoId;
            }

            if ($this->cuotasTarjeta !== null) {
                $cuotas = $esGastoTarjeta
                    ? PlanCuotas::generar($datos['monto'], $numCuotas, $datos['fecha'], $cuenta['dia_corte'] ?? null, $conIntereses, $tasaBps)
                    : [];
                $this->cuotasTarjeta->replaceForTransaccion($id, $datos['id_cuenta'], $cuotas);
            }

            return ['codigo' => ReglasMovimiento::OK, 'id' => $id, 'cuenta' => $cuenta['nombre'] ?? null, 'saldo' => $saldoDisponible];
        });
    }

    /**
     * Registra un movimiento dividido en varias categorías en una sola transacción (Split Transactions · Fase 14).
     *
     * @param array{tipo: string, monto_total: string, fecha: string, id_cuenta: int, descripcion: string|null, etiqueta?: string|null} $base
     * @param list<array{id_categoria: int, monto: string, descripcion: string|null}> $partes
     * @return array{codigo: string, ids: list<int>, grupo_split: string|null, cuenta: string|null, saldo: string|null}
     */
    public function guardarDividido(int $userId, array $base, array $partes, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $base, $partes, $espacioId): array {
            $cuenta = $this->cuentas->lockForWrite($userId, $base['id_cuenta'], $espacioId);
            $saldoDisponible = $cuenta !== null
                ? (string) ($this->cuentas->findOwned($userId, $base['id_cuenta'], $espacioId)?->saldo ?? '0.00')
                : null;

            $montos = array_map(static fn (array $p): string => $p['monto'], $partes);
            $montoTotal = Dinero::sumar($montos);

            foreach ($partes as $parte) {
                $categoria = $this->categorias->findById($parte['id_categoria']);
                $codigo = ReglasMovimiento::evaluar(
                    $cuenta,
                    $categoria,
                    $userId,
                    $base['tipo'],
                    $saldoDisponible,
                    $montoTotal,
                    $espacioId,
                );

                if ($codigo !== ReglasMovimiento::OK) {
                    return [
                        'codigo' => $codigo,
                        'ids' => [],
                        'grupo_split' => null,
                        'cuenta' => $cuenta['nombre'] ?? null,
                        'saldo' => $saldoDisponible,
                    ];
                }
            }

            $grupoSplit = bin2hex(random_bytes(8));
            $etiqueta = $base['etiqueta'] ?? null;
            $esGastoTarjeta = ($cuenta['tipo'] ?? '') === 'tarjeta_credito' && $base['tipo'] === 'gasto';
            $numCuotas = $esGastoTarjeta ? max(1, min(36, (int) ($base['num_cuotas'] ?? 1))) : 1;
            $conIntereses = $esGastoTarjeta && $numCuotas > 1 ? (bool) ($base['con_intereses'] ?? true) : false;
            $tasaBps = ($conIntereses && $numCuotas > 1) ? (int) ($cuenta['tasa_mensual_bps'] ?? 0) : 0;
            $ids = [];

            foreach ($partes as $parte) {
                $id = $this->movimientos->insert(
                    $userId,
                    $base['id_cuenta'],
                    $parte['id_categoria'],
                    $base['tipo'],
                    $parte['monto'],
                    $base['fecha'],
                    $parte['descripcion'],
                    $espacioId,
                    $etiqueta,
                    $grupoSplit,
                    $numCuotas,
                    $conIntereses,
                    $tasaBps,
                );
                $ids[] = $id;

                if ($this->cuotasTarjeta !== null && $esGastoTarjeta) {
                    $cuotas = PlanCuotas::generar($parte['monto'], $numCuotas, $base['fecha'], $cuenta['dia_corte'] ?? null, $conIntereses, $tasaBps);
                    $this->cuotasTarjeta->replaceForTransaccion($id, $base['id_cuenta'], $cuotas);
                }
            }

            return [
                'codigo' => ReglasMovimiento::OK,
                'ids' => $ids,
                'grupo_split' => $grupoSplit,
                'cuenta' => $cuenta['nombre'] ?? null,
                'saldo' => $saldoDisponible,
            ];
        });
    }

    public function eliminar(int $userId, int $movimientoId, ?int $espacioId = null): bool
    {
        return Transaction::run($this->pdo, function () use ($userId, $movimientoId, $espacioId): bool {
            $movimiento = $this->movimientos->findOwned($userId, $movimientoId, $espacioId);

            if ($movimiento === null) {
                return false;
            }

            $this->cuentas->lockForWrite($userId, $movimiento->idCuenta, $espacioId);

            return $this->movimientos->delete($userId, $movimientoId, $espacioId);
        });
    }
}
