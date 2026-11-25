<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Categoria;
use PDO;
use PDOException;

/**
 * Único sitio con el SQL de `categorias`: las predeterminadas (`id_usuario IS NULL AND id_espacio IS NULL`, RF-34),
 * las propias del usuario en su espacio personal (`id_espacio IS NULL`) y las propias de un espacio compartido (`id_espacio = :e`).
 */
final class CategoriaRepository
{
    private const COLUMNS = 'id_categoria, id_usuario, id_espacio, nombre, tipo, color';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Las predeterminadas y las del usuario o su espacio compartido: gastos primero, por nombre. @return list<Categoria> */
    public function listVisible(int $userId, ?int $espacioId = null): array
    {
        if ($espacioId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT ' . self::COLUMNS . " FROM categorias WHERE (id_usuario IS NULL AND id_espacio IS NULL) OR (id_usuario = :u AND id_espacio IS NULL) ORDER BY tipo = 'gasto' DESC, nombre, id_categoria"
            );
            $stmt->execute(['u' => $userId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT ' . self::COLUMNS . " FROM categorias WHERE (id_usuario IS NULL AND id_espacio IS NULL) OR id_espacio = :e ORDER BY tipo = 'gasto' DESC, nombre, id_categoria"
            );
            $stmt->execute(['e' => $espacioId]);
        }

        return array_map([Categoria::class, 'fromRow'], $stmt->fetchAll());
    }

    /** @return list<Categoria> */
    public function listDefaults(): array
    {
        $rows = $this->pdo->query('SELECT ' . self::COLUMNS . " FROM categorias WHERE id_usuario IS NULL AND id_espacio IS NULL ORDER BY tipo = 'gasto' DESC, nombre, id_categoria")->fetchAll();

        return array_map([Categoria::class, 'fromRow'], $rows);
    }

    /** Cualquier categoría por id, SIN comprobar de quién es: quien la usa debe decidir si puede verla o tocarla. */
    public function findById(int $id): ?Categoria
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM categorias WHERE id_categoria = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Categoria::fromRow($row);
    }

    /**
     * ¿Ya existe una categoría con ese nombre y tipo en lo que ve ese dueño/espacio?
     */
    public function nameTaken(?int $ownerId, string $nombre, string $tipo, ?int $exceptId = null, ?int $espacioId = null): bool
    {
        if ($espacioId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM categorias WHERE nombre = :n AND tipo = :t AND ((id_usuario IS NULL AND id_espacio IS NULL) OR id_espacio = :e) AND id_categoria <> :ex'
            );
            $stmt->execute(['n' => $nombre, 't' => $tipo, 'e' => $espacioId, 'ex' => $exceptId ?? 0]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM categorias WHERE nombre = :n AND tipo = :t AND id_espacio IS NULL AND (id_usuario IS NULL OR id_usuario <=> :u) AND id_categoria <> :ex'
            );
            $stmt->execute(['n' => $nombre, 't' => $tipo, 'u' => $ownerId, 'ex' => $exceptId ?? 0]);
        }

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param int|null $ownerId null = predeterminada */
    public function create(?int $ownerId, string $nombre, string $tipo, string $color, ?int $espacioId = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO categorias (id_usuario, id_espacio, nombre, tipo, color) VALUES (:u, :e, :n, :t, :c)');
        $stmt->execute(['u' => $ownerId, 'e' => $espacioId, 'n' => $nombre, 't' => $tipo, 'c' => $color]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(?int $ownerId, int $id, string $nombre, string $color, ?int $espacioId = null): bool
    {
        if ($espacioId !== null) {
            $stmt = $this->pdo->prepare('UPDATE categorias SET nombre = :n, color = :c WHERE id_categoria = :id AND id_espacio = :e');
            $stmt->execute(['n' => $nombre, 'c' => $color, 'id' => $id, 'e' => $espacioId]);
        } else {
            $stmt = $this->pdo->prepare('UPDATE categorias SET nombre = :n, color = :c WHERE id_categoria = :id AND id_espacio IS NULL AND id_usuario <=> :u');
            $stmt->execute(['n' => $nombre, 'c' => $color, 'id' => $id, 'u' => $ownerId]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * @return string 'ok', 'en_uso' o 'no_existe'
     */
    public function delete(?int $ownerId, int $id, ?int $espacioId = null): string
    {
        try {
            if ($espacioId !== null) {
                $stmt = $this->pdo->prepare('DELETE FROM categorias WHERE id_categoria = :id AND id_espacio = :e');
                $stmt->execute(['id' => $id, 'e' => $espacioId]);
            } else {
                $stmt = $this->pdo->prepare('DELETE FROM categorias WHERE id_categoria = :id AND id_espacio IS NULL AND id_usuario <=> :u');
                $stmt->execute(['id' => $id, 'u' => $ownerId]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'en_uso';
            }

            throw $e;
        }

        return $stmt->rowCount() > 0 ? 'ok' : 'no_existe';
    }
}
