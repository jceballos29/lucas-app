<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Transferencia;
use PDO;

/**
 * Único sitio con el SQL de `transferencias`. TODA consulta filtra por el id del usuario de la sesión (RNF-04).
 */
final class TransferenciaRepository
{
    private const SELECT = 'SELECT tr.id_transferencia, tr.id_usuario, tr.id_cuenta_origen, tr.id_cuenta_destino,
            tr.monto, tr.fecha, tr.descripcion,
            co.nombre AS cuenta_origen_nombre, cd.nombre AS cuenta_destino_nombre
        FROM transferencias tr
        JOIN cuentas co ON co.id_cuenta = tr.id_cuenta_origen
        JOIN cuentas cd ON cd.id_cuenta = tr.id_cuenta_destino';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Listado paginado de transferencias del usuario, de la más reciente a la más antigua.
     *
     * @return list<Transferencia>
     */
    public function listByUser(int $userId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE tr.id_usuario = :u ORDER BY tr.fecha DESC, tr.id_transferencia DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('u', $userId, PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([Transferencia::class, 'fromRow'], $stmt->fetchAll());
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transferencias WHERE id_usuario = :u');
        $stmt->execute(['u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function totalByUser(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(monto), 0) FROM transferencias WHERE id_usuario = :u');
        $stmt->execute(['u' => $userId]);

        return (string) $stmt->fetchColumn();
    }

    public function findOwned(int $userId, int $id): ?Transferencia
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE tr.id_usuario = :u AND tr.id_transferencia = :id');
        $stmt->execute(['u' => $userId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Transferencia::fromRow($row);
    }

    public function insert(int $userId, int $origenId, int $destinoId, string $monto, string $fecha, ?string $descripcion): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transferencias (id_usuario, id_cuenta_origen, id_cuenta_destino, monto, fecha, descripcion)
             VALUES (:u, :origen, :destino, :monto, :fecha, :desc)'
        );
        $stmt->execute([
            'u' => $userId,
            'origen' => $origenId,
            'destino' => $destinoId,
            'monto' => $monto,
            'fecha' => $fecha,
            'desc' => $descripcion,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $userId, int $id, int $origenId, int $destinoId, string $monto, string $fecha, ?string $descripcion): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE transferencias
             SET id_cuenta_origen = :origen, id_cuenta_destino = :destino, monto = :monto, fecha = :fecha, descripcion = :desc
             WHERE id_transferencia = :id AND id_usuario = :u'
        );
        $stmt->execute([
            'origen' => $origenId,
            'destino' => $destinoId,
            'monto' => $monto,
            'fecha' => $fecha,
            'desc' => $descripcion,
            'id' => $id,
            'u' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM transferencias WHERE id_transferencia = :id AND id_usuario = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
