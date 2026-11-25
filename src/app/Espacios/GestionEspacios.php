<?php

declare(strict_types=1);

namespace App\Espacios;

use App\Auth\RecuperacionClave;
use App\Database\Transaction;
use App\Domain\Espacio;
use App\Mail\Mailer;
use App\Repository\EspacioRepository;
use PDO;
use RuntimeException;

/**
 * Servicio transaccional de espacios compartidos / cuenta familiar (Fase 10):
 *  - crear espacio (el creador nace como `propietario`);
 *  - actualizar nombre y moneda del espacio (solo `propietario`);
 *  - invitar por correo electrónico (envía correo y registra invitación pendiente en BD);
 *  - aceptar invitación (por `id_invitacion` desde `/espacios.php` o por `token` del correo);
 *  - cambiar rol de un miembro (garantizando con `FOR UPDATE` que siempre quede al menos un `propietario`);
 *  - retirar a un miembro o abandonar un espacio (garantizando que nunca quede sin `propietario`);
 *  - eliminar espacio y todos sus datos (solo `propietario`).
 */
final class GestionEspacios
{
    public const INVITACION_TTL = 172800; // 48 horas

    public function __construct(
        private readonly PDO $pdo,
        private readonly EspacioRepository $espacios,
        private readonly Mailer $mailer,
        private readonly string $appOrigin,
    ) {
    }

    public function crear(int $userId, string $nombre, string $moneda): int
    {
        return Transaction::run($this->pdo, function () use ($userId, $nombre, $moneda): int {
            $espacioId = $this->espacios->create($userId, $nombre, $moneda);
            $this->espacios->addMember($espacioId, $userId, 'propietario');

            return $espacioId;
        });
    }

    public function editar(int $actorId, int $espacioId, string $nombre, string $moneda): string
    {
        return Transaction::run($this->pdo, function () use ($actorId, $espacioId, $nombre, $moneda): string {
            $espacio = $this->espacios->lockForMember($actorId, $espacioId);

            if ($espacio === null) {
                return ReglasEspacio::NO_EXISTE;
            }

            if (!$espacio->esPropietario()) {
                return ReglasEspacio::SIN_PERMISO;
            }

            $this->espacios->update($espacioId, $nombre, $moneda);

            return ReglasEspacio::OK;
        });
    }

    /**
     * @return array{codigo: string, token: string|null, espacioNombre: string|null}
     */
    public function invitar(int $actorId, string $actorNombre, int $espacioId, string $correo, string $rol): array
    {
        $correo = strtolower(trim($correo));
        $token = bin2hex(random_bytes(32));
        $tokenHash = RecuperacionClave::hashToken($token);

        $resultado = Transaction::run($this->pdo, function () use ($actorId, $espacioId, $correo, $rol, $tokenHash): array {
            $espacio = $this->espacios->lockForMember($actorId, $espacioId);

            if ($espacio === null) {
                return ['codigo' => ReglasEspacio::NO_EXISTE, 'espacioNombre' => null];
            }

            if (!$espacio->esPropietario()) {
                return ['codigo' => ReglasEspacio::SIN_PERMISO, 'espacioNombre' => $espacio->nombre];
            }

            if (!array_key_exists($rol, Espacio::ROLES_INVITACION)) {
                return ['codigo' => ReglasEspacio::ROL_INVALIDO, 'espacioNombre' => $espacio->nombre];
            }

            if ($this->espacios->isMemberByEmail($espacioId, $correo)) {
                return ['codigo' => ReglasEspacio::YA_ES_MIEMBRO, 'espacioNombre' => $espacio->nombre];
            }

            $this->espacios->createInvitation($espacioId, $correo, $rol, $tokenHash, self::INVITACION_TTL);

            return ['codigo' => ReglasEspacio::OK, 'espacioNombre' => $espacio->nombre];
        });

        if ($resultado['codigo'] !== ReglasEspacio::OK) {
            return ['codigo' => $resultado['codigo'], 'token' => null, 'espacioNombre' => $resultado['espacioNombre']];
        }

        $enlace = rtrim($this->appOrigin, '/') . '/espacios.php?token=' . $token;
        $rolEtiqueta = Espacio::ROLES[$rol] ?? $rol;
        $cuerpo = "Hola,\r\n\r\n"
            . "{$actorNombre} te invitó a unirte al espacio compartido «{$resultado['espacioNombre']}» en Lucas como {$rolEtiqueta}.\r\n\r\n"
            . "Para aceptar la invitación, entra en:\r\n{$enlace}\r\n\r\n"
            . "También puedes iniciar sesión con tu cuenta ({$correo}) y aceptarla directamente en la sección «Espacios».\r\n"
            . "Este enlace caduca en 48 horas.\r\n";

        try {
            $this->mailer->send($correo, "Invitación a «{$resultado['espacioNombre']}» en Lucas", $cuerpo);
        } catch (RuntimeException) {
            // La invitación ya quedó guardada en la BD y el usuario invitado la verá en /espacios.php al iniciar sesión.
        }

        return ['codigo' => ReglasEspacio::OK, 'token' => $token, 'espacioNombre' => $resultado['espacioNombre']];
    }

    /**
     * Acepta una invitación pendiente por `id_invitacion` o por `token` en claro, verificando que corresponda al correo del usuario.
     *
     * @return array{codigo: string, id_espacio: int|null, nombre: string|null}
     */
    public function aceptarInvitacion(int $userId, string $correoUsuario, ?int $invitacionId = null, ?string $token = null): array
    {
        return Transaction::run($this->pdo, function () use ($userId, $correoUsuario, $invitacionId, $token): array {
            $inv = null;
            if ($token !== null && RecuperacionClave::formaValida($token)) {
                $inv = $this->espacios->lockInvitationByToken(RecuperacionClave::hashToken($token), $correoUsuario);
            } elseif ($invitacionId !== null && $invitacionId > 0) {
                $inv = $this->espacios->lockInvitationForEmail($invitacionId, $correoUsuario);
            }

            if ($inv === null) {
                return ['codigo' => ReglasEspacio::NO_EXISTE, 'id_espacio' => null, 'nombre' => null];
            }

            if ($this->espacios->findMemberRole($inv['id_espacio'], $userId) === null) {
                $this->espacios->addMember($inv['id_espacio'], $userId, $inv['rol']);
            }

            $this->espacios->deleteInvitation($inv['id_espacio'], $inv['id_invitacion']);

            return [
                'codigo' => ReglasEspacio::OK,
                'id_espacio' => $inv['id_espacio'],
                'nombre' => $inv['espacio_nombre'],
            ];
        });
    }

    public function cancelarInvitacion(int $actorId, int $espacioId, int $invitacionId): string
    {
        return Transaction::run($this->pdo, function () use ($actorId, $espacioId, $invitacionId): string {
            $espacio = $this->espacios->lockForMember($actorId, $espacioId);

            if ($espacio === null) {
                return ReglasEspacio::NO_EXISTE;
            }

            if (!$espacio->esPropietario()) {
                return ReglasEspacio::SIN_PERMISO;
            }

            return $this->espacios->deleteInvitation($espacioId, $invitacionId) ? ReglasEspacio::OK : ReglasEspacio::NO_EXISTE;
        });
    }

    public function cambiarRol(int $actorId, int $espacioId, int $objetivoId, string $nuevoRol): string
    {
        return Transaction::run($this->pdo, function () use ($actorId, $espacioId, $objetivoId, $nuevoRol): string {
            $espacio = $this->espacios->lockForMember($actorId, $espacioId);

            if ($espacio === null) {
                return ReglasEspacio::NO_EXISTE;
            }

            $owners = $this->espacios->lockOwnerIds($espacioId);
            $rolObjetivo = $this->espacios->findMemberRole($espacioId, $objetivoId);

            $codigo = ReglasEspacio::evaluarCambioRol($espacio->rolUsuario, $objetivoId, $rolObjetivo, $nuevoRol, $owners);
            if ($codigo !== ReglasEspacio::OK) {
                return $codigo;
            }

            $this->espacios->updateMemberRole($espacioId, $objetivoId, $nuevoRol);

            return ReglasEspacio::OK;
        });
    }

    public function retirarMiembro(int $actorId, int $espacioId, int $objetivoId): string
    {
        return Transaction::run($this->pdo, function () use ($actorId, $espacioId, $objetivoId): string {
            $espacio = $this->espacios->lockForMember($actorId, $espacioId);

            if ($espacio === null) {
                return ReglasEspacio::NO_EXISTE;
            }

            $owners = $this->espacios->lockOwnerIds($espacioId);
            $rolObjetivo = $this->espacios->findMemberRole($espacioId, $objetivoId);

            $codigo = ReglasEspacio::evaluarSalidaMiembro($actorId, $espacio->rolUsuario, $objetivoId, $rolObjetivo, $owners);
            if ($codigo !== ReglasEspacio::OK) {
                return $codigo;
            }

            $this->espacios->removeMember($espacioId, $objetivoId);

            return ReglasEspacio::OK;
        });
    }

    public function eliminarEspacio(int $actorId, int $espacioId): string
    {
        return Transaction::run($this->pdo, function () use ($actorId, $espacioId): string {
            $espacio = $this->espacios->lockForMember($actorId, $espacioId);

            if ($espacio === null) {
                return ReglasEspacio::NO_EXISTE;
            }

            if (!$espacio->esPropietario()) {
                return ReglasEspacio::SIN_PERMISO;
            }

            $this->espacios->deleteWithAllData($espacioId);

            return ReglasEspacio::OK;
        });
    }
}
