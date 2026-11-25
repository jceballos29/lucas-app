<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Mes;
use PDO;

/**
 * Agregados de solo lectura para el panel y los reportes (RF-21, RF-22). TODA consulta filtra por el usuario o el espacio
 * compartido de la SESIÓN (RNF-04).
 */
final class ReporteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{ingresos: string, gastos: string} */
    public function totalesMes(int $userId, int $mes, int $anio, ?int $espacioId = null): array
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto END), 0) AS ingresos,
                    COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN monto END), 0) AS gastos
             FROM transacciones WHERE $scope AND fecha >= :desde AND fecha < :hasta"
        );
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);
        $row = $stmt->fetch();

        return ['ingresos' => (string) $row['ingresos'], 'gastos' => (string) $row['gastos']];
    }

    /**
     * @return list<array{nombre: string, color: string, total: string}>
     */
    public function gastosPorCategoria(int $userId, int $mes, int $anio, ?int $espacioId = null): array
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        [$scope, $params] = self::scope($userId, $espacioId, 't');
        $stmt = $this->pdo->prepare(
            "SELECT k.nombre, k.color, SUM(t.monto) AS total
             FROM transacciones t JOIN categorias k ON k.id_categoria = t.id_categoria
             WHERE $scope AND t.tipo = 'gasto' AND t.fecha >= :desde AND t.fecha < :hasta
             GROUP BY k.id_categoria, k.nombre, k.color
             ORDER BY total DESC, k.nombre"
        );
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);

        return array_map(static fn (array $r): array => ['nombre' => (string) $r['nombre'], 'color' => (string) $r['color'], 'total' => (string) $r['total']], $stmt->fetchAll());
    }

    /**
     * @return list<array{mes: int, anio: int, ingresos: string, gastos: string}>
     */
    public function evolucion(int $userId, int $mes, int $anio, int $meses, ?int $espacioId = null): array
    {
        $meses = max(1, min(24, $meses));
        $primero = ['mes' => $mes, 'anio' => $anio];
        for ($i = 1; $i < $meses; $i++) {
            $primero = Mes::anterior($primero['mes'], $primero['anio']);
        }

        [$desde] = Mes::limites($primero['mes'], $primero['anio']);
        [, $hasta] = Mes::limites($mes, $anio);
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "SELECT YEAR(fecha) AS y, MONTH(fecha) AS m,
                    COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto END), 0) AS ingresos,
                    COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN monto END), 0) AS gastos
             FROM transacciones WHERE $scope AND fecha >= :desde AND fecha < :hasta
             GROUP BY y, m"
        );
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);

        $porMes = [];
        foreach ($stmt->fetchAll() as $r) {
            $porMes[(int) $r['y'] . '-' . (int) $r['m']] = ['ingresos' => (string) $r['ingresos'], 'gastos' => (string) $r['gastos']];
        }

        $resultado = [];
        $actual = $primero;
        for ($i = 0; $i < $meses; $i++) {
            $datos = $porMes[$actual['anio'] . '-' . $actual['mes']] ?? ['ingresos' => '0.00', 'gastos' => '0.00'];
            $resultado[] = ['mes' => $actual['mes'], 'anio' => $actual['anio']] + $datos;
            $actual = Mes::siguiente($actual['mes'], $actual['anio']);
        }

        return $resultado;
    }

    /** @return array{0: string, 1: array<string, int>} */
    private static function scope(int $userId, ?int $espacioId, string $alias = ''): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        if ($espacioId === null) {
            return ["{$p}id_usuario = :u AND {$p}id_espacio IS NULL", ['u' => $userId]];
        }

        return ["{$p}id_espacio = :e", ['e' => $espacioId]];
    }
}
