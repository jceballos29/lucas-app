<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Database\Transaction;
use App\Repository\CategoriaRepository;
use App\Repository\CuentaRepository;
use App\Repository\MovimientoRepository;
use PDO;

/**
 * Crear y editar movimientos respetando D8. Todo ocurre en UNA transacción: se bloquea la cuenta (`FOR UPDATE`), se
 * comprueban las reglas con lo leído ahí dentro y solo entonces se escribe. Así una cuenta que se desactiva a la vez no
 * recibe movimientos «a escondidas»; borrar también bloquea la cuenta. El saldo no se guarda, se calcula (CuentaRepository), de modo que alta, edición y
 * borrado lo actualizan solos (RF-14).
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
     * @return array{codigo: string, id: int|null, cuenta: string|null} El resultado ({@see ReglasMovimiento}, o 'no_existe' si
     *         el movimiento que se edita no es del usuario), el id guardado y el nombre de la cuenta (para el mensaje).
     */
    public function guardar(int $userId, ?int $movimientoId, array $datos): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $movimientoId, $datos): array {
            if ($movimientoId !== null && $this->movimientos->findOwned($userId, $movimientoId) === null) {
                return ['codigo' => 'no_existe', 'id' => null, 'cuenta' => null];
            }

            $cuenta = $this->cuentas->lockForWrite($userId, $datos['id_cuenta']);
            $categoria = $this->categorias->findById($datos['id_categoria']);
            $codigo = ReglasMovimiento::evaluar($cuenta, $categoria, $userId, $datos['tipo']);

            if ($codigo !== ReglasMovimiento::OK) {
                return ['codigo' => $codigo, 'id' => null, 'cuenta' => $cuenta['nombre'] ?? null];
            }

            if ($movimientoId === null) {
                $id = $this->movimientos->insert($userId, $datos['id_cuenta'], $datos['id_categoria'], $datos['tipo'], $datos['monto'], $datos['fecha'], $datos['descripcion']);
            } else {
                $this->movimientos->update($userId, $movimientoId, $datos['id_cuenta'], $datos['id_categoria'], $datos['tipo'], $datos['monto'], $datos['fecha'], $datos['descripcion']);
                $id = $movimientoId;
            }

            return ['codigo' => ReglasMovimiento::OK, 'id' => $id, 'cuenta' => $cuenta['nombre'] ?? null];
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
