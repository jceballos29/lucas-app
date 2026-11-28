<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Recurrente;
use PDO;

/**
 * Único sitio con el SQL de `movimientos_recurrentes`. TODA consulta filtra por el usuario o el espacio compartido de la sesión (RNF-04).
 */
final class RecurrenteRepository
{
    private const SELECT = 'SELECT r.id_recurrente, r.id_usuario, r.id_cuenta, r.id_categoria, r.tipo, r.monto,
            r.frecuencia, r.proxima_fecha, r.descripcion, r.auto_aplicar, r.activo,
            c.nombre AS cuenta_nombre, k.nombre AS categoria_nombre
        FROM movimientos_recurrentes r
        JOIN cuentas c ON c.id_cuenta = r.id_cuenta
        JOIN categorias k ON k.id_categoria = r.id_categoria';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<Recurrente>
     */
    public function listByUser(int $userId, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'r');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope ORDER BY r.activo DESC, r.proxima_fecha ASC, r.id_recurrente ASC");
        $stmt->execute($params);

        return array_map([Recurrente::class, 'fromRow'], $stmt->fetchAll());
    }

    /**
     * @return list<Recurrente>
     */
    public function listDueByUser(int $userId, string $hoy, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'r');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope AND r.activo = 1 AND r.proxima_fecha <= :hoy ORDER BY r.proxima_fecha ASC, r.id_recurrente ASC");
        $stmt->execute($params + ['hoy' => $hoy]);

        return array_map([Recurrente::class, 'fromRow'], $stmt->fetchAll());
    }

    /**
     * @return list<int>
     */
    public function usersWithDueAuto(string $hoy): array
    {
        $stmt = $this->pdo->prepare('SELECT DISTINCT id_usuario FROM movimientos_recurrentes WHERE activo = 1 AND auto_aplicar = 1 AND proxima_fecha <= :hoy AND id_espacio IS NULL');
        $stmt->execute(['hoy' => $hoy]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array{id_espacio: int, id_usuario: int}>
     */
    public function espaciosWithDueAuto(string $hoy): array
    {
        $stmt = $this->pdo->prepare('SELECT DISTINCT id_espacio, MIN(id_usuario) AS id_usuario FROM movimientos_recurrentes WHERE activo = 1 AND auto_aplicar = 1 AND proxima_fecha <= :hoy AND id_espacio IS NOT NULL GROUP BY id_espacio');
        $stmt->execute(['hoy' => $hoy]);

        return array_map(static fn (array $r): array => [
            'id_espacio' => (int) $r['id_espacio'],
            'id_usuario' => (int) $r['id_usuario'],
        ], $stmt->fetchAll());
    }

    public function findOwned(int $userId, int $id, ?int $espacioId = null): ?Recurrente
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'r');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope AND r.id_recurrente = :id");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Recurrente::fromRow($row);
    }

    public function lockOwned(int $userId, int $id, ?int $espacioId = null): ?Recurrente
    {
        [$scope, $params] = self::scope($userId, $espacioId, 'r');
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE $scope AND r.id_recurrente = :id FOR UPDATE");
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Recurrente::fromRow($row);
    }

    /**
     * @param array{id_cuenta: int, id_categoria: int, tipo: string, monto: string, frecuencia: string, proxima_fecha: string, descripcion: string|null, auto_aplicar: bool} $d
     */
    public function insert(int $userId, array $d, ?int $espacioId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO movimientos_recurrentes (id_usuario, id_espacio, id_cuenta, id_categoria, tipo, monto, frecuencia, proxima_fecha, descripcion, auto_aplicar)
             VALUES (:u, :e, :cuenta, :cat, :tipo, :monto, :freq, :fecha, :desc, :auto)',
        );
        $stmt->execute([
            'u' => $userId,
            'e' => $espacioId,
            'cuenta' => $d['id_cuenta'],
            'cat' => $d['id_categoria'],
            'tipo' => $d['tipo'],
            'monto' => $d['monto'],
            'freq' => $d['frecuencia'],
            'fecha' => $d['proxima_fecha'],
            'desc' => $d['descripcion'],
            'auto' => $d['auto_aplicar'] ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{id_cuenta: int, id_categoria: int, tipo: string, monto: string, frecuencia: string, proxima_fecha: string, descripcion: string|null, auto_aplicar: bool} $d
     */
    public function update(int $userId, int $id, array $d, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "UPDATE movimientos_recurrentes
             SET id_cuenta = :cuenta, id_categoria = :cat, tipo = :tipo, monto = :monto, frecuencia = :freq,
                 proxima_fecha = :fecha, descripcion = :desc, auto_aplicar = :auto
             WHERE id_recurrente = :id AND $scope",
        );
        $stmt->execute($params + [
            'cuenta' => $d['id_cuenta'],
            'cat' => $d['id_categoria'],
            'tipo' => $d['tipo'],
            'monto' => $d['monto'],
            'freq' => $d['frecuencia'],
            'fecha' => $d['proxima_fecha'],
            'desc' => $d['descripcion'],
            'auto' => $d['auto_aplicar'] ? 1 : 0,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function setProximaFecha(int $userId, int $id, string $proximaFecha, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("UPDATE movimientos_recurrentes SET proxima_fecha = :f WHERE id_recurrente = :id AND $scope");
        $stmt->execute($params + ['f' => $proximaFecha, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function setActivo(int $userId, int $id, bool $activo, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("UPDATE movimientos_recurrentes SET activo = :a WHERE id_recurrente = :id AND $scope");
        $stmt->execute($params + ['a' => $activo ? 1 : 0, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("DELETE FROM movimientos_recurrentes WHERE id_recurrente = :id AND $scope");
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
