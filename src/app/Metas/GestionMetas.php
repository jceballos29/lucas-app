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
 * Operaciones transaccionales de una meta de ahorro, en el espacio personal o en un espacio compartido (`$espacioId`).
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
     * @param array{id_cuenta: int, monto: string, fecha: string} $datos
     * @return array{codigo: string, lograda: bool, cuenta: string|null, saldo: string|null}
     */
    public function aportar(int $userId, int $metaId, array $datos, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $metaId, $datos, $espacioId): array {
            $cuenta = $this->cuentas->lockForWrite($userId, $datos['id_cuenta'], $espacioId);
            $meta = $this->metas->lockOwned($userId, $metaId, $espacioId);
            $saldo = $cuenta === null ? '0.00' : (string) $this->cuentas->findOwned($userId, $datos['id_cuenta'], $espacioId)?->saldo;
            $codigo = ReglasAporte::evaluar($cuenta, $meta, $saldo, $datos['monto']);

            if ($codigo !== ReglasAporte::OK || $meta === null) {
                return ['codigo' => $codigo, 'lograda' => false, 'cuenta' => $cuenta['nombre'] ?? null, 'saldo' => $cuenta === null ? null : $saldo];
            }

            $this->aportes->insert($metaId, $datos['id_cuenta'], $datos['monto'], $datos['fecha']);
            $lograda = Dinero::aCentavos($this->aportes->totalByMeta($metaId)) >= Dinero::aCentavos($meta['objetivo']);

            if ($lograda) {
                $this->metas->setEstado($userId, $metaId, 'lograda', $espacioId);
            }

            return ['codigo' => ReglasAporte::OK, 'lograda' => $lograda, 'cuenta' => $cuenta['nombre'] ?? null, 'saldo' => $saldo];
        });
    }

    /**
     * @param array{nombre: string, monto_objetivo: string, fecha_limite: string|null} $datos
     * @return array{codigo: string, lograda: bool}
     */
    public function editar(int $userId, int $metaId, array $datos, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $metaId, $datos, $espacioId): array {
            $meta = $this->metas->lockOwned($userId, $metaId, $espacioId);

            if ($meta === null) {
                return ['codigo' => self::NO_EXISTE, 'lograda' => false];
            }

            if ($meta['estado'] !== 'en_curso') {
                return ['codigo' => self::CERRADA, 'lograda' => false];
            }

            $this->metas->update($userId, $metaId, $datos['nombre'], $datos['monto_objetivo'], $datos['fecha_limite'], $espacioId);
            $lograda = Dinero::aCentavos($this->aportes->totalByMeta($metaId)) >= Dinero::aCentavos($datos['monto_objetivo']);

            if ($lograda) {
                $this->metas->setEstado($userId, $metaId, 'lograda', $espacioId);
            }

            return ['codigo' => self::OK, 'lograda' => $lograda];
        });
    }

    /** @return array{codigo: string, devuelto: string} */
    public function cancelar(int $userId, int $metaId, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $metaId, $espacioId): array {
            $meta = $this->metas->lockOwned($userId, $metaId, $espacioId);

            if ($meta === null) {
                return ['codigo' => self::NO_EXISTE, 'devuelto' => '0.00'];
            }

            if ($meta['estado'] !== 'en_curso') {
                return ['codigo' => self::CERRADA, 'devuelto' => '0.00'];
            }

            $devuelto = $this->aportes->totalByMeta($metaId);
            $this->aportes->deleteByMeta($metaId);
            $this->metas->setEstado($userId, $metaId, 'cancelada', $espacioId);

            return ['codigo' => self::OK, 'devuelto' => $devuelto];
        });
    }

    /** @return string {@see OK}, {@see NO_EXISTE} o {@see TIENE_APORTES} */
    public function eliminar(int $userId, int $metaId, ?int $espacioId = null): string
    {
        return Transaction::run($this->pdo, function () use ($userId, $metaId, $espacioId): string {
            if ($this->metas->lockOwned($userId, $metaId, $espacioId) === null) {
                return self::NO_EXISTE;
            }

            if (Dinero::aCentavos($this->aportes->totalByMeta($metaId)) > 0) {
                return self::TIENE_APORTES;
            }

            $this->metas->delete($userId, $metaId, $espacioId);

            return self::OK;
        });
    }
}
