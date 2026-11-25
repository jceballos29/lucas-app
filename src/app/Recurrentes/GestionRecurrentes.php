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
 * Gestión transaccional de movimientos recurrentes y gastos fijos programados (Fase 9):
 *  - guardar (crear o editar una programación comprobando que la cuenta y la categoría pertenezcan al usuario y coincidan en tipo);
 *  - aplicarAhora (materializa una ocurrencia de la programación en `transacciones` y avanza `proxima_fecha`);
 *  - saltarPeriodo (avanza `proxima_fecha` sin crear movimiento);
 *  - procesarVencidos (ejecuta automáticamente los que tienen `auto_aplicar = 1` y `proxima_fecha <= $hoy`, con un tope
 *    de seguridad de 24 periodos por regla; si la cuenta normal no tiene saldo suficiente o está inactiva, se detiene sin
 *    fallar y lo deja pendiente para que el usuario lo vea en pantalla).
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
    public function guardar(int $userId, ?int $recurrenteId, array $datos): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $recurrenteId, $datos): array {
            if ($recurrenteId !== null && $this->recurrentes->findOwned($userId, $recurrenteId) === null) {
                return ['codigo' => self::NO_EXISTE, 'id' => null, 'cuenta' => null];
            }

            $cuenta = $this->cuentas->lockForWrite($userId, $datos['id_cuenta']);
            $categoria = $this->categorias->findById($datos['id_categoria']);
            // Al configurar una plantilla recurrente no se exige el saldo hoy (se exigirá el día que se ejecute),
            // pero sí que la cuenta exista, esté activa y la categoría coincida con el tipo.
            $codigo = ReglasMovimiento::evaluar($cuenta, $categoria, $userId, $datos['tipo']);

            if ($codigo !== ReglasMovimiento::OK) {
                return ['codigo' => $codigo, 'id' => null, 'cuenta' => $cuenta['nombre'] ?? null];
            }

            if ($recurrenteId === null) {
                $id = $this->recurrentes->insert($userId, $datos);
            } else {
                $this->recurrentes->update($userId, $recurrenteId, $datos);
                $id = $recurrenteId;
            }

            return ['codigo' => ReglasMovimiento::OK, 'id' => $id, 'cuenta' => $cuenta['nombre'] ?? null];
        });
    }

    /**
     * Registra en `transacciones` la ocurrencia actual de un movimiento recurrente y avanza su `proxima_fecha`.
     *
     * @return array{codigo: string, movimientoId: int|null, cuenta: string|null, saldo: string|null, proximaFecha: string|null}
     */
    public function aplicarAhora(int $userId, int $recurrenteId): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $recurrenteId): array {
            $rec = $this->recurrentes->lockOwned($userId, $recurrenteId);

            if ($rec === null) {
                return ['codigo' => self::NO_EXISTE, 'movimientoId' => null, 'cuenta' => null, 'saldo' => null, 'proximaFecha' => null];
            }

            $cuenta = $this->cuentas->lockForWrite($userId, $rec->idCuenta);
            $categoria = $this->categorias->findById($rec->idCategoria);
            $saldo = $cuenta === null ? '0.00' : (string) ($this->cuentas->findOwned($userId, $rec->idCuenta)?->saldo ?? '0.00');

            $codigo = ReglasMovimiento::evaluar($cuenta, $categoria, $userId, $rec->tipo, $saldo, $rec->monto);

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
            );

            $siguiente = Frecuencia::siguiente($rec->proximaFecha, $rec->frecuencia);
            $this->recurrentes->setProximaFecha($userId, $rec->id, $siguiente);

            return [
                'codigo' => ReglasMovimiento::OK,
                'movimientoId' => $movId,
                'cuenta' => $cuenta['nombre'] ?? $rec->cuentaNombre,
                'saldo' => $saldo,
                'proximaFecha' => $siguiente,
            ];
        });
    }

    /**
     * Avanza `proxima_fecha` al siguiente periodo sin registrar movimiento en `transacciones`.
     */
    public function saltarPeriodo(int $userId, int $recurrenteId): ?string
    {
        return Transaction::run($this->pdo, function () use ($userId, $recurrenteId): ?string {
            $rec = $this->recurrentes->lockOwned($userId, $recurrenteId);

            if ($rec === null) {
                return null;
            }

            $siguiente = Frecuencia::siguiente($rec->proximaFecha, $rec->frecuencia);
            $this->recurrentes->setProximaFecha($userId, $rec->id, $siguiente);

            return $siguiente;
        });
    }

    /**
     * Ejecuta los movimientos recurrentes activos con `auto_aplicar = true` y `proxima_fecha <= $hoy`.
     *
     * @return array{aplicados: int, retenidosPorSaldo: int}
     */
    public function procesarVencidos(int $userId, string $hoy): array
    {
        $aplicados = 0;
        $retenidos = 0;

        foreach ($this->recurrentes->listDueByUser($userId, $hoy) as $rec) {
            if (!$rec->autoAplicar) {
                continue;
            }

            $iteraciones = 0;
            $fechaCursor = $rec->proximaFecha;

            while ($fechaCursor <= $hoy && $iteraciones < self::MAX_PERIODOS_AUTO) {
                $res = $this->aplicarAhora($userId, $rec->id);

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
