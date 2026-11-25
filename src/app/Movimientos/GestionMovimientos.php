<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Database\Transaction;
use App\Repository\CategoriaRepository;
use App\Repository\CuentaRepository;
use App\Repository\MovimientoRepository;
use App\Support\Dinero;
use PDO;

/**
 * Crear y editar movimientos respetando D8. Todo ocurre en UNA transacción: se bloquea la cuenta (`FOR UPDATE`), se
 * comprueban las reglas con lo leído ahí dentro (incluyendo que una cuenta normal no quede con saldo negativo) y solo
 * entonces se escribe. Así una cuenta que se desactiva a la vez no recibe movimientos «a escondidas»; borrar también
 * bloquea la cuenta. El saldo no se guarda, se calcula (CuentaRepository), de modo que alta, edición y borrado lo
 * actualizan solos (RF-14).
 */
final class GestionMovimientos
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MovimientoRepository $movimientos,
        private readonly CuentaRepository $cuentas,
        private readonly CategoriaRepository $categorias,
    ) {
    }

    /**
     * @param int|null $movimientoId null = movimiento nuevo
     * @param array{tipo: string, monto: string, fecha: string, id_cuenta: int, id_categoria: int, descripcion: string|null} $datos ya validados
     * @return array{codigo: string, id: int|null, cuenta: string|null, saldo?: string|null} El resultado ({@see ReglasMovimiento},
     *         o 'no_existe' si el movimiento que se edita no es del usuario), el id guardado, el nombre de la cuenta y su saldo
     *         disponible cuando no alcanza.
     */
    public function guardar(int $userId, ?int $movimientoId, array $datos): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $movimientoId, $datos): array {
            $anterior = $movimientoId !== null ? $this->movimientos->findOwned($userId, $movimientoId) : null;

            if ($movimientoId !== null && $anterior === null) {
                return ['codigo' => 'no_existe', 'id' => null, 'cuenta' => null, 'saldo' => null];
            }

            // Si al editar se cambia de cuenta, también se bloquea la cuenta de origen para que nadie gaste a la vez.
            if ($anterior !== null && $anterior->idCuenta !== $datos['id_cuenta']) {
                $this->cuentas->lockForWrite($userId, $anterior->idCuenta);
            }

            $cuenta = $this->cuentas->lockForWrite($userId, $datos['id_cuenta']);
            $categoria = $this->categorias->findById($datos['id_categoria']);

            // Saldo disponible en la cuenta destino ANTES de aplicar el movimiento nuevo (si se está editando un movimiento de la
            // misma cuenta, se revierte primero su efecto anterior: un gasto viejo se devuelve y un ingreso viejo se descuenta).
            $saldoDisponible = null;
            if ($cuenta !== null) {
                $saldoActual = (string) ($this->cuentas->findOwned($userId, $datos['id_cuenta'])?->saldo ?? '0.00');
                if ($anterior !== null && $anterior->idCuenta === $datos['id_cuenta']) {
                    $efectoRevertido = $anterior->esIngreso() ? '-' . $anterior->monto : $anterior->monto;
                    $saldoDisponible = Dinero::sumar([$saldoActual, $efectoRevertido]);
                } else {
                    $saldoDisponible = $saldoActual;
                }
            }

            $codigo = ReglasMovimiento::evaluar($cuenta, $categoria, $userId, $datos['tipo'], $saldoDisponible, $datos['monto']);

            if ($codigo !== ReglasMovimiento::OK) {
                return ['codigo' => $codigo, 'id' => null, 'cuenta' => $cuenta['nombre'] ?? null, 'saldo' => $saldoDisponible];
            }

            // Si al editar un ingreso se cambia a gasto, se baja su monto o se mueve a otra cuenta, la cuenta donde estaba antes
            // tampoco puede quedar en negativo (salvo que sea tarjeta de crédito).
            if ($anterior !== null && $anterior->esIngreso()) {
                if ($anterior->idCuenta !== $datos['id_cuenta']) {
                    $cuentaOrigen = $this->cuentas->findOwned($userId, $anterior->idCuenta);
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

            if ($movimientoId === null) {
                $id = $this->movimientos->insert($userId, $datos['id_cuenta'], $datos['id_categoria'], $datos['tipo'], $datos['monto'], $datos['fecha'], $datos['descripcion']);
            } else {
                $this->movimientos->update($userId, $movimientoId, $datos['id_cuenta'], $datos['id_categoria'], $datos['tipo'], $datos['monto'], $datos['fecha'], $datos['descripcion']);
                $id = $movimientoId;
            }

            return ['codigo' => ReglasMovimiento::OK, 'id' => $id, 'cuenta' => $cuenta['nombre'] ?? null, 'saldo' => $saldoDisponible];
        });
    }

    /**
     * Elimina un movimiento del usuario. Bloquea su cuenta (como el alta y los aportes a metas): quitar un ingreso baja el
     * saldo, y no debe cruzarse con un aporte que acaba de comprobar que había saldo suficiente.
     *
     * @return bool false si no existe o no es del usuario
     */
    public function eliminar(int $userId, int $movimientoId): bool
    {
        return Transaction::run($this->pdo, function () use ($userId, $movimientoId): bool {
            $movimiento = $this->movimientos->findOwned($userId, $movimientoId);

            if ($movimiento === null) {
                return false;
            }

            $this->cuentas->lockForWrite($userId, $movimiento->idCuenta);

            return $this->movimientos->delete($userId, $movimientoId);
        });
    }
}
