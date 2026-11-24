<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Meta;
use PDO;

/**
 * Único sitio con el SQL de `metas_ahorro`. TODA consulta lleva el id del usuario de la SESIÓN (RNF-04). Lo ahorrado es la
 * suma de los aportes y se calcula al leer.
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

    /** En curso primero, luego lograda y cancelada; dentro de cada una, la fecha límite más próxima primero. @return list<Meta> */
    public function listByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE m.id_usuario = :u ORDER BY FIELD(m.estado, 'en_curso', 'lograda', 'cancelada'), m.fecha_limite IS NULL, m.fecha_limite, m.id_meta");
        $stmt->execute(['u' => $userId]);

        return array_map([Meta::class, 'fromRow'], $stmt->fetchAll());
    }

    public function findOwned(int $userId, int $id): ?Meta
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE m.id_usuario = :u AND m.id_meta = :id');
        $stmt->execute(['u' => $userId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Meta::fromRow($row);
    }

    public function create(int $userId, string $nombre, string $objetivo, ?string $fechaLimite): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO metas_ahorro (id_usuario, nombre, monto_objetivo, fecha_limite) VALUES (:u, :n, :o, :f)');
        $stmt->execute(['u' => $userId, 'n' => $nombre, 'o' => $objetivo, 'f' => $fechaLimite]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Lee la meta del usuario BLOQUEANDO su fila hasta el final de la transacción (`FOR UPDATE`): dos aportes a la vez no
     * pueden decidir sobre un estado a medias. Solo dentro de una transacción.
     *
     * @return array{estado: string, objetivo: string}|null null si no existe o no es del usuario
     */
    public function lockOwned(int $userId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT estado, monto_objetivo FROM metas_ahorro WHERE id_meta = :id AND id_usuario = :u FOR UPDATE');
        $stmt->execute(['id' => $id, 'u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : ['estado' => (string) $row['estado'], 'objetivo' => (string) $row['monto_objetivo']];
    }

    public function update(int $userId, int $id, string $nombre, string $objetivo, ?string $fechaLimite): bool
    {
        $stmt = $this->pdo->prepare('UPDATE metas_ahorro SET nombre = :n, monto_objetivo = :o, fecha_limite = :f WHERE id_meta = :id AND id_usuario = :u');
        $stmt->execute(['n' => $nombre, 'o' => $objetivo, 'f' => $fechaLimite, 'id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function setEstado(int $userId, int $id, string $estado): bool
    {
        $stmt = $this->pdo->prepare('UPDATE metas_ahorro SET estado = :e WHERE id_meta = :id AND id_usuario = :u');
        $stmt->execute(['e' => $estado, 'id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM metas_ahorro WHERE id_meta = :id AND id_usuario = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
