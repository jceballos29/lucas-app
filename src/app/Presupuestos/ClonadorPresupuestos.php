<?php

declare(strict_types=1);

namespace App\Presupuestos;

use App\Database\Transaction;
use App\Repository\CategoriaRepository;
use App\Repository\PresupuestoRepository;
use App\Support\Dinero;
use App\Support\Mes;
use PDO;

/**
 * Operaciones por lotes de presupuestos (Fase 12):
 *  - clonarDelMesAnterior: copia en una sola transacción todos los presupuestos del mes anterior que no existan aún en el mes destino;
 *  - guardarLote: crea o actualiza varios presupuestos de un mes desde el asistente 50/30/20.
 */
final class ClonadorPresupuestos
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PresupuestoRepository $presupuestos,
        private readonly CategoriaRepository $categorias,
    ) {
    }

    /**
     * @return array{copiados: int, omitidos: int}
     */
    public function clonarDelMesAnterior(int $userId, int $mesDestino, int $anioDestino, ?int $espacioId = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $mesDestino, $anioDestino, $espacioId): array {
            $anterior = Mes::anterior($mesDestino, $anioDestino);
            $origen = $this->presupuestos->listByMonth($userId, $anterior['mes'], $anterior['anio'], $espacioId);
            $copiados = 0;
            $omitidos = 0;

            foreach ($origen as $p) {
                $creado = $this->presupuestos->create($userId, $p->idCategoria, $mesDestino, $anioDestino, $p->montoLimite, $espacioId);
                if ($creado !== null) {
                    $copiados++;
                } else {
                    $omitidos++;
                }
            }

            return ['copiados' => $copiados, 'omitidos' => $omitidos];
        });
    }

    /**
     * Guarda en lote un mapa `[id_categoria => monto_limite]` para un mes y año dados. Ignora montos en cero.
     *
     * @param array<int, string> $montosPorCategoria Montos ya normalizados («400000.00»).
     */
    public function guardarLote(int $userId, int $mes, int $anio, array $montosPorCategoria, ?int $espacioId = null): int
    {
        return Transaction::run($this->pdo, function () use ($userId, $mes, $anio, $montosPorCategoria, $espacioId): int {
            $guardados = 0;

            foreach ($montosPorCategoria as $catId => $limite) {
                if (Dinero::aCentavos($limite) <= 0) {
                    continue;
                }

                $categoria = $this->categorias->findById((int) $catId);
                if (ReglasPresupuesto::evaluar($categoria, $userId, $espacioId) !== ReglasPresupuesto::OK) {
                    continue;
                }

                $existente = $this->presupuestos->findForCategory($userId, (int) $catId, $mes, $anio, $espacioId);
                if ($existente !== null) {
                    $this->presupuestos->updateLimit($userId, $existente->id, $limite, $espacioId);
                } else {
                    $this->presupuestos->create($userId, (int) $catId, $mes, $anio, $limite, $espacioId);
                }
                $guardados++;
            }

            return $guardados;
        });
    }
}
