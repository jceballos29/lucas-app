<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Intentos de acceso recientes, para limitar la fuerza bruta (RNF-24). El tiempo lo pone MySQL (UTC). */
final class IntentoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $tipo, string $clave): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO intentos_acceso (tipo, clave) VALUES (:tipo, :clave)');
        $stmt->execute(['tipo' => $tipo, 'clave' => $clave]);
    }

    /** Intentos de esa clave en los últimos $seconds segundos. */
    public function countSince(string $tipo, string $clave, int $seconds): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM intentos_acceso WHERE tipo = :tipo AND clave = :clave AND creado_en >= (NOW() - INTERVAL :s SECOND)');
        $stmt->execute(['tipo' => $tipo, 'clave' => $clave, 's' => $seconds]);

        return (int) $stmt->fetchColumn();
    }

    public function clear(string $tipo, string $clave): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM intentos_acceso WHERE tipo = :tipo AND clave = :clave');
        $stmt->execute(['tipo' => $tipo, 'clave' => $clave]);
    }

    /** Borra lo anterior a $seconds segundos (mantenimiento; se llama de vez en cuando). */
    public function purgeOlderThan(int $seconds): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM intentos_acceso WHERE creado_en < (NOW() - INTERVAL :s SECOND)');
        $stmt->execute(['s' => $seconds]);
    }

    /** Borra los intentos de esa clave en TODOS los tipos (al eliminar una cuenta no debe quedar rastro del correo, ni siquiera su hash). */
    public function clearKey(string $clave): void
    {
        $this->pdo->prepare('DELETE FROM intentos_acceso WHERE clave = :clave')->execute(['clave' => $clave]);
    }
}
