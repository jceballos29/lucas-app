<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Meta;
use PDO;

/**
 * Único sitio con el SQL de `metas_ahorro`. TODA consulta filtra por el usuario o el espacio compartido de la SESIÓN (RNF-04).
 */
final class MetaRepository
{
    private const SELECT = 'SELECT m.id_meta, m.id_usuario, m.nombre, m.monto_objetivo, m.fecha_limite, m.estado,
            COALESCE((SELECT SUM(a.monto) FROM aportes_meta a WHERE a.id_meta = m.id_meta), 0) AS ahorrado,
            (SELECT COUNT(*) FROM aportes_meta a WHERE a.id_meta = m.id_meta) AS num_aportes
        FROM metas_ahorro m';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Meta> */
    public function listByUser(int $userId, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'm');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope ORDER BY FIELD(m.estado, 'en_curso', 'lograda', 'cancelada'), m.fecha_limite IS NULL, m.fecha_limite, m.id_meta");
        $stmt->execute($params);

        return array_map([Meta::class, 'fromRow'], $stmt->fetchAll());
    }

    public function findOwned(int $userId, int $id, ?int $espacioId = null): ?Meta
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'm');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope AND m.id_meta = :id");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Meta::fromRow($row);
    }

    public function create(int $userId, string $nombre, string $objetivo, ?string $fechaLimite, ?int $espacioId = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO metas_ahorro (id_usuario, id_espacio, nombre, monto_objetivo, fecha_limite) VALUES (:u, :e, :n, :o, :f)');
        $stmt->execute(['u' => $userId, 'e' => $espacioId, 'n' => $nombre, 'o' => $objetivo, 'f' => $fechaLimite]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{estado: string, objetivo: string}|null
     */
    public function lockOwned(int $userId, int $id, ?int $espacioId = null): ?array
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("SELECT estado, monto_objetivo FROM metas_ahorro WHERE id_meta = :id AND $scope FOR UPDATE");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : ['estado' => (string) $row['estado'], 'objetivo' => (string) $row['monto_objetivo']];
    }

    public function update(int $userId, int $id, string $nombre, string $objetivo, ?string $fechaLimite, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("UPDATE metas_ahorro SET nombre = :n, monto_objetivo = :o, fecha_limite = :f WHERE id_meta = :id AND $scope");
        $stmt->execute($params + ['n' => $nombre, 'o' => $objetivo, 'f' => $fechaLimite, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function setEstado(int $userId, int $id, string $estado, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("UPDATE metas_ahorro SET estado = :e_est WHERE id_meta = :id AND $scope");
        $stmt->execute($params + ['e_est' => $estado, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("DELETE FROM metas_ahorro WHERE id_meta = :id AND $scope");
        $stmt->execute($params + ['id' => $id]);

        return $stmt->rowCount() > 0;
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
