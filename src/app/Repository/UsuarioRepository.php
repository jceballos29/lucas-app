<?php

declare(strict_types=1);

namespace App\Repository;

use App\Auth\CredentialSource;
use App\Database\Esquema;
use App\Domain\Usuario;
use PDO;
use PDOException;

/** Único sitio con el SQL de `usuarios`. */
final class UsuarioRepository implements CredentialSource
{
    private const COLUMNS = 'id_usuario, nombre, correo, contrasena_hash, moneda, rol, activo, notif_presupuestos, notif_resumen_mensual, correo_verificado_en, totp_activado_en';

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
    public function create(string $nombre, string $correo, string $hash, string $rol = Usuario::ROL_USUARIO, bool $verificado = false): ?int
    {
        try {
            // El registro público crea cuentas SIN verificar (A1); los administradores que crea la consola van verificados.
            $stmt = $this->pdo->prepare('INSERT INTO usuarios (nombre, correo, contrasena_hash, rol, correo_verificado_en) VALUES (:nombre, :correo, :hash, :rol, ' . ($verificado ? 'NOW()' : 'NULL') . ')');
            $stmt->execute(['nombre' => $nombre, 'correo' => $correo, 'hash' => $hash, 'rol' => $rol]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return null;
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /** Marca el correo como verificado (si ya lo estaba, conserva la fecha original). */
    public function marcarCorreoVerificado(int $id): void
    {
        $this->pdo->prepare('UPDATE usuarios SET correo_verificado_en = COALESCE(correo_verificado_en, NOW()) WHERE id_usuario = :id')->execute(['id' => $id]);
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

    public function updateNotificaciones(int $id, bool $presupuestos, bool $resumenMensual): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET notif_presupuestos = :p, notif_resumen_mensual = :r WHERE id_usuario = :id');
        $stmt->execute(['p' => $presupuestos ? 1 : 0, 'r' => $resumenMensual ? 1 : 0, 'id' => $id]);
    }

    /**
     * Usuarios activos, con el correo verificado, que activaron el resumen mensual (para el script de consola).
     *
     * @return list<Usuario>
     */
    public function listWithResumenMensual(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM usuarios WHERE activo = 1 AND notif_resumen_mensual = 1 AND correo_verificado_en IS NOT NULL ORDER BY id_usuario');

        return array_map([Usuario::class, 'fromRow'], $stmt->fetchAll());
    }

    /**
     * Borra TODO lo del usuario y luego al usuario (RNF-20), en un ORDEN explícito: `transacciones` y `aportes_meta` referencian
     * `cuentas` sin cascada, así que no se puede confiar en el orden de los `ON DELETE CASCADE`. Solo dentro de una transacción.
     *
     * @return list<string> Rutas relativas de comprobantes adjuntos que deben eliminarse de disco tras confirmar la transacción.
     */
    public function deleteWithAllData(int $id): array
    {
        $adjuntosABorrar = [];
        $stmt = $this->pdo->prepare("SELECT id_espacio FROM espacio_miembros WHERE id_usuario = :id AND rol = 'propietario'");
        $stmt->execute(['id' => $id]);
        $espaciosComoOwner = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($espaciosComoOwner as $espacioId) {
            $stmtOtros = $this->pdo->prepare("SELECT COUNT(*) FROM espacio_miembros WHERE id_espacio = :e AND rol = 'propietario' AND id_usuario <> :id");
            $stmtOtros->execute(['e' => $espacioId, 'id' => $id]);
            if ((int) $stmtOtros->fetchColumn() > 0) {
                $this->pdo->prepare('UPDATE espacios SET id_creador = (SELECT id_usuario FROM espacio_miembros WHERE id_espacio = :e2 AND id_usuario <> :id LIMIT 1) WHERE id_espacio = :e AND id_creador = :id2')
                    ->execute(['e2' => $espacioId, 'id' => $id, 'e' => $espacioId, 'id2' => $id]);
                continue;
            }

            $stmtCandidato = $this->pdo->prepare("SELECT id_usuario FROM espacio_miembros WHERE id_espacio = :e AND id_usuario <> :id ORDER BY FIELD(rol, 'editor', 'lector'), unido_en ASC LIMIT 1");
            $stmtCandidato->execute(['e' => $espacioId, 'id' => $id]);
            $candidato = $stmtCandidato->fetchColumn();

            if ($candidato !== false) {
                $nuevoOwner = (int) $candidato;
                $this->pdo->prepare("UPDATE espacio_miembros SET rol = 'propietario' WHERE id_espacio = :e AND id_usuario = :u")
                    ->execute(['e' => $espacioId, 'u' => $nuevoOwner]);
                $this->pdo->prepare('UPDATE espacios SET id_creador = :u WHERE id_espacio = :e AND id_creador = :id')
                    ->execute(['u' => $nuevoOwner, 'e' => $espacioId, 'id' => $id]);
            } else {
                $stmtAdjEsp = $this->pdo->prepare('SELECT adjunto_ruta FROM transacciones WHERE id_espacio = :e AND adjunto_ruta IS NOT NULL');
                $stmtAdjEsp->execute(['e' => $espacioId]);
                foreach ($stmtAdjEsp->fetchAll(PDO::FETCH_COLUMN) as $ruta) {
                    if (is_string($ruta) && $ruta !== '') {
                        $adjuntosABorrar[] = $ruta;
                    }
                }
                Esquema::borrarEspacio($this->pdo, $espacioId);
            }
        }

        // Si el usuario creó un espacio pero luego ya no era propietario, reasignar id_creador a un propietario actual.
        $this->pdo->prepare("UPDATE espacios e SET e.id_creador = (SELECT em.id_usuario FROM espacio_miembros em WHERE em.id_espacio = e.id_espacio AND em.id_usuario <> :id ORDER BY em.rol = 'propietario' DESC LIMIT 1) WHERE e.id_creador = :id2")
            ->execute(['id' => $id, 'id2' => $id]);

        // Reasignar registros financieros en espacios compartidos que aún continúan con otros miembros al propietario del espacio.
        foreach (Esquema::TABLAS_FINANCIERAS as $tabla) {
            $this->pdo->prepare(
                "UPDATE $tabla t JOIN espacios e ON e.id_espacio = t.id_espacio
                 SET t.id_usuario = e.id_creador
                 WHERE t.id_usuario = :id AND t.id_espacio IS NOT NULL"
            )->execute(['id' => $id]);
        }

        $this->pdo->prepare(
            "UPDATE pagos_deuda pd JOIN deudas d ON d.id_deuda = pd.id_deuda JOIN espacios e ON e.id_espacio = d.id_espacio
             SET pd.id_usuario = e.id_creador
             WHERE pd.id_usuario = :id AND d.id_espacio IS NOT NULL"
        )->execute(['id' => $id]);

        $stmtAdjPers = $this->pdo->prepare('SELECT adjunto_ruta FROM transacciones WHERE id_usuario = :id AND id_espacio IS NULL AND adjunto_ruta IS NOT NULL');
        $stmtAdjPers->execute(['id' => $id]);
        foreach ($stmtAdjPers->fetchAll(PDO::FETCH_COLUMN) as $ruta) {
            if (is_string($ruta) && $ruta !== '') {
                $adjuntosABorrar[] = $ruta;
            }
        }

        Esquema::borrarPersonales($this->pdo, $id);

        return $adjuntosABorrar;
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
