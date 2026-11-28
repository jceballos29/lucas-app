<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\Transaction;
use App\Repository\DosPasosRepository;
use App\Security\CifradoSecreto;
use PDO;

/**
 * Autenticación en dos pasos (M7 de docs/AUDITORIA.md): TOTP (RFC 6238) + 10 códigos de recuperación de un solo uso.
 *
 * - El secreto se guarda CIFRADO (clave APP_KEY, fuera de la base).
 * - Un código TOTP vale una sola vez: se recuerda el último paso aceptado y solo se acepta uno posterior.
 * - Los códigos de recuperación (80 bits) se guardan como hash y se gastan al usarlos.
 * - Obligatorio para administradores fuera de desarrollo ({@see self::adminDebeActivar()}).
 */
final class DosPasos
{
    public const CODIGOS_RECUPERACION = 10;

    public const OK_TOTP = 'totp';
    public const OK_RECUPERACION = 'recuperacion';

    public function __construct(
        private readonly PDO $pdo,
        private readonly DosPasosRepository $repo,
        private readonly CifradoSecreto $cifrado,
    ) {
    }

    /** ¿Debe este administrador activar el 2FA antes de usar el panel de administración? (Los usuarios normales no están obligados.) */
    public static function adminDebeActivar(string $rol, bool $dosPasosActivo, bool $obligatorio): bool
    {
        return $rol === 'admin' && $obligatorio && !$dosPasosActivo;
    }

    /** Valor por defecto de la obligatoriedad: sí, salvo en desarrollo (`APP_ENV=local`); `ADMIN_2FA_OBLIGATORIO` (0/1) lo fuerza. */
    public static function obligatorioParaAdmin(string $appEnv, ?string $forzado): bool
    {
        if ($forzado !== null && $forzado !== '') {
            return in_array(strtolower($forzado), ['1', 'true', 'si', 'sí', 'yes'], true);
        }

        return $appEnv !== 'local';
    }

    /** Código de recuperación al azar con forma XXXX-XXXX-XXXX-XXXX (80 bits, sin caracteres ambiguos). */
    public static function generarCodigoRecuperacion(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 símbolos = 5 bits cada uno
        $codigo = '';
        for ($i = 0; $i < 16; $i++) {
            $codigo .= $alfabeto[random_int(0, 31)];
        }

        return implode('-', str_split($codigo, 4));
    }

    /** Hash del código tal como se guarda: ignora mayúsculas, guiones y espacios. */
    public static function hashCodigo(string $codigo): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\s-]+/', '', $codigo) ?? ''));
    }

    public function estaActivo(int $idUsuario): bool
    {
        return $this->repo->datos($idUsuario) !== null;
    }

    public function codigosDisponibles(int $idUsuario): int
    {
        return $this->repo->codigosDisponibles($idUsuario);
    }

    /**
     * Confirma la activación: si el código es válido para el secreto de la configuración en curso, lo guarda cifrado, activa el 2FA y
     * genera los códigos de recuperación (se devuelven EN CLARO esta única vez). null si el código no coincide.
     *
     * @return list<string>|null
     */
    public function activar(int $idUsuario, string $secretoBase32, string $codigo, ?int $ahora = null): ?array
    {
        $paso = Totp::verificar($secretoBase32, $codigo, $ahora ?? time());
        if ($paso === null) {
            return null;
        }

        return Transaction::run($this->pdo, function () use ($idUsuario, $secretoBase32, $paso): array {
            $this->repo->activar($idUsuario, $this->cifrado->cifrar($secretoBase32), $paso);

            return $this->nuevosCodigos($idUsuario);
        });
    }

    /** Genera 10 códigos nuevos (los anteriores dejan de valer) y los devuelve en claro. @return list<string> */
    public function regenerarCodigos(int $idUsuario): array
    {
        return Transaction::run($this->pdo, fn (): array => $this->nuevosCodigos($idUsuario));
    }

    public function desactivar(int $idUsuario): void
    {
        $this->repo->desactivar($idUsuario);
    }

    /**
     * Comprueba un código al iniciar sesión (o antes de una acción delicada): TOTP vigente y no usado, o código de recuperación sin gastar.
     *
     * @return string|null `OK_TOTP`, `OK_RECUPERACION` o null si no vale.
     */
    public function comprobar(int $idUsuario, string $entrada, ?int $ahora = null): ?string
    {
        $datos = $this->repo->datos($idUsuario);
        if ($datos === null) {
            return null;
        }

        $limpio = preg_replace('/[\s-]+/', '', $entrada) ?? '';

        if (preg_match('/^\d{6}$/', $limpio) === 1) {
            try {
                $secreto = $this->cifrado->descifrar($datos['secreto']);
            } catch (\RuntimeException) {
                return null;
            }

            $paso = Totp::verificar($secreto, $limpio, $ahora ?? time());

            return $paso !== null && $this->repo->consumirPaso($idUsuario, $paso) ? self::OK_TOTP : null;
        }

        if (preg_match('/^[A-Za-z0-9]{16}$/', $limpio) === 1 && $this->repo->consumirCodigo($idUsuario, self::hashCodigo($limpio))) {
            return self::OK_RECUPERACION;
        }

        return null;
    }

    /** @return list<string> */
    private function nuevosCodigos(int $idUsuario): array
    {
        $claros = [];
        while (count($claros) < self::CODIGOS_RECUPERACION) {
            $claros[self::hashCodigo($c = self::generarCodigoRecuperacion())] = $c;
        }

        $this->repo->reemplazarCodigos($idUsuario, array_keys($claros));

        return array_values($claros);
    }
}
