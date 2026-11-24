<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Presupuesto;
use App\Support\Mes;
use PDO;
use PDOException;

/**
 * Único sitio con el SQL de `presupuestos`. TODA consulta lleva el id del usuario de la SESIÓN (RNF-04). Lo gastado se
 * calcula al leer (suma de los gastos de la categoría en el mes), no se guarda: así nunca queda desactualizado.
 */
final class PresupuestoRepository
{
    private const SELECT = "SELECT p.id_presupuesto, p.id_usuario, p.id_categoria, p.mes, p.anio, p.monto_limite,
            k.nombre AS categoria_nombre, k.color AS categoria_color,
            COALESCE((SELECT SUM(t.monto) FROM transacciones t
                      WHERE t.id_usuario = p.id_usuario AND t.id_categoria = p.id_categoria AND t.tipo = 'gasto'
                        AND t.fecha >= :desde AND t.fecha < :hasta), 0) AS gastado
        FROM presupuestos p
        JOIN categorias k ON k.id_categoria = p.id_categoria";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Los presupuestos de un mes, por nombre de categoría. @return list<Presupuesto> */
    public function listByMonth(int $userId, int $mes, int $anio): array
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE p.id_usuario = :u AND p.mes = :mes AND p.anio = :anio ORDER BY k.nombre, p.id_presupuesto');
        $stmt->execute(['desde' => $desde, 'hasta' => $hasta, 'u' => $userId, 'mes' => $mes, 'anio' => $anio]);

        return array_map([Presupuesto::class, 'fromRow'], $stmt->fetchAll());
    }

    public function findOwned(int $userId, int $id): ?Presupuesto
    {
        // El mes del presupuesto fija la ventana de fechas de lo gastado: se lee primero y se calcula después.
        $stmt = $this->pdo->prepare('SELECT mes, anio FROM presupuestos WHERE id_presupuesto = :id AND id_usuario = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);
        $mes = $stmt->fetch();

        if ($mes === false) {
            return null;
        }

        [$desde, $hasta] = Mes::limites((int) $mes['mes'], (int) $mes['anio']);
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE p.id_usuario = :u AND p.id_presupuesto = :id');
        $stmt->execute(['desde' => $desde, 'hasta' => $hasta, 'u' => $userId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Presupuesto::fromRow($row);
    }

    /** El presupuesto de una categoría en un mes (o null si no hay), para avisar tras guardar un gasto. */
    public function findForCategory(int $userId, int $categoriaId, int $mes, int $anio): ?Presupuesto
    {
        [$desde, $hasta] = Mes::limites($mes, $anio);
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE p.id_usuario = :u AND p.id_categoria = :cat AND p.mes = :mes AND p.anio = :anio');
        $stmt->execute(['desde' => $desde, 'hasta' => $hasta, 'u' => $userId, 'cat' => $categoriaId, 'mes' => $mes, 'anio' => $anio]);
        $row = $stmt->fetch();

        return $row === false ? null : Presupuesto::fromRow($row);
    }

    /**
     * Los presupuestos del mes que piden aviso (desde el 80 %, RF-17), los más gastados primero. Los usarán el panel
     * (Fase 6) y el listado.
     *
     * @return list<Presupuesto>
     */
    public function alertas(int $userId, int $mes, int $anio): array
    {
        $alertas = array_values(array_filter($this->listByMonth($userId, $mes, $anio), static fn (Presupuesto $p): bool => $p->requiereAviso()));
        usort($alertas, static fn (Presupuesto $a, Presupuesto $b): int => $b->progreso()['percent'] <=> $a->progreso()['percent']);

        return $alertas;
    }

    /** @return int|null El id nuevo, o null si ya existe uno para esa categoría y mes (clave UNIQUE). */
    public function create(int $userId, int $categoriaId, int $mes, int $anio, string $limite): ?int
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO presupuestos (id_usuario, id_categoria, mes, anio, monto_limite) VALUES (:u, :cat, :mes, :anio, :limite)');
            $stmt->execute(['u' => $userId, 'cat' => $categoriaId, 'mes' => $mes, 'anio' => $anio, 'limite' => $limite]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return null;
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /** Solo cambia el límite: la categoría y el mes definen QUÉ presupuesto es. @return bool false si no existe o no es del usuario */
    public function updateLimit(int $userId, int $id, string $limite): bool
    {
        $stmt = $this->pdo->prepare('UPDATE presupuestos SET monto_limite = :limite WHERE id_presupuesto = :id AND id_usuario = :u');
        $stmt->execute(['limite' => $limite, 'id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** @return bool false si no existe o no es del usuario */
    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM presupuestos WHERE id_presupuesto = :id AND id_usuario = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
