<?php

declare(strict_types=1);

namespace App\Metas;

use App\Database\Transaction;
use App\Repository\AporteRepository;
use App\Repository\CuentaRepository;
use App\Repository\MetaRepository;
use App\Support\Dinero;
use PDO;

/**
 * Las operaciones de una meta que tocan más de una tabla, cada una en UNA transacción (RNF-16):
 *  - aportar: bloquea la cuenta y la meta, comprueba las reglas con lo leído ahí dentro, inserta el aporte y, si se alcanza el
 *    objetivo, pasa la meta a «lograda» en la misma transacción;
 *  - editar: cambia nombre, objetivo y fecha, y vuelve a evaluar si ya se alcanzó el objetivo;
 *  - cancelar: devuelve el dinero (borra los aportes) y marca la meta como cancelada;
 *  - eliminar: solo una meta sin aportes.
 * El saldo de las cuentas no se guarda, se calcula, así que aportar y cancelar lo actualizan solos.
 */
final class GestionMetas
{
    public const NO_EXISTE = 'no_existe';
    public const CERRADA = 'meta_cerrada';
    public const TIENE_APORTES = 'tiene_aportes';
    public const OK = 'ok';

    public function __construct(
        private readonly PDO $pdo,
        private readonly MetaRepository $metas,
        private readonly AporteRepository $aportes,
        private readonly CuentaRepository $cuentas,
    ) {
    }

    /**
     * @param array{id_cuenta: int, monto: string, fecha: string} $datos ya validados
     * @return array{codigo: string, lograda: bool, cuenta: string|null, saldo: string|null}
     *         `codigo` es uno de {@see ReglasAporte}; `lograda` indica si ESTE aporte completó la meta.
     */
    public function aportar(int $userId, int $metaId, array $datos): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $metaId, $datos): array {
            // Primero las lecturas con bloqueo (cuenta y luego meta): la lectura del saldo de abajo ya ve todo lo
            // confirmado por quien tuviera la cuenta antes que nosotros.
            $cuenta = $this->cuentas->lockForWrite($userId, $datos['id_cuenta']);
            $meta = $this->metas->lockOwned($userId, $metaId);
            $saldo = $cuenta === null ? '0.00' : (string) $this->cuentas->findOwned($userId, $datos['id_cuenta'])?->saldo;
            $codigo = ReglasAporte::evaluar($cuenta, $meta, $saldo, $datos['monto']);

            if ($codigo !== ReglasAporte::OK || $meta === null) {
                return ['codigo' => $codigo, 'lograda' => false, 'cuenta' => $cuenta['nombre'] ?? null, 'saldo' => $cuenta === null ? null : $saldo];
            }

            $this->aportes->insert($metaId, $datos['id_cuenta'], $datos['monto'], $datos['fecha']);
            $lograda = Dinero::aCentavos($this->aportes->totalByMeta($metaId)) >= Dinero::aCentavos($meta['objetivo']);

            if ($lograda) {
                $this->metas->setEstado($userId, $metaId, 'lograda');
            }

            return ['codigo' => ReglasAporte::OK, 'lograda' => $lograda, 'cuenta' => $cuenta['nombre'] ?? null, 'saldo' => $saldo];
        });
    }

    /**
     * @param array{nombre: string, monto_objetivo: string, fecha_limite: string|null} $datos ya validados
     * @return array{codigo: string, lograda: bool}
     */
    public function editar(int $userId, int $metaId, array $datos): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $metaId, $datos): array {
            $meta = $this->metas->lockOwned($userId, $metaId);

            if ($meta === null) {
                return ['codigo' => self::NO_EXISTE, 'lograda' => false];
            }

            if ($meta['estado'] !== 'en_curso') {
                return ['codigo' => self::CERRADA, 'lograda' => false];
            }

            $this->metas->update($userId, $metaId, $datos['nombre'], $datos['monto_objetivo'], $datos['fecha_limite']);
            // Si se bajó el objetivo por debajo de lo ya ahorrado, la meta queda lograda.
            $lograda = Dinero::aCentavos($this->aportes->totalByMeta($metaId)) >= Dinero::aCentavos($datos['monto_objetivo']);

            if ($lograda) {
                $this->metas->setEstado($userId, $metaId, 'lograda');
            }

            return ['codigo' => self::OK, 'lograda' => $lograda];
        });
    }

    /** @return array{codigo: string, devuelto: string} `devuelto`: lo que volvió a las cuentas. */
    public function cancelar(int $userId, int $metaId): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $metaId): array {
            $meta = $this->metas->lockOwned($userId, $metaId);

            if ($meta === null) {
                return ['codigo' => self::NO_EXISTE, 'devuelto' => '0.00'];
            }

            if ($meta['estado'] !== 'en_curso') {
                return ['codigo' => self::CERRADA, 'devuelto' => '0.00'];
            }

            $devuelto = $this->aportes->totalByMeta($metaId);
            $this->aportes->deleteByMeta($metaId);
            $this->metas->setEstado($userId, $metaId, 'cancelada');

            return ['codigo' => self::OK, 'devuelto' => $devuelto];
        });
    }

    /** @return string {@see OK}, {@see NO_EXISTE} o {@see TIENE_APORTES} */
    public function eliminar(int $userId, int $metaId): string
    {
        return Transaction::run($this->pdo, function () use ($userId, $metaId): string {
            if ($this->metas->lockOwned($userId, $metaId) === null) {
                return self::NO_EXISTE;
            }

            if (Dinero::aCentavos($this->aportes->totalByMeta($metaId)) > 0) {
                return self::TIENE_APORTES;
            }

            $this->metas->delete($userId, $metaId);

            return self::OK;
        });
    }
}
