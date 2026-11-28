<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * SQL de `aportes_meta`. Las operaciones de escritura se llaman SIEMPRE desde GestionMetas, dentro de una transacción en la
 * que la meta ya se comprobó como del usuario o del espacio compartido; la lectura filtra además por ese alcance.
 */
final class AporteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(int $metaId, int $cuentaId, string $monto, string $fecha): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO aportes_meta (id_meta, id_cuenta, monto, fecha) VALUES (:meta, :cuenta, :monto, :fecha)');
        $stmt->execute(['meta' => $metaId, 'cuenta' => $cuentaId, 'monto' => $monto, 'fecha' => $fecha]);

        return (int) $this->pdo->lastInsertId();
    }

    public function totalByMeta(int $metaId): string
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(monto), 0) FROM aportes_meta WHERE id_meta = :id');
        $stmt->execute(['id' => $metaId]);

        return (string) $stmt->fetchColumn();
    }

    public function deleteByMeta(int $metaId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM aportes_meta WHERE id_meta = :id');
        $stmt->execute(['id' => $metaId]);

        return $stmt->rowCount();
    }

    /**
     * @return list<array{id: int, fecha: string, monto: string, cuenta: string}>
     */
    public function listByMeta(int $userId, int $metaId, int $limit = 20, ?int $espacioId = null): array
    {
        $scope = $espacioId === null ? 'm.id_usuario = :u AND m.id_espacio IS NULL' : 'm.id_espacio = :e';
        $stmt = $this->pdo->prepare(
            "SELECT a.id_aporte, a.fecha, a.monto, c.nombre AS cuenta
             FROM aportes_meta a
             JOIN metas_ahorro m ON m.id_meta = a.id_meta
             JOIN cuentas c ON c.id_cuenta = a.id_cuenta
             WHERE $scope AND a.id_meta = :id
             ORDER BY a.fecha DESC, a.id_aporte DESC LIMIT :limit",
        );
        if ($espacioId === null) {
            $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue('e', $espacioId, PDO::PARAM_INT);
        }
        $stmt->bindValue('id', $metaId, PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id_aporte'],
            'fecha' => (string) $r['fecha'],
            'monto' => (string) $r['monto'],
            'cuenta' => (string) $r['cuenta'],
        ], $stmt->fetchAll());
    }
}
