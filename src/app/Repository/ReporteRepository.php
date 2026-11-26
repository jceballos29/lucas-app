<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\ConversorMoneda;
use App\Support\Dinero;
use App\Support\Mes;
use PDO;

/**
 * Agregados de solo lectura para el panel y los reportes (RF-21, RF-22, Fase 18). TODA consulta filtra por el usuario
 * o el espacio compartido de la SESIÓN (RNF-04). Cuando se pasa `$monedaBase` y `$tasas`, las transacciones en cuentas
 * de otras monedas se convierten al vuelo usando las tasas de referencia vigentes.
 */
final class ReporteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, int> $tasas
     * @return array{ingresos: string, gastos: string}
     */
    public function totalesMes(int $userId, int $mes, int $anio, ?int $espacioId = null, string $monedaBase = '', array $tasas = []): array
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        if ($monedaBase === '') {
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

        [$scope, $params] = self::scope($userId, $espacioId, 't');
        $stmt = $this->pdo->prepare(
            "SELECT c.moneda,
                    COALESCE(SUM(CASE WHEN t.tipo = 'ingreso' THEN t.monto END), 0) AS ingresos,
                    COALESCE(SUM(CASE WHEN t.tipo = 'gasto' THEN t.monto END), 0) AS gastos
             FROM transacciones t
             JOIN cuentas c ON c.id_cuenta = t.id_cuenta
             WHERE $scope AND t.fecha >= :desde AND t.fecha < :hasta
             GROUP BY c.moneda"
        );
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);

        $ingresos = [];
        $gastos = [];
        foreach ($stmt->fetchAll() as $r) {
            $monedaCuenta = is_string($r['moneda'] ?? null) && $r['moneda'] !== '' ? $r['moneda'] : $monedaBase;
            $ing = (string) $r['ingresos'];
            $gas = (string) $r['gastos'];
            $ingresos[] = ConversorMoneda::convertir($ing, $monedaCuenta, $monedaBase, $tasas) ?? $ing;
            $gastos[] = ConversorMoneda::convertir($gas, $monedaCuenta, $monedaBase, $tasas) ?? $gas;
        }

        return ['ingresos' => Dinero::sumar($ingresos), 'gastos' => Dinero::sumar($gastos)];
    }

    /**
     * @param array<string, int> $tasas
     * @return list<array{nombre: string, color: string, total: string}>
     */
    public function gastosPorCategoria(int $userId, int $mes, int $anio, ?int $espacioId = null, string $monedaBase = '', array $tasas = []): array
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        [$scope, $params] = self::scope($userId, $espacioId, 't');
        if ($monedaBase === '') {
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

        $stmt = $this->pdo->prepare(
            "SELECT k.id_categoria, k.nombre, k.color, c.moneda, SUM(t.monto) AS total
             FROM transacciones t
             JOIN categorias k ON k.id_categoria = t.id_categoria
             JOIN cuentas c ON c.id_cuenta = t.id_cuenta
             WHERE $scope AND t.tipo = 'gasto' AND t.fecha >= :desde AND t.fecha < :hasta
             GROUP BY k.id_categoria, k.nombre, k.color, c.moneda"
        );
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);

        $acum = [];
        foreach ($stmt->fetchAll() as $r) {
            $idCat = (int) $r['id_categoria'];
            $monedaCuenta = is_string($r['moneda'] ?? null) && $r['moneda'] !== '' ? $r['moneda'] : $monedaBase;
            $sub = (string) $r['total'];
            $conv = ConversorMoneda::convertir($sub, $monedaCuenta, $monedaBase, $tasas) ?? $sub;
            if (!isset($acum[$idCat])) {
                $acum[$idCat] = ['nombre' => (string) $r['nombre'], 'color' => (string) $r['color'], 'centavos' => 0];
            }
            $acum[$idCat]['centavos'] += Dinero::aCentavos($conv);
        }

        usort($acum, static fn (array $a, array $b): int => ($b['centavos'] <=> $a['centavos']) ?: strcmp($a['nombre'], $b['nombre']));

        return array_map(static fn (array $a): array => [
            'nombre' => $a['nombre'],
            'color' => $a['color'],
            'total' => Dinero::deCentavos($a['centavos']),
        ], $acum);
    }

    /**
     * Gastos del mes agrupados por etiqueta transversal (`#etiqueta`, Fase 14).
     *
     * @param array<string, int> $tasas
     * @return list<array{etiqueta: string, total: string, cantidad: int}>
     */
    public function gastosPorEtiqueta(int $userId, int $mes, int $anio, ?int $espacioId = null, string $monedaBase = '', array $tasas = []): array
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        [$scope, $params] = self::scope($userId, $espacioId, 't');
        if ($monedaBase === '') {
            $stmt = $this->pdo->prepare(
                "SELECT t.etiqueta, SUM(t.monto) AS total, COUNT(*) AS cantidad
                 FROM transacciones t
                 WHERE $scope AND t.tipo = 'gasto' AND t.etiqueta IS NOT NULL AND t.etiqueta <> ''
                   AND t.fecha >= :desde AND t.fecha < :hasta
                 GROUP BY t.etiqueta
                 ORDER BY total DESC, t.etiqueta"
            );
            $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);

            return array_map(static fn (array $r): array => [
                'etiqueta' => (string) $r['etiqueta'],
                'total' => (string) $r['total'],
                'cantidad' => (int) $r['cantidad'],
            ], $stmt->fetchAll());
        }

        $stmt = $this->pdo->prepare(
            "SELECT t.etiqueta, c.moneda, SUM(t.monto) AS total, COUNT(*) AS cantidad
             FROM transacciones t
             JOIN cuentas c ON c.id_cuenta = t.id_cuenta
             WHERE $scope AND t.tipo = 'gasto' AND t.etiqueta IS NOT NULL AND t.etiqueta <> ''
               AND t.fecha >= :desde AND t.fecha < :hasta
             GROUP BY t.etiqueta, c.moneda"
        );
        $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);

        $acum = [];
        foreach ($stmt->fetchAll() as $r) {
            $et = (string) $r['etiqueta'];
            $monedaCuenta = is_string($r['moneda'] ?? null) && $r['moneda'] !== '' ? $r['moneda'] : $monedaBase;
            $sub = (string) $r['total'];
            $conv = ConversorMoneda::convertir($sub, $monedaCuenta, $monedaBase, $tasas) ?? $sub;
            if (!isset($acum[$et])) {
                $acum[$et] = ['etiqueta' => $et, 'centavos' => 0, 'cantidad' => 0];
            }
            $acum[$et]['centavos'] += Dinero::aCentavos($conv);
            $acum[$et]['cantidad'] += (int) $r['cantidad'];
        }

        usort($acum, static fn (array $a, array $b): int => ($b['centavos'] <=> $a['centavos']) ?: strcmp($a['etiqueta'], $b['etiqueta']));

        return array_map(static fn (array $a): array => [
            'etiqueta' => $a['etiqueta'],
            'total' => Dinero::deCentavos($a['centavos']),
            'cantidad' => $a['cantidad'],
        ], $acum);
    }

    /**
     * @param array<string, int> $tasas
     * @return list<array{mes: int, anio: int, ingresos: string, gastos: string}>
     */
    public function evolucion(int $userId, int $mes, int $anio, int $meses, ?int $espacioId = null, string $monedaBase = '', array $tasas = []): array
    {
        $meses = max(1, min(24, $meses));
        $primero = ['mes' => $mes, 'anio' => $anio];
        for ($i = 1; $i < $meses; $i++) {
            $primero = Mes::anterior($primero['mes'], $primero['anio']);
        }

        [$desde] = Mes::limites($primero['mes'], $primero['anio']);
        [, $hasta] = Mes::limites($mes, $anio);

        $porMes = [];
        if ($monedaBase === '') {
            [$scope, $params] = self::scope($userId, $espacioId);
            $stmt = $this->pdo->prepare(
                "SELECT YEAR(fecha) AS y, MONTH(fecha) AS m,
                        COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto END), 0) AS ingresos,
                        COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN monto END), 0) AS gastos
                 FROM transacciones WHERE $scope AND fecha >= :desde AND fecha < :hasta
                 GROUP BY y, m"
            );
            $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);

            foreach ($stmt->fetchAll() as $r) {
                $porMes[(int) $r['y'] . '-' . (int) $r['m']] = ['ingresos' => (string) $r['ingresos'], 'gastos' => (string) $r['gastos']];
            }
        } else {
            [$scope, $params] = self::scope($userId, $espacioId, 't');
            $stmt = $this->pdo->prepare(
                "SELECT YEAR(t.fecha) AS y, MONTH(t.fecha) AS m, c.moneda,
                        COALESCE(SUM(CASE WHEN t.tipo = 'ingreso' THEN t.monto END), 0) AS ingresos,
                        COALESCE(SUM(CASE WHEN t.tipo = 'gasto' THEN t.monto END), 0) AS gastos
                 FROM transacciones t
                 JOIN cuentas c ON c.id_cuenta = t.id_cuenta
                 WHERE $scope AND t.fecha >= :desde AND t.fecha < :hasta
                 GROUP BY y, m, c.moneda"
            );
            $stmt->execute($params + ['desde' => $desde, 'hasta' => $hasta]);

            $centavosMes = [];
            foreach ($stmt->fetchAll() as $r) {
                $clave = (int) $r['y'] . '-' . (int) $r['m'];
                $monedaCuenta = is_string($r['moneda'] ?? null) && $r['moneda'] !== '' ? $r['moneda'] : $monedaBase;
                $ing = (string) $r['ingresos'];
                $gas = (string) $r['gastos'];
                $ingConv = ConversorMoneda::convertir($ing, $monedaCuenta, $monedaBase, $tasas) ?? $ing;
                $gasConv = ConversorMoneda::convertir($gas, $monedaCuenta, $monedaBase, $tasas) ?? $gas;
                if (!isset($centavosMes[$clave])) {
                    $centavosMes[$clave] = ['ing' => 0, 'gas' => 0];
                }
                $centavosMes[$clave]['ing'] += Dinero::aCentavos($ingConv);
                $centavosMes[$clave]['gas'] += Dinero::aCentavos($gasConv);
            }
            foreach ($centavosMes as $clave => $c) {
                $porMes[$clave] = ['ingresos' => Dinero::deCentavos($c['ing']), 'gastos' => Dinero::deCentavos($c['gas'])];
            }
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
