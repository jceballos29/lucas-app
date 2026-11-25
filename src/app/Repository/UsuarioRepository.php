<?php

declare(strict_types=1);

namespace App\Repository;

use App\Auth\CredentialSource;
use App\Domain\Usuario;
use PDO;
use PDOException;

/** Único sitio con el SQL de `usuarios`. */
final class UsuarioRepository implements CredentialSource
{
    private const COLUMNS = 'id_usuario, nombre, correo, contrasena_hash, moneda, rol, activo';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $correo): ?Usuario
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM usuarios WHERE correo = :correo');
        $stmt->execute(['correo' => $correo]);
        $row = $stmt->fetch();

        return $row === false ? null : Usuario::fromRow($row);
    }

    public function findById(int $id): ?Usuario
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM usuarios WHERE id_usuario = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Usuario::fromRow($row);
    }

    /**
     * Crea un usuario. El rol por defecto es «usuario»: el registro público nunca pasa otro (RF-25); solo los
     * scripts de consola (create-admin.php) piden «admin».
     *
     * @return int|null El id nuevo, o null si el correo ya existe (la clave UNIQUE decide, también ante dos
     *                  registros simultáneos con el mismo correo).
     */
    public function create(string $nombre, string $correo, string $hash, string $rol = Usuario::ROL_USUARIO): ?int
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO usuarios (nombre, correo, contrasena_hash, rol) VALUES (:nombre, :correo, :hash, :rol)');
            $stmt->execute(['nombre' => $nombre, 'correo' => $correo, 'hash' => $hash, 'rol' => $rol]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return null;
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET contrasena_hash = :hash WHERE id_usuario = :id');
        $stmt->execute(['hash' => $hash, 'id' => $id]);
    }

    public function updateProfile(int $id, string $nombre, string $moneda): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET nombre = :n, moneda = :m WHERE id_usuario = :id');
        $stmt->execute(['n' => $nombre, 'm' => $moneda, 'id' => $id]);
    }

    /**
     * Borra TODO lo del usuario y luego al usuario (RNF-20), en un ORDEN explícito: `transacciones` y `aportes_meta` referencian
     * `cuentas` sin cascada, así que no se puede confiar en el orden de los `ON DELETE CASCADE`. Solo dentro de una transacción.
     */
    public function deleteWithAllData(int $id): void
    {
        foreach ([
            'DELETE FROM aportes_meta WHERE id_meta IN (SELECT id_meta FROM metas_ahorro WHERE id_usuario = :id)',
            'DELETE FROM transferencias WHERE id_usuario = :id',
            'DELETE FROM transacciones WHERE id_usuario = :id',
            'DELETE FROM presupuestos WHERE id_usuario = :id',
            'DELETE FROM metas_ahorro WHERE id_usuario = :id',
            'DELETE FROM cuentas WHERE id_usuario = :id',
            'DELETE FROM categorias WHERE id_usuario = :id',
            'DELETE FROM restablecimientos WHERE id_usuario = :id',
            'DELETE FROM usuarios WHERE id_usuario = :id',
        ] as $sql) {
            $this->pdo->prepare($sql)->execute(['id' => $id]);
        }
    }

    public function countActiveAdmins(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchColumn();
    }

    // ---- Administración de usuarios (RF-30 a RF-33) ---------------------------------------------------------

    /**
     * Busca usuarios por nombre o correo (texto parcial; vacío = todos), los más recientes primero. Solo datos de
     * cuenta: nunca el hash.
     *
     * @return list<array{id: int, nombre: string, correo: string, rol: string, activo: bool, creado_en: string}>
     */
    public function search(string $q, int $limit, int $offset): array
    {
        [$where, $params] = self::searchClause($q);
        $stmt = $this->pdo->prepare(
            'SELECT id_usuario, nombre, correo, rol, activo, creado_en FROM usuarios' . $where
            . ' ORDER BY creado_en DESC, id_usuario DESC LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id_usuario'],
            'nombre' => (string) $r['nombre'],
            'correo' => (string) $r['correo'],
            'rol' => (string) $r['rol'],
            'activo' => (bool) $r['activo'],
            'creado_en' => (string) $r['creado_en'],
        ], $stmt->fetchAll());
    }

    public function countSearch(string $q): int
    {
        [$where, $params] = self::searchClause($q);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM usuarios' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * El `%` y el `_` que escriba quien busca son texto, no comodines: se escapan con `!`.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function searchClause(string $q): array
    {
        if ($q === '') {
            return ['', []];
        }

        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';

        return [" WHERE (nombre LIKE :q1 ESCAPE '!' OR correo LIKE :q2 ESCAPE '!')", ['q1' => $like, 'q2' => $like]];
    }

    /**
     * Ejecuta $work dentro de una transacción: confirma si termina y deshace si lanza una excepción.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        return \App\Database\Transaction::run($this->pdo, $work);
    }

    /**
     * Ids de los administradores activos, BLOQUEADOS hasta el final de la transacción (`FOR UPDATE`): dos cambios
     * simultáneos no pueden dejar al sistema sin administradores (RF-33). Siempre en el mismo orden (por id) para
     * que dos transacciones no se bloqueen entre sí. Solo dentro de {@see transaction()}.
     *
     * @return list<int>
     */
    public function lockActiveAdminIds(): array
    {
        $ids = $this->pdo->query("SELECT id_usuario FROM usuarios WHERE rol = 'admin' AND activo = 1 ORDER BY id_usuario FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $ids);
    }

    /** El usuario, con su fila bloqueada hasta el final de la transacción. Solo dentro de {@see transaction()}. */
    public function lockById(int $id): ?Usuario
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM usuarios WHERE id_usuario = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Usuario::fromRow($row);
    }

    public function setActivo(int $id, bool $activo): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET activo = :activo WHERE id_usuario = :id');
        $stmt->execute(['activo' => $activo ? 1 : 0, 'id' => $id]);
    }

    public function setRol(int $id, string $rol): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET rol = :rol WHERE id_usuario = :id');
        $stmt->execute(['rol' => $rol, 'id' => $id]);
    }
}
