<?php

declare(strict_types=1);

namespace App\Transferencias;

use App\Database\Transaction;
use App\Repository\CuentaRepository;
use App\Repository\TasaCambioRepository;
use App\Repository\TransferenciaRepository;
use App\Support\ConversorMoneda;
use App\Support\Dinero;
use PDO;

/**
 * Crear, editar y eliminar transferencias entre cuentas en UNA transacción, en el espacio personal o compartido.
 * Soporta transferencias multimoneda mediante monto destino explícito, tasa aplicada o tasa de referencia guardada.
 */
final class GestionTransferencias
{
    public const NO_EXISTE = 'no_existe';

    public function __construct(
        private readonly PDO $pdo,
        private readonly TransferenciaRepository $transferencias,
        private readonly CuentaRepository $cuentas,
        private readonly ?TasaCambioRepository $tasas = null,
    ) {
    }

    /**
     * @param int|null $transferenciaId null = nueva transferencia
     * @param array{id_cuenta_origen: int, id_cuenta_destino: int, monto: string, monto_destino?: string|null, tasa_micros?: int|null, fecha: string, descripcion: string|null} $datos
     * @return array{codigo: string, id: int|null, origen: string|null, destino: string|null, saldo: string|null, moneda_origen?: string|null, moneda_destino?: string|null, monto_destino?: string|null}
     */
    public function guardar(int $userId, ?int $transferenciaId, array $datos, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $transferenciaId, $datos, $espacioId): array {
            $anterior = $transferenciaId !== null ? $this->transferencias->findOwned($userId, $transferenciaId, $espacioId) : null;

            if ($transferenciaId !== null && $anterior === null) {
                return ['codigo' => self::NO_EXISTE, 'id' => null, 'origen' => null, 'destino' => null, 'saldo' => null];
            }

            $ids = array_unique(array_filter([
                $datos['id_cuenta_origen'],
                $datos['id_cuenta_destino'],
                $anterior !== null ? $anterior->idCuentaOrigen : 0,
                $anterior !== null ? $anterior->idCuentaDestino : 0,
            ]));
            sort($ids);

            $bloqueadas = [];
            foreach ($ids as $cid) {
                $bloqueadas[$cid] = $this->cuentas->lockForWrite($userId, $cid, $espacioId);
            }

            $origen = $bloqueadas[$datos['id_cuenta_origen']] ?? null;
            $destino = $bloqueadas[$datos['id_cuenta_destino']] ?? null;

            $monedaBase = Dinero::monedaActual();
            $monedaOrigen = $origen !== null ? ($origen['moneda'] ?? $monedaBase) : null;
            $monedaDestino = $destino !== null ? ($destino['moneda'] ?? $monedaBase) : null;

            $saldoDisponibleOrigen = '0.00';
            if ($origen !== null) {
                $saldoActual = $this->cuentas->findOwned($userId, $datos['id_cuenta_origen'], $espacioId)->saldo ?? '0.00';
                $ajustePrevio = '0.00';
                if ($anterior !== null) {
                    if ($anterior->idCuentaOrigen === $datos['id_cuenta_origen']) {
                        $ajustePrevio = $anterior->monto;
                    } elseif ($anterior->idCuentaDestino === $datos['id_cuenta_origen']) {
                        $ajustePrevio = '-' . $anterior->montoRecibido();
                    }
                }
                $saldoDisponibleOrigen = Dinero::sumar([$saldoActual, $ajustePrevio]);
            }

            $codigo = ReglasTransferencia::evaluar(
                $origen,
                $destino,
                $datos['id_cuenta_origen'],
                $datos['id_cuenta_destino'],
                $saldoDisponibleOrigen,
                $datos['monto'],
            );

            if ($codigo !== ReglasTransferencia::OK) {
                return [
                    'codigo' => $codigo,
                    'id' => null,
                    'origen' => $origen['nombre'] ?? null,
                    'destino' => $destino['nombre'] ?? null,
                    'saldo' => $saldoDisponibleOrigen,
                    'moneda_origen' => $monedaOrigen,
                    'moneda_destino' => $monedaDestino,
                    'monto_destino' => null,
                ];
            }

            // Resolver monto acreditado en cuenta destino cuando ambas cuentas tienen distinta moneda.
            $montoDestinoFinal = null;
            if ($monedaOrigen !== null && $monedaDestino !== null && $monedaOrigen !== $monedaDestino) {
                $explicitDest = $datos['monto_destino'] ?? null;
                $explicitTasa = $datos['tasa_micros'] ?? null;

                if (is_string($explicitDest) && Dinero::aCentavos($explicitDest) > 0) {
                    $montoDestinoFinal = $explicitDest;
                } elseif (is_int($explicitTasa) && $explicitTasa > 0) {
                    $montoDestinoFinal = ConversorMoneda::convertirConTasa($datos['monto'], $explicitTasa);
                } elseif ($this->tasas !== null) {
                    $mapaTasas = $this->tasas->listMap($userId, $espacioId);
                    $montoDestinoFinal = ConversorMoneda::convertir($datos['monto'], $monedaOrigen, $monedaDestino, $mapaTasas);
                }

                if ($montoDestinoFinal === null || Dinero::aCentavos($montoDestinoFinal) <= 0) {
                    return [
                        'codigo' => ReglasTransferencia::FALTA_CONVERSION,
                        'id' => null,
                        'origen' => $origen['nombre'] ?? null,
                        'destino' => $destino['nombre'] ?? null,
                        'saldo' => $saldoDisponibleOrigen,
                        'moneda_origen' => $monedaOrigen,
                        'moneda_destino' => $monedaDestino,
                        'monto_destino' => null,
                    ];
                }
            }

            if ($anterior !== null) {
                $antiguoDestino = $this->cuentas->findOwned($userId, $anterior->idCuentaDestino, $espacioId);
                if ($antiguoDestino !== null && $antiguoDestino->tipo !== 'tarjeta_credito') {
                    $nuevoEfectoEnAntiguoDestino = '0.00';
                    if ($datos['id_cuenta_destino'] === $anterior->idCuentaDestino) {
                        $nuevoEfectoEnAntiguoDestino = $montoDestinoFinal ?? $datos['monto'];
                    } elseif ($datos['id_cuenta_origen'] === $anterior->idCuentaDestino) {
                        $nuevoEfectoEnAntiguoDestino = '-' . $datos['monto'];
                    }
                    $saldoFinalAntiguoDestino = Dinero::sumar([$antiguoDestino->saldo, '-' . $anterior->montoRecibido(), $nuevoEfectoEnAntiguoDestino]);
                    if (Dinero::aCentavos($saldoFinalAntiguoDestino) < 0) {
                        return [
                            'codigo' => ReglasTransferencia::SALDO_INSUFICIENTE,
                            'id' => null,
                            'origen' => $antiguoDestino->nombre,
                            'destino' => $destino['nombre'] ?? null,
                            'saldo' => $antiguoDestino->saldo,
                            'moneda_origen' => $antiguoDestino->monedaEfectiva($monedaBase),
                            'moneda_destino' => $monedaDestino,
                            'monto_destino' => null,
                        ];
                    }
                }
            }

            if ($transferenciaId === null) {
                $id = $this->transferencias->insert(
                    $userId,
                    $datos['id_cuenta_origen'],
                    $datos['id_cuenta_destino'],
                    $datos['monto'],
                    $datos['fecha'],
                    $datos['descripcion'],
                    $espacioId,
                    $montoDestinoFinal,
                );
            } else {
                $this->transferencias->update(
                    $userId,
                    $transferenciaId,
                    $datos['id_cuenta_origen'],
                    $datos['id_cuenta_destino'],
                    $datos['monto'],
                    $datos['fecha'],
                    $datos['descripcion'],
                    $espacioId,
                    $montoDestinoFinal,
                );
                $id = $transferenciaId;
            }

            return [
                'codigo' => ReglasTransferencia::OK,
                'id' => $id,
                'origen' => $origen['nombre'] ?? null,
                'destino' => $destino['nombre'] ?? null,
                'saldo' => $saldoDisponibleOrigen,
                'moneda_origen' => $monedaOrigen,
                'moneda_destino' => $monedaDestino,
                'monto_destino' => $montoDestinoFinal,
            ];
        });
    }

    /**
     * @return array{codigo: string, cuenta: string|null}
     */
    public function eliminar(int $userId, int $transferenciaId, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $transferenciaId, $espacioId): array {
            $transferencia = $this->transferencias->findOwned($userId, $transferenciaId, $espacioId);

            if ($transferencia === null) {
                return ['codigo' => self::NO_EXISTE, 'cuenta' => null];
            }

            $ids = [$transferencia->idCuentaOrigen, $transferencia->idCuentaDestino];
            sort($ids);
            foreach ($ids as $cid) {
                $this->cuentas->lockForWrite($userId, $cid, $espacioId);
            }

            $destino = $this->cuentas->findOwned($userId, $transferencia->idCuentaDestino, $espacioId);
            if ($destino !== null && $destino->tipo !== 'tarjeta_credito') {
                $saldoSinTransferencia = Dinero::sumar([$destino->saldo, '-' . $transferencia->montoRecibido()]);
                if (Dinero::aCentavos($saldoSinTransferencia) < 0) {
                    return ['codigo' => ReglasTransferencia::SALDO_INSUFICIENTE, 'cuenta' => $destino->nombre];
                }
            }

            $this->transferencias->delete($userId, $transferenciaId, $espacioId);

            return ['codigo' => ReglasTransferencia::OK, 'cuenta' => null];
        });
    }
}
