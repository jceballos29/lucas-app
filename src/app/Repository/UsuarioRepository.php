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

    public function countActiveAdmins(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchColumn();
    }
}
