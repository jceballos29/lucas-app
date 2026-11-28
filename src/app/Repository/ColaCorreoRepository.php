<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Cola de correo (M4). La petición web solo inserta; el proceso `scripts/enviar-cola.php` reclama, envía y marca. El tiempo lo pone MySQL.
 * Si dos procesos corren a la vez no se reparten el mismo correo (`FOR UPDATE SKIP LOCKED`), y un correo reclamado que no se confirma
 * (el proceso murió a medias) vuelve a estar disponible pasado `ESPERA_RECLAMO` segundos.
 */
final class ColaCorreoRepository
{
    public const MAX_INTENTOS = 5;
    public const ESPERA_RECLAMO = 300;
    /** Espera antes del intento siguiente, según los intentos ya hechos (1.º falló → 1 min, 2.º → 5, 3.º → 15, 4.º → 60). */
    private const ESPERAS = [1 => 60, 2 => 300, 3 => 900, 4 => 3600];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function encolar(string $destinatario, string $asunto, string $cuerpo): int
    {
        $this->pdo->prepare('INSERT INTO cola_correo (destinatario, asunto, cuerpo) VALUES (:d, :a, :c)')
            ->execute(['d' => $destinatario, 'a' => mb_substr($asunto, 0, 255), 'c' => $cuerpo]);

        return (int) $this->pdo->lastInsertId();
    }

    public static function esperaTrasFallo(int $intentosHechos): int
    {
        return self::ESPERAS[$intentosHechos] ?? 3600;
    }

    /**
     * Reclama hasta $max correos pendientes y ya vencidos: cuenta el intento y los oculta ESPERA_RECLAMO segundos.
     *
     * @return list<array{id_correo: int, destinatario: string, asunto: string, cuerpo: string, intentos: int}>
     */
    public function reclamar(int $max): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT id_correo FROM cola_correo WHERE estado = 'pendiente' AND proximo_intento <= NOW() ORDER BY id_correo LIMIT :n FOR UPDATE SKIP LOCKED");
            $stmt->bindValue('n', $max, PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            if ($ids === []) {
                $this->pdo->commit();

                return [];
            }

            $in = implode(',', $ids);
            $this->pdo->exec('UPDATE cola_correo SET intentos = intentos + 1, proximo_intento = NOW() + INTERVAL ' . self::ESPERA_RECLAMO . " SECOND WHERE id_correo IN ($in)");
            $filas = $this->pdo->query("SELECT id_correo, destinatario, asunto, cuerpo, intentos FROM cola_correo WHERE id_correo IN ($in) ORDER BY id_correo")->fetchAll();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return array_map(static fn (array $f): array => [
            'id_correo' => (int) $f['id_correo'], 'destinatario' => (string) $f['destinatario'], 'asunto' => (string) $f['asunto'],
            'cuerpo' => (string) $f['cuerpo'], 'intentos' => (int) $f['intentos'],
        ], $filas);
    }

    /** Salió: se conserva la fila (historial) pero se borra el cuerpo, que puede llevar enlaces con token. */
    public function marcarEnviado(int $id): void
    {
        $this->pdo->prepare("UPDATE cola_correo SET estado = 'enviado', cuerpo = '', ultimo_error = NULL, procesado_en = NOW() WHERE id_correo = :i")->execute(['i' => $id]);
    }

    /** Falló: se reintenta con espera creciente; agotados los intentos pasa a «fallido» y también se borra el cuerpo. */
    public function marcarFallo(int $id, int $intentosHechos, string $error): void
    {
        $error = mb_substr($error, 0, 255);

        if ($intentosHechos >= self::MAX_INTENTOS) {
            $this->pdo->prepare("UPDATE cola_correo SET estado = 'fallido', cuerpo = '', ultimo_error = :e, procesado_en = NOW() WHERE id_correo = :i")->execute(['e' => $error, 'i' => $id]);

            return;
        }

        $this->pdo->prepare('UPDATE cola_correo SET proximo_intento = NOW() + INTERVAL :s SECOND, ultimo_error = :e WHERE id_correo = :i')
            ->execute(['s' => self::esperaTrasFallo($intentosHechos), 'e' => $error, 'i' => $id]);
    }

    /** Un correo que no se puede ni armar (dirección inválida...) no tiene sentido reintentarlo. */
    public function descartar(int $id, string $error): void
    {
        $this->pdo->prepare("UPDATE cola_correo SET estado = 'fallido', cuerpo = '', ultimo_error = :e, procesado_en = NOW() WHERE id_correo = :i")->execute(['e' => mb_substr($error, 0, 255), 'i' => $id]);
    }

    /** Borra el historial viejo: enviados de más de 7 días y fallidos de más de 30. */
    public function purgar(): int
    {
        return (int) $this->pdo->exec("DELETE FROM cola_correo WHERE (estado = 'enviado' AND procesado_en < NOW() - INTERVAL 7 DAY) OR (estado = 'fallido' AND procesado_en < NOW() - INTERVAL 30 DAY)");
    }

    /** @return array{pendiente: int, enviado: int, fallido: int, mas_antiguo_segundos: int|null} */
    public function resumen(): array
    {
        $cuenta = ['pendiente' => 0, 'enviado' => 0, 'fallido' => 0];
        foreach ($this->pdo->query('SELECT estado, COUNT(*) AS n FROM cola_correo GROUP BY estado')->fetchAll() as $f) {
            $cuenta[(string) $f['estado']] = (int) $f['n'];
        }
        $antiguo = $this->pdo->query("SELECT TIMESTAMPDIFF(SECOND, MIN(creado_en), NOW()) FROM cola_correo WHERE estado = 'pendiente'")->fetchColumn();

        return $cuenta + ['mas_antiguo_segundos' => $antiguo === null || $antiguo === false ? null : (int) $antiguo];
    }
}
