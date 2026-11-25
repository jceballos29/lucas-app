<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Transferencia;
use PDO;

/**
 * Único sitio con el SQL de `transferencias`. TODA consulta filtra por el usuario o el espacio compartido de la sesión (RNF-04).
 */
final class TransferenciaRepository
{
    private const SELECT = 'SELECT tr.id_transferencia, tr.id_usuario, tr.id_cuenta_origen, tr.id_cuenta_destino,
            tr.monto, tr.fecha, tr.descripcion,
            co.nombre AS cuenta_origen_nombre, cd.nombre AS cuenta_destino_nombre, u.nombre AS autor_nombre
        FROM transferencias tr
        JOIN cuentas co ON co.id_cuenta = tr.id_cuenta_origen
        JOIN cuentas cd ON cd.id_cuenta = tr.id_cuenta_destino
        JOIN usuarios u ON u.id_usuario = tr.id_usuario';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<Transferencia>
     */
    public function listByUser(int $userId, int $limit, int $offset, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'tr');
        $stmt = $this->pdo->prepare(
            self::SELECT . " WHERE $scope ORDER BY tr.fecha DESC, tr.id_transferencia DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([Transferencia::class, 'fromRow'], $stmt->fetchAll());
    }

    public function countByUser(int $userId, ?int $espacioId = null): int
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM transferencias WHERE $scope");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function totalByUser(int $userId, ?int $espacioId = null): string
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM transferencias WHERE $scope");
        $stmt->execute($params);

        return (string) $stmt->fetchColumn();
    }

    public function findOwned(int $userId, int $id, ?int $espacioId = null): ?Transferencia
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'tr');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope AND tr.id_transferencia = :id");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Transferencia::fromRow($row);
    }

    public function insert(int $userId, int $origenId, int $destinoId, string $monto, string $fecha, ?string $descripcion, ?int $espacioId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transferencias (id_usuario, id_espacio, id_cuenta_origen, id_cuenta_destino, monto, fecha, descripcion)
             VALUES (:u, :e, :origen, :destino, :monto, :fecha, :desc)'
        );
        $stmt->execute([
            'u' => $userId,
            'e' => $espacioId,
            'origen' => $origenId,
            'destino' => $destinoId,
            'monto' => $monto,
            'fecha' => $fecha,
            'desc' => $descripcion,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $userId, int $id, int $origenId, int $destinoId, string $monto, string $fecha, ?string $descripcion, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "UPDATE transferencias
             SET id_cuenta_origen = :origen, id_cuenta_destino = :destino, monto = :monto, fecha = :fecha, descripcion = :desc
             WHERE id_transferencia = :id AND $scope"
        );
        $stmt->execute($params + [
            'origen' => $origenId,
            'destino' => $destinoId,
            'monto' => $monto,
            'fecha' => $fecha,
            'desc' => $descripcion,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("DELETE FROM transferencias WHERE id_transferencia = :id AND $scope");
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
