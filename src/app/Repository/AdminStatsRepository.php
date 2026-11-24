<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Métricas globales del dashboard de administración. Solo AGREGADOS: cuenta filas, nunca lee montos, descripciones ni
 * saldos de nadie (RNF-21). Lo único «individual» son los datos de cuenta de los últimos registros (nombre, correo, fecha).
 */
final class AdminStatsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{usuarios: int, activos: int, nuevos_mes: int, admins_activos: int, cuentas: int, movimientos: int} */
    public function totals(): array
    {
        $row = $this->pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM usuarios) AS usuarios,
                (SELECT COUNT(*) FROM usuarios WHERE activo = 1) AS activos,
                (SELECT COUNT(*) FROM usuarios WHERE creado_en >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS nuevos_mes,
                (SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1) AS admins_activos,
                (SELECT COUNT(*) FROM cuentas) AS cuentas,
                (SELECT COUNT(*) FROM transacciones) AS movimientos"
        )->fetch();

        return [
            'usuarios' => (int) $row['usuarios'],
            'activos' => (int) $row['activos'],
            'nuevos_mes' => (int) $row['nuevos_mes'],
            'admins_activos' => (int) $row['admins_activos'],
            'cuentas' => (int) $row['cuentas'],
            'movimientos' => (int) $row['movimientos'],
        ];
    }

    /**
     * Registros por mes de los últimos $months meses (el actual incluido), del más viejo al más nuevo y con ceros en
     * los meses sin registros. Los meses se cuentan en UTC, como se guardan las fechas.
     *
     * @return list<array{year: int, month: int, value: int}>
     */
    public function registrationsByMonth(int $months = 12): array
    {
        $months = max(1, min(60, $months));
        $back = $months - 1;
        $rows = $this->pdo->query(
            "SELECT DATE_FORMAT(creado_en, '%Y-%m') AS ym, COUNT(*) AS total
             FROM usuarios
             WHERE creado_en >= DATE_FORMAT(NOW() - INTERVAL $back MONTH, '%Y-%m-01')
             GROUP BY ym"
        )->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['ym']] = (int) $row['total'];
        }

        $first = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('first day of this month')->modify("-$back months");
        $result = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $first->modify("+$i months");
            $result[] = ['year' => (int) $month->format('Y'), 'month' => (int) $month->format('n'), 'value' => $counts[$month->format('Y-m')] ?? 0];
        }

        return $result;
    }

    /** @return list<array{id: int, nombre: string, correo: string, activo: bool, creado_en: string}> */
    public function latestUsers(int $limit = 4): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->pdo->query("SELECT id_usuario, nombre, correo, activo, creado_en FROM usuarios ORDER BY creado_en DESC, id_usuario DESC LIMIT $limit")->fetchAll();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id_usuario'],
            'nombre' => (string) $r['nombre'],
            'correo' => (string) $r['correo'],
            'activo' => (bool) $r['activo'],
            'creado_en' => (string) $r['creado_en'],
        ], $rows);
    }
}
