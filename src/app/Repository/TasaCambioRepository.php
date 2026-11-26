<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Único sitio con el SQL de `tasas_cambio` (Fase 18). Toda consulta filtra por el usuario o espacio compartido activo.
 */
final class TasaCambioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{id: int, moneda_origen: string, moneda_destino: string, tasa_micros: int, actualizado_en: string}>
     */
    public function listAll(int $userId, ?int $espacioId = null): array
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "SELECT id_tasa, moneda_origen, moneda_destino, tasa_micros, actualizado_en
             FROM tasas_cambio
             WHERE $scope
             ORDER BY moneda_origen, moneda_destino, id_tasa"
        );
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id_tasa'],
            'moneda_origen' => (string) $r['moneda_origen'],
            'moneda_destino' => (string) $r['moneda_destino'],
            'tasa_micros' => (int) $r['tasa_micros'],
            'actualizado_en' => (string) $r['actualizado_en'],
        ], $stmt->fetchAll());
    }

    /**
     * Mapa compacto `"ORIG:DEST" => tasa_micros` listo para {@see \App\Support\ConversorMoneda}.
     *
     * @return array<string, int>
     */
    public function listMap(int $userId, ?int $espacioId = null): array
    {
        $mapa = [];
        foreach ($this->listAll($userId, $espacioId) as $r) {
            $mapa[$r['moneda_origen'] . ':' . $r['moneda_destino']] = $r['tasa_micros'];
        }

        return $mapa;
    }

    /** Crea o actualiza la tasa de referencia para el par `(origen -> destino)` en el ámbito actual. */
    public function upsert(int $userId, string $origen, string $destino, int $tasaMicros, ?int $espacioId = null): int
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare(
            "SELECT id_tasa FROM tasas_cambio
             WHERE $scope AND moneda_origen = :orig AND moneda_destino = :dest
             LIMIT 1"
        );
        $stmt->execute($params + ['orig' => $origen, 'dest' => $destino]);
        $existente = $stmt->fetchColumn();

        if ($existente !== false) {
            $id = (int) $existente;
            $upd = $this->pdo->prepare(
                "UPDATE tasas_cambio SET tasa_micros = :tasa WHERE id_tasa = :id AND $scope"
            );
            $upd->execute($params + ['tasa' => $tasaMicros, 'id' => $id]);

            return $id;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO tasas_cambio (id_usuario, id_espacio, moneda_origen, moneda_destino, tasa_micros)
             VALUES (:u, :e, :orig, :dest, :tasa)'
        );
        $ins->execute([
            'u' => $userId,
            'e' => $espacioId,
            'orig' => $origen,
            'dest' => $destino,
            'tasa' => $tasaMicros,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $userId, int $id, ?int $espacioId = null): bool
    {
        [$scope, $params] = self::scope($userId, $espacioId);
        $stmt = $this->pdo->prepare("DELETE FROM tasas_cambio WHERE id_tasa = :id AND $scope");
        $stmt->execute($params + ['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{0: string, 1: array<string, int>} */
    private static function scope(int $userId, ?int $espacioId): array
    {
        if ($espacioId === null) {
            return ['id_usuario = :u AND id_espacio IS NULL', ['u' => $userId]];
        }

        return ['id_espacio = :e', ['e' => $espacioId]];
    }
}
