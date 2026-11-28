<?php

declare(strict_types=1);

namespace App\Repository;

use App\Tarjetas\CicloTarjeta;
use PDO;

/**
 * Persistencia y agregados de `cuotas_tarjeta` (Fase 15).
 */
final class CuotaTarjetaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Reemplaza todas las cuotas de una transacción de tarjeta de crédito.
     *
     * @param list<array{numero: int, fecha_cobro: string, monto_capital: string, monto_interes: string, monto_total: string}> $cuotas
     */
    public function replaceForTransaccion(int $transaccionId, int $cuentaId, array $cuotas): void
    {
        $this->pdo->prepare('DELETE FROM cuotas_tarjeta WHERE id_transaccion = :t')->execute(['t' => $transaccionId]);

        if ($cuotas === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cuotas_tarjeta (id_transaccion, id_cuenta, numero, fecha_cobro, monto_capital, monto_interes, monto_total)
             VALUES (:t, :c, :n, :f, :cap, :int, :tot)',
        );

        foreach ($cuotas as $c) {
            $stmt->execute([
                't' => $transaccionId,
                'c' => $cuentaId,
                'n' => $c['numero'],
                'f' => $c['fecha_cobro'],
                'cap' => $c['monto_capital'],
                'int' => $c['monto_interes'],
                'tot' => $c['monto_total'],
            ]);
        }
    }

    /**
     * Resumen del próximo corte y de las cuotas futuras diferidas para una tarjeta de crédito.
     *
     * @return array{
     *   cuotaProximoCorte: string,
     *   capitalProximoCorte: string,
     *   interesProximoCorte: string,
     *   deudaDiferidaFutura: string,
     *   numCuotasEnCurso: int,
     *   proximoCorte: array{fecha: string, dias: int}|null,
     *   proximoPago: array{fecha: string, dias: int}|null
     * }
     */
    public function resumenTarjeta(int $cuentaId, ?int $diaCorte, ?int $diaPagoLimite, string $hoy): array
    {
        [$desde, $hasta] = CicloTarjeta::ventanaCicloActual($diaCorte, $hoy);

        $stmtCiclo = $this->pdo->prepare(
            'SELECT COALESCE(SUM(monto_total), 0) AS total,
                    COALESCE(SUM(monto_capital), 0) AS capital,
                    COALESCE(SUM(monto_interes), 0) AS interes,
                    COUNT(*) AS cantidad
             FROM cuotas_tarjeta
             WHERE id_cuenta = :c AND fecha_cobro >= :desde AND fecha_cobro <= :hasta',
        );
        $stmtCiclo->execute(['c' => $cuentaId, 'desde' => $desde, 'hasta' => $hasta]);
        $rowCiclo = $stmtCiclo->fetch() ?: ['total' => '0.00', 'capital' => '0.00', 'interes' => '0.00', 'cantidad' => 0];

        $stmtFuturo = $this->pdo->prepare(
            'SELECT COALESCE(SUM(monto_total), 0) AS futuro,
                    COALESCE(SUM(monto_capital), 0) AS capital_futuro,
                    COALESCE(SUM(monto_interes), 0) AS interes_futuro
             FROM cuotas_tarjeta
             WHERE id_cuenta = :c AND fecha_cobro > :hasta',
        );
        $stmtFuturo->execute(['c' => $cuentaId, 'hasta' => $hasta]);
        $rowFuturo = $stmtFuturo->fetch() ?: ['futuro' => '0.00', 'capital_futuro' => '0.00', 'interes_futuro' => '0.00'];

        return [
            'cuotaProximoCorte' => (string) $rowCiclo['total'],
            'capitalProximoCorte' => (string) $rowCiclo['capital'],
            'interesProximoCorte' => (string) $rowCiclo['interes'],
            'deudaDiferidaFutura' => (string) $rowFuturo['futuro'],
            'capitalDiferidoFuturo' => (string) $rowFuturo['capital_futuro'],
            'interesDiferidoFuturo' => (string) $rowFuturo['interes_futuro'],
            'numCuotasEnCurso' => (int) $rowCiclo['cantidad'],
            'proximoCorte' => CicloTarjeta::proximaFecha($diaCorte, $hoy),
            'proximoPago' => CicloTarjeta::proximaFecha($diaPagoLimite, $hoy),
        ];
    }
}
