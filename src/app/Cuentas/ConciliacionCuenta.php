<?php

declare(strict_types=1);

namespace App\Cuentas;

use App\Database\Transaction;
use App\Movimientos\ReglasMovimiento;
use App\Repository\CategoriaRepository;
use App\Repository\CuentaRepository;
use App\Repository\MovimientoRepository;
use App\Support\Dinero;
use PDO;

/**
 * Conciliación rápida del saldo de una cuenta (Fase 13).
 *
 * El usuario indica cuál es su saldo real hoy en el banco o en su billetera. Lucas bloquea la cuenta (`FOR UPDATE`),
 * calcula la diferencia exacta (`delta = saldo_real - saldo_actual_lucas`) y, si hay diferencia, registra un movimiento
 * de ajuste (`ingreso` si `delta > 0`, `gasto` si `delta < 0`) para que el saldo calculado coincida al centavo.
 */
final class ConciliacionCuenta
{
    public const OK = 'ok';
    public const SIN_DIFERENCIA = 'sin_diferencia';
    public const SALDO_NEGATIVO_NO_PERMITIDO = 'saldo_negativo_no_permitido';

    public function __construct(
        private readonly PDO $pdo,
        private readonly CuentaRepository $cuentas,
        private readonly MovimientoRepository $movimientos,
        private readonly CategoriaRepository $categorias,
    ) {
    }

    /**
     * @return array{codigo: string, tipo_ajuste: string|null, diferencia: string, cuenta: string|null}
     */
    public function conciliar(
        int $userId,
        int $cuentaId,
        string $saldoReal,
        int $categoriaIngresoId,
        int $categoriaGastoId,
        string $fecha,
        ?string $descripcion = null,
        ?int $espacioId = null,
    ): array {
        return Transaction::run($this->pdo, function () use (
            $userId,
            $cuentaId,
            $saldoReal,
            $categoriaIngresoId,
            $categoriaGastoId,
            $fecha,
            $descripcion,
            $espacioId,
        ): array {
            $lock = $this->cuentas->lockForWrite($userId, $cuentaId, $espacioId);
            $cuenta = $this->cuentas->findOwned($userId, $cuentaId, $espacioId);

            if ($lock === null || $cuenta === null) {
                return ['codigo' => ReglasMovimiento::CUENTA_AJENA, 'tipo_ajuste' => null, 'diferencia' => '0.00', 'cuenta' => null];
            }

            if (!$lock['activa']) {
                return ['codigo' => ReglasMovimiento::CUENTA_INACTIVA, 'tipo_ajuste' => null, 'diferencia' => '0.00', 'cuenta' => $cuenta->nombre];
            }

            $realCentavos = Dinero::aCentavos($saldoReal);
            if ($cuenta->tipo !== 'tarjeta_credito' && $realCentavos < 0) {
                return ['codigo' => self::SALDO_NEGATIVO_NO_PERMITIDO, 'tipo_ajuste' => null, 'diferencia' => '0.00', 'cuenta' => $cuenta->nombre];
            }

            $actualCentavos = Dinero::aCentavos($cuenta->saldo);
            $deltaCentavos = $realCentavos - $actualCentavos;

            if ($deltaCentavos === 0) {
                return ['codigo' => self::SIN_DIFERENCIA, 'tipo_ajuste' => null, 'diferencia' => '0.00', 'cuenta' => $cuenta->nombre];
            }

            $tipo = $deltaCentavos > 0 ? 'ingreso' : 'gasto';
            $montoAjuste = Dinero::deCentavos(abs($deltaCentavos));
            $catId = $tipo === 'ingreso' ? $categoriaIngresoId : $categoriaGastoId;
            $categoria = $this->categorias->findById($catId);

            $codigo = ReglasMovimiento::evaluar($lock, $categoria, $userId, $tipo, $cuenta->saldo, $montoAjuste, $espacioId);
            if ($codigo !== ReglasMovimiento::OK) {
                return ['codigo' => $codigo, 'tipo_ajuste' => $tipo, 'diferencia' => $montoAjuste, 'cuenta' => $cuenta->nombre];
            }

            $nota = ($descripcion !== null && trim($descripcion) !== '')
                ? trim($descripcion)
                : 'Ajuste por conciliación de saldo';

            $this->movimientos->insert($userId, $cuentaId, $catId, $tipo, $montoAjuste, $fecha, $nota, $espacioId);

            return [
                'codigo' => self::OK,
                'tipo_ajuste' => $tipo,
                'diferencia' => $montoAjuste,
                'cuenta' => $cuenta->nombre,
            ];
        });
    }
}
