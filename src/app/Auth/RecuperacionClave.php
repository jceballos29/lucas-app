<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\Transaction;
use App\Logging\Logger;
use App\Mail\Mailer;
use App\Mail\MailException;
use App\Repository\RestablecimientoRepository;
use App\Repository\UsuarioRepository;
use PDO;

/**
 * Recuperar la contraseña por correo (RF-04). El enlace lleva un token al azar de 256 bits; en la BD solo queda su HASH, vale
 * 30 minutos y se usa una sola vez. Pedir uno nuevo anula el anterior.
 */
final class RecuperacionClave
{
    public const VIGENCIA_MINUTOS = 30;

    public function __construct(
        private readonly PDO $pdo,
        private readonly UsuarioRepository $usuarios,
        private readonly RestablecimientoRepository $tokens,
        private readonly Mailer $mailer,
        private readonly string $origen,
    ) {
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** ¿Tiene la forma de un token (64 hexadecimales)? Lo demás ni se consulta. */
    public static function formaValida(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    /**
     * Si el correo es de un usuario ACTIVO, crea el enlace y lo envía. Si no, no hace nada y NO lo dice: quien llama responde
     * lo mismo en los dos casos (RNF-24). Un fallo al enviar se registra, nunca se propaga.
     */
    public function solicitar(string $correo): void
    {
        $usuario = $this->usuarios->findByEmail(mb_strtolower(trim($correo)));

        if ($usuario === null || !$usuario->activo) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        Transaction::run($this->pdo, fn () => $this->tokens->replaceFor($usuario->id, self::hashToken($token), self::VIGENCIA_MINUTOS));

        $enlace = rtrim($this->origen, '/') . '/restablecer.php?token=' . $token;
        $cuerpo = "Hola {$usuario->nombre},\n\n"
            . 'Recibimos una solicitud para restablecer la contraseña de tu cuenta de Lucas. Para elegir una nueva, abre este enlace '
            . '(vale ' . self::VIGENCIA_MINUTOS . " minutos y solo se puede usar una vez):\n\n$enlace\n\n"
            . "Si tú no lo pediste, ignora este mensaje: tu contraseña sigue igual.\n";

        try {
            $this->mailer->send($usuario->correo, 'Restablece tu contraseña de Lucas', $cuerpo);
        } catch (MailException $e) {
            Logger::exception('mail', $e);
        }
    }

    public function esValido(string $token): bool
    {
        return self::formaValida($token) && $this->tokens->isValid(self::hashToken($token));
    }

    /**
     * Cambia la contraseña con un token vigente y lo gasta, todo en una transacción. Las demás sesiones del usuario dejan de
     * valer (la sesión guarda la huella del hash de la contraseña y se compara en cada petición).
     *
     * @return bool false si el enlace no existe, ya se usó, caducó o la cuenta está inactiva
     */
    public function restablecer(string $token, string $nuevaClave): bool
    {
        if (!self::formaValida($token)) {
            return false;
        }

        return Transaction::run($this->pdo, function () use ($token, $nuevaClave): bool {
            $fila = $this->tokens->lockValid(self::hashToken($token));

            if ($fila === null) {
                return false;
            }

            $usuario = $this->usuarios->findById($fila['userId']);

            if ($usuario === null || !$usuario->activo) {
                return false;
            }

            $this->usuarios->updatePasswordHash($usuario->id, password_hash($nuevaClave, PASSWORD_DEFAULT));
            // Abrir el enlace que llegó al buzón demuestra que el correo es suyo (A1): queda verificado.
            $this->usuarios->marcarCorreoVerificado($usuario->id);
            $this->tokens->markUsed($fila['id']);
            $this->tokens->deleteOthers($usuario->id, $fila['id']);

            return true;
        });
    }
}
