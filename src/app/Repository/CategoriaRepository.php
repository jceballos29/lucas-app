<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Categoria;
use PDO;
use PDOException;

/**
 * Único sitio con el SQL de `categorias`: las predeterminadas (`id_usuario IS NULL`, de las que se encarga el administrador,
 * RF-34) y las propias de cada usuario (RF-09). Una categoría ajena simplemente «no existe» para quien la pide.
 */
final class CategoriaRepository
{
    private const COLUMNS = 'id_categoria, id_usuario, nombre, tipo, color';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Las predeterminadas y las del usuario: gastos primero, por nombre. @return list<Categoria> */
    public function listVisible(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM categorias WHERE id_usuario IS NULL OR id_usuario = :u ORDER BY tipo = 'gasto' DESC, nombre, id_categoria");
        $stmt->execute(['u' => $userId]);

        return array_map([Categoria::class, 'fromRow'], $stmt->fetchAll());
    }

    /** @return list<Categoria> */
    public function listDefaults(): array
    {
        $rows = $this->pdo->query('SELECT ' . self::COLUMNS . " FROM categorias WHERE id_usuario IS NULL ORDER BY tipo = 'gasto' DESC, nombre, id_categoria")->fetchAll();

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
     * ¿Ya existe una categoría con ese nombre y tipo en lo que ve ese dueño (sin distinguir mayúsculas ni tildes)?
     * Con `$ownerId` = null (el administrador) solo se miran las predeterminadas; con un usuario, las suyas y las predeterminadas.
     */
    public function nameTaken(?int $ownerId, string $nombre, string $tipo, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM categorias WHERE nombre = :n AND tipo = :t AND (id_usuario IS NULL OR id_usuario <=> :u) AND id_categoria <> :ex'
        );
        $stmt->execute(['n' => $nombre, 't' => $tipo, 'u' => $ownerId, 'ex' => $exceptId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param int|null $ownerId null = predeterminada */
    public function create(?int $ownerId, string $nombre, string $tipo, string $color): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO categorias (id_usuario, nombre, tipo, color) VALUES (:u, :n, :t, :c)');
        $stmt->execute(['u' => $ownerId, 'n' => $nombre, 't' => $tipo, 'c' => $color]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Cambia nombre y color. El TIPO no se cambia nunca: los movimientos ya guardados dependen de él (D8).
     *
     * @return bool false si no existe o no es de ese dueño
     */
    public function update(?int $ownerId, int $id, string $nombre, string $color): bool
    {
        $stmt = $this->pdo->prepare('UPDATE categorias SET nombre = :n, color = :c WHERE id_categoria = :id AND id_usuario <=> :u');
        $stmt->execute(['n' => $nombre, 'c' => $color, 'id' => $id, 'u' => $ownerId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Borra una categoría de ese dueño (D4). Si tiene movimientos o presupuestos la clave foránea lo impide y NO se
     * borra: es la defensa real (también ante dos peticiones a la vez), no una comprobación previa.
     *
     * @return string 'ok', 'en_uso' o 'no_existe'
     */
    public function delete(?int $ownerId, int $id): string
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM categorias WHERE id_categoria = :id AND id_usuario <=> :u');
            $stmt->execute(['id' => $id, 'u' => $ownerId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'en_uso';
            }

            throw $e;
        }

        return $stmt->rowCount() > 0 ? 'ok' : 'no_existe';
    }
}
