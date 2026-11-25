<?php

declare(strict_types=1);

namespace App\Recurrentes;

use App\Database\Transaction;
use App\Movimientos\ReglasMovimiento;
use App\Repository\CategoriaRepository;
use App\Repository\CuentaRepository;
use App\Repository\MovimientoRepository;
use App\Repository\RecurrenteRepository;
use App\Support\Frecuencia;
use PDO;

/**
 * Gestión transaccional de movimientos recurrentes y gastos fijos programados (Fase 9), con soporte para espacios compartidos (Fase 10).
 */
final class GestionRecurrentes
{
    public const NO_EXISTE = 'no_existe';
    public const MAX_PERIODOS_AUTO = 24;

    public function __construct(
        private readonly PDO $pdo,
        private readonly RecurrenteRepository $recurrentes,
        private readonly MovimientoRepository $movimientos,
        private readonly CuentaRepository $cuentas,
        private readonly CategoriaRepository $categorias,
    ) {
    }

    /**
     * @param array{tipo: string, monto: string, frecuencia: string, proxima_fecha: string, id_cuenta: int, id_categoria: int, descripcion: string|null, auto_aplicar: bool} $datos
     * @return array{codigo: string, id: int|null, cuenta: string|null}
     */
    public function guardar(int $userId, ?int $recurrenteId, array $datos, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $recurrenteId, $datos, $espacioId): array {
            if ($recurrenteId !== null && $this->recurrentes->findOwned($userId, $recurrenteId, $espacioId) === null) {
                return ['codigo' => self::NO_EXISTE, 'id' => null, 'cuenta' => null];
            }

            $cuenta = $this->cuentas->lockForWrite($userId, $datos['id_cuenta'], $espacioId);
            $categoria = $this->categorias->findById($datos['id_categoria']);
            $codigo = ReglasMovimiento::evaluar($cuenta, $categoria, $userId, $datos['tipo'], null, '0.00', $espacioId);

            if ($codigo !== ReglasMovimiento::OK) {
                return ['codigo' => $codigo, 'id' => null, 'cuenta' => $cuenta['nombre'] ?? null];
            }

            if ($recurrenteId === null) {
                $id = $this->recurrentes->insert($userId, $datos, $espacioId);
            } else {
                $this->recurrentes->update($userId, $recurrenteId, $datos, $espacioId);
                $id = $recurrenteId;
            }

            return ['codigo' => ReglasMovimiento::OK, 'id' => $id, 'cuenta' => $cuenta['nombre'] ?? null];
        });
    }

    /**
     * @return array{codigo: string, movimientoId: int|null, cuenta: string|null, saldo: string|null, proximaFecha: string|null}
     */
    public function aplicarAhora(int $userId, int $recurrenteId, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $recurrenteId, $espacioId): array {
            $rec = $this->recurrentes->lockOwned($userId, $recurrenteId, $espacioId);

            if ($rec === null) {
                return ['codigo' => self::NO_EXISTE, 'movimientoId' => null, 'cuenta' => null, 'saldo' => null, 'proximaFecha' => null];
            }

            $cuenta = $this->cuentas->lockForWrite($userId, $rec->idCuenta, $espacioId);
            $categoria = $this->categorias->findById($rec->idCategoria);
            $saldo = $cuenta === null ? '0.00' : (string) ($this->cuentas->findOwned($userId, $rec->idCuenta, $espacioId)?->saldo ?? '0.00');

            $codigo = ReglasMovimiento::evaluar($cuenta, $categoria, $userId, $rec->tipo, $saldo, $rec->monto, $espacioId);

            if ($codigo !== ReglasMovimiento::OK) {
                return [
                    'codigo' => $codigo,
                    'movimientoId' => null,
                    'cuenta' => $cuenta['nombre'] ?? $rec->cuentaNombre,
                    'saldo' => $saldo,
                    'proximaFecha' => $rec->proximaFecha,
                ];
            }

            $movId = $this->movimientos->insert(
                $userId,
                $rec->idCuenta,
                $rec->idCategoria,
                $rec->tipo,
                $rec->monto,
                $rec->proximaFecha,
                $rec->descripcion,
                $espacioId,
            );

            $siguiente = Frecuencia::siguiente($rec->proximaFecha, $rec->frecuencia);
            $this->recurrentes->setProximaFecha($userId, $rec->id, $siguiente, $espacioId);

            return [
                'codigo' => ReglasMovimiento::OK,
                'movimientoId' => $movId,
                'cuenta' => $cuenta['nombre'] ?? $rec->cuentaNombre,
                'saldo' => $saldo,
                'proximaFecha' => $siguiente,
            ];
        });
    }

    public function saltarPeriodo(int $userId, int $recurrenteId, ?int $espacioId = null): ?string
    {
        return Transaction::run($this->pdo, function () use ($userId, $recurrenteId, $espacioId): ?string {
            $rec = $this->recurrentes->lockOwned($userId, $recurrenteId, $espacioId);

            if ($rec === null) {
                return null;
            }

            $siguiente = Frecuencia::siguiente($rec->proximaFecha, $rec->frecuencia);
            $this->recurrentes->setProximaFecha($userId, $rec->id, $siguiente, $espacioId);

            return $siguiente;
        });
    }

    /**
     * @return array{aplicados: int, retenidosPorSaldo: int}
     */
    public function procesarVencidos(int $userId, string $hoy, ?int $espacioId = null): array
    {
        $aplicados = 0;
        $retenidos = 0;

        foreach ($this->recurrentes->listDueByUser($userId, $hoy, $espacioId) as $rec) {
            if (!$rec->autoAplicar) {
                continue;
            }

            $iteraciones = 0;
            $fechaCursor = $rec->proximaFecha;

            while ($fechaCursor <= $hoy && $iteraciones < self::MAX_PERIODOS_AUTO) {
                $res = $this->aplicarAhora($userId, $rec->id, $espacioId);

                if ($res['codigo'] !== ReglasMovimiento::OK) {
                    if ($res['codigo'] === ReglasMovimiento::SALDO_INSUFICIENTE || $res['codigo'] === ReglasMovimiento::CUENTA_INACTIVA) {
                        $retenidos++;
                    }
                    break;
                }

                $aplicados++;
                $iteraciones++;
                $fechaCursor = (string) $res['proximaFecha'];
            }
        }

        return ['aplicados' => $aplicados, 'retenidosPorSaldo' => $retenidos];
    }
}
