<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Esquema;
use App\Domain\Espacio;
use PDO;

/**
 * Único sitio con el SQL de `espacios`, `espacio_miembros` y `espacio_invitaciones` (Fase 10).
 */
final class EspacioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Espacios compartidos a los que pertenece un usuario.
     *
     * @return list<Espacio>
     */
    public function listByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id_espacio, e.nombre, e.id_creador, e.moneda, em.rol AS rol_usuario,
                    (SELECT COUNT(*) FROM espacio_miembros m WHERE m.id_espacio = e.id_espacio) AS num_miembros
             FROM espacios e
             JOIN espacio_miembros em ON em.id_espacio = e.id_espacio
             WHERE em.id_usuario = :u
             ORDER BY e.nombre ASC, e.id_espacio ASC',
        );
        $stmt->execute(['u' => $userId]);

        return array_map([Espacio::class, 'fromRow'], $stmt->fetchAll());
    }

    /**
     * Busca un espacio verificando que `$userId` sea miembro de él.
     */
    public function findForMember(int $userId, int $espacioId): ?Espacio
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id_espacio, e.nombre, e.id_creador, e.moneda, em.rol AS rol_usuario,
                    (SELECT COUNT(*) FROM espacio_miembros m WHERE m.id_espacio = e.id_espacio) AS num_miembros
             FROM espacios e
             JOIN espacio_miembros em ON em.id_espacio = e.id_espacio
             WHERE e.id_espacio = :id AND em.id_usuario = :u',
        );
        $stmt->execute(['id' => $espacioId, 'u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : Espacio::fromRow($row);
    }

    /**
     * Bloquea el espacio (`FOR UPDATE`) y verifica que `$userId` sea miembro. Solo dentro de una transacción.
     */
    public function lockForMember(int $userId, int $espacioId): ?Espacio
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id_espacio, e.nombre, e.id_creador, e.moneda, em.rol AS rol_usuario
             FROM espacios e
             JOIN espacio_miembros em ON em.id_espacio = e.id_espacio
             WHERE e.id_espacio = :id AND em.id_usuario = :u
             FOR UPDATE',
        );
        $stmt->execute(['id' => $espacioId, 'u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : Espacio::fromRow($row);
    }

    public function create(int $creadorId, string $nombre, string $moneda): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO espacios (nombre, id_creador, moneda) VALUES (:n, :u, :m)');
        $stmt->execute(['n' => $nombre, 'u' => $creadorId, 'm' => $moneda]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $espacioId, string $nombre, string $moneda): bool
    {
        $stmt = $this->pdo->prepare('UPDATE espacios SET nombre = :n, moneda = :m WHERE id_espacio = :id');
        $stmt->execute(['n' => $nombre, 'm' => $moneda, 'id' => $espacioId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Elimina un espacio compartido y todos sus datos financieros asociados en el orden requerido por las claves foráneas.
     * Solo dentro de una transacción.
     *
     * @return list<string> Rutas relativas de los comprobantes adjuntos del espacio: los archivos viven en disco y hay que borrarlos
     *                      DESPUÉS de confirmar la transacción (M3 de docs/AUDITORIA.md; igual que UsuarioRepository::deleteWithAllData).
     */
    public function deleteWithAllData(int $espacioId): array
    {
        $stmtAdj = $this->pdo->prepare('SELECT adjunto_ruta FROM transacciones WHERE id_espacio = :e AND adjunto_ruta IS NOT NULL');
        $stmtAdj->execute(['e' => $espacioId]);
        $adjuntosABorrar = array_values(array_filter(
            $stmtAdj->fetchAll(PDO::FETCH_COLUMN),
            static fn (mixed $ruta): bool => is_string($ruta) && $ruta !== '',
        ));

        Esquema::borrarEspacio($this->pdo, $espacioId);

        return $adjuntosABorrar;
    }

    public function addMember(int $espacioId, int $userId, string $rol): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO espacio_miembros (id_espacio, id_usuario, rol) VALUES (:e, :u, :r) ON DUPLICATE KEY UPDATE rol = VALUES(rol)');
        $stmt->execute(['e' => $espacioId, 'u' => $userId, 'r' => $rol]);
    }

    public function updateMemberRole(int $espacioId, int $userId, string $rol): bool
    {
        $stmt = $this->pdo->prepare('UPDATE espacio_miembros SET rol = :r WHERE id_espacio = :e AND id_usuario = :u');
        $stmt->execute(['r' => $rol, 'e' => $espacioId, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function removeMember(int $espacioId, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM espacio_miembros WHERE id_espacio = :e AND id_usuario = :u');
        $stmt->execute(['e' => $espacioId, 'u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function findMemberRole(int $espacioId, int $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT rol FROM espacio_miembros WHERE id_espacio = :e AND id_usuario = :u');
        $stmt->execute(['e' => $espacioId, 'u' => $userId]);
        $rol = $stmt->fetchColumn();

        return $rol === false ? null : (string) $rol;
    }

    public function isMemberByEmail(int $espacioId, string $correo): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM espacio_miembros em JOIN usuarios u ON u.id_usuario = em.id_usuario
             WHERE em.id_espacio = :e AND u.correo = :c',
        );
        $stmt->execute(['e' => $espacioId, 'c' => $correo]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Ids de los miembros con rol `propietario` en el espacio, bloqueados con `FOR UPDATE`.
     *
     * @return list<int>
     */
    public function lockOwnerIds(int $espacioId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_usuario FROM espacio_miembros WHERE id_espacio = :e AND rol = 'propietario' ORDER BY id_usuario FOR UPDATE",
        );
        $stmt->execute(['e' => $espacioId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array{id_usuario: int, nombre: string, correo: string, rol: string, unido_en: string}>
     */
    public function listMembers(int $espacioId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id_usuario, u.nombre, u.correo, em.rol, em.unido_en
             FROM espacio_miembros em
             JOIN usuarios u ON u.id_usuario = em.id_usuario
             WHERE em.id_espacio = :e
             ORDER BY FIELD(em.rol, 'propietario', 'editor', 'lector'), u.nombre ASC",
        );
        $stmt->execute(['e' => $espacioId]);

        return array_map(static fn (array $r): array => [
            'id_usuario' => (int) $r['id_usuario'],
            'nombre' => (string) $r['nombre'],
            'correo' => (string) $r['correo'],
            'rol' => (string) $r['rol'],
            'unido_en' => (string) $r['unido_en'],
        ], $stmt->fetchAll());
    }

    // ---- Invitaciones ----------------------------------------------------------------------------------------------

    /** Invitaciones vigentes (sin aceptar ni caducar) del espacio. */
    public function contarInvitacionesPendientes(int $espacioId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM espacio_invitaciones WHERE id_espacio = :e AND expira_en > CURRENT_TIMESTAMP');
        $stmt->execute(['e' => $espacioId]);

        return (int) $stmt->fetchColumn();
    }

    /** ¿Hay ya una invitación vigente de este espacio para ese correo? (al reinvitar se reemplaza, no suma una pendiente más). */
    public function tieneInvitacionPendiente(int $espacioId, string $correo): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM espacio_invitaciones WHERE id_espacio = :e AND correo = :c AND expira_en > CURRENT_TIMESTAMP');
        $stmt->execute(['e' => $espacioId, 'c' => $correo]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Bloquea la fila del usuario (`FOR UPDATE`): dos invitaciones simultáneas del mismo usuario se atienden de una en una. Solo dentro
     * de una transacción.
     *
     * @return bool ¿Tiene el correo verificado? (A1)
     */
    public function bloquearUsuario(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT correo_verificado_en IS NOT NULL FROM usuarios WHERE id_usuario = :u FOR UPDATE');
        $stmt->execute(['u' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    /** ¿Tiene el usuario el correo verificado? (A1) */
    public function correoVerificado(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT correo_verificado_en IS NOT NULL FROM usuarios WHERE id_usuario = :u');
        $stmt->execute(['u' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    public function createInvitation(int $espacioId, string $correo, string $rol, string $tokenHash, int $ttlSeconds = 172800): int
    {
        $this->pdo->prepare('DELETE FROM espacio_invitaciones WHERE id_espacio = :e AND correo = :c')
            ->execute(['e' => $espacioId, 'c' => $correo]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO espacio_invitaciones (id_espacio, correo, rol, token_hash, expira_en)
             VALUES (:e, :c, :r, :h, TIMESTAMPADD(SECOND, :ttl, CURRENT_TIMESTAMP))',
        );
        $stmt->bindValue('e', $espacioId, PDO::PARAM_INT);
        $stmt->bindValue('c', $correo);
        $stmt->bindValue('r', $rol);
        $stmt->bindValue('h', $tokenHash);
        $stmt->bindValue('ttl', $ttlSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Invitaciones activas (no caducadas) de un espacio compartido.
     *
     * @return list<array{id_invitacion: int, correo: string, rol: string, expira_en: string}>
     */
    public function listInvitationsByEspacio(int $espacioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_invitacion, correo, rol, expira_en
             FROM espacio_invitaciones
             WHERE id_espacio = :e AND expira_en > CURRENT_TIMESTAMP
             ORDER BY creado_en DESC, id_invitacion DESC',
        );
        $stmt->execute(['e' => $espacioId]);

        return array_map(static fn (array $r): array => [
            'id_invitacion' => (int) $r['id_invitacion'],
            'correo' => (string) $r['correo'],
            'rol' => (string) $r['rol'],
            'expira_en' => (string) $r['expira_en'],
        ], $stmt->fetchAll());
    }

    /**
     * Invitaciones activas dirigidas al correo del usuario actual.
     *
     * @return list<array{id_invitacion: int, id_espacio: int, espacio_nombre: string, moneda: string, rol: string, expira_en: string}>
     */
    public function listInvitationsForEmail(string $correo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.id_invitacion, i.id_espacio, e.nombre AS espacio_nombre, e.moneda, i.rol, i.expira_en
             FROM espacio_invitaciones i
             JOIN espacios e ON e.id_espacio = i.id_espacio
             WHERE i.correo = :c AND i.expira_en > CURRENT_TIMESTAMP
             ORDER BY i.creado_en DESC, i.id_invitacion DESC',
        );
        $stmt->execute(['c' => strtolower(trim($correo))]);

        return array_map(static fn (array $r): array => [
            'id_invitacion' => (int) $r['id_invitacion'],
            'id_espacio' => (int) $r['id_espacio'],
            'espacio_nombre' => (string) $r['espacio_nombre'],
            'moneda' => (string) $r['moneda'],
            'rol' => (string) $r['rol'],
            'expira_en' => (string) $r['expira_en'],
        ], $stmt->fetchAll());
    }

    /**
     * Busca y bloquea (`FOR UPDATE`) una invitación activa para ese correo por `id_invitacion`.
     *
     * @return array{id_invitacion: int, id_espacio: int, espacio_nombre: string, rol: string}|null
     */
    public function lockInvitationForEmail(int $invitacionId, string $correo): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.id_invitacion, i.id_espacio, e.nombre AS espacio_nombre, i.rol
             FROM espacio_invitaciones i
             JOIN espacios e ON e.id_espacio = i.id_espacio
             WHERE i.id_invitacion = :id AND i.correo = :c AND i.expira_en > CURRENT_TIMESTAMP
             FOR UPDATE',
        );
        $stmt->execute(['id' => $invitacionId, 'c' => strtolower(trim($correo))]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id_invitacion' => (int) $row['id_invitacion'],
            'id_espacio' => (int) $row['id_espacio'],
            'espacio_nombre' => (string) $row['espacio_nombre'],
            'rol' => (string) $row['rol'],
        ];
    }

    /**
     * Busca y bloquea (`FOR UPDATE`) una invitación activa por `token_hash` y correo.
     *
     * @return array{id_invitacion: int, id_espacio: int, espacio_nombre: string, rol: string}|null
     */
    public function lockInvitationByToken(string $tokenHash, string $correo): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.id_invitacion, i.id_espacio, e.nombre AS espacio_nombre, i.rol
             FROM espacio_invitaciones i
             JOIN espacios e ON e.id_espacio = i.id_espacio
             WHERE i.token_hash = :h AND i.correo = :c AND i.expira_en > CURRENT_TIMESTAMP
             FOR UPDATE',
        );
        $stmt->execute(['h' => $tokenHash, 'c' => strtolower(trim($correo))]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id_invitacion' => (int) $row['id_invitacion'],
            'id_espacio' => (int) $row['id_espacio'],
            'espacio_nombre' => (string) $row['espacio_nombre'],
            'rol' => (string) $row['rol'],
        ];
    }

    public function deleteInvitation(int $espacioId, int $invitacionId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM espacio_invitaciones WHERE id_invitacion = :id AND id_espacio = :e');
        $stmt->execute(['id' => $invitacionId, 'e' => $espacioId]);

        return $stmt->rowCount() > 0;
    }
}
