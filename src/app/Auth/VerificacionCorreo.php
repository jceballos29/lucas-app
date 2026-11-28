<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\Transaction;
use App\Domain\Usuario;
use App\Logging\Logger;
use App\Mail\Mailer;
use App\Mail\MailException;
use App\Repository\NotificacionRepository;
use App\Repository\UsuarioRepository;
use App\Repository\VerificacionCorreoRepository;
use PDO;

/**
 * Verificar que quien se registra controla el correo (A1 de docs/AUDITORIA.md). El enlace lleva un token al azar de 256 bits; en la BD solo
 * queda su HASH, vale 48 horas y se usa una sola vez. Mientras el correo no está verificado, la cuenta funciona, pero Lucas no le envía
 * correo (alertas, resúmenes) y no puede enviar ni aceptar invitaciones a espacios compartidos: así registrar el correo de otra persona
 * no sirve para recibir sus invitaciones ni para usar a Lucas como emisor de correo.
 */
final class VerificacionCorreo
{
    public const VIGENCIA_HORAS = 48;

    /** Reenvíos permitidos por usuario en la ventana (el correo del registro cuenta como uno). */
    public const MAX_ENVIOS = 3;
    public const VENTANA_ENVIOS = 3600;

    public const OK = 'ok';
    public const YA_VERIFICADO = 'ya_verificado';
    public const LIMITE = 'limite';

    public function __construct(
        private readonly PDO $pdo,
        private readonly UsuarioRepository $usuarios,
        private readonly VerificacionCorreoRepository $tokens,
        private readonly NotificacionRepository $notificaciones,
        private readonly Mailer $mailer,
        private readonly string $origen,
    ) {
    }

    /** ¿Tiene la forma de un token (64 hexadecimales)? Lo demás ni se consulta. */
    public static function formaValida(string $token): bool
    {
        return RecuperacionClave::formaValida($token);
    }

    /**
     * Crea el enlace (anulando los anteriores) y lo envía. Cuenta para el límite de reenvíos. Un fallo al enviar se registra y no se propaga:
     * el usuario puede pedir el reenvío desde su perfil.
     */
    public function enviar(Usuario $usuario): string
    {
        if ($usuario->correoVerificado) {
            return self::YA_VERIFICADO;
        }

        $prefijo = 'verificacion:u' . $usuario->id . ':';

        if ($this->notificaciones->contarPorPrefijo($prefijo, self::VENTANA_ENVIOS, $usuario->id) >= self::MAX_ENVIOS) {
            return self::LIMITE;
        }

        $this->notificaciones->reservar($usuario->id, null, $prefijo . bin2hex(random_bytes(8)));

        $token = bin2hex(random_bytes(32));
        Transaction::run($this->pdo, fn () => $this->tokens->replaceFor($usuario->id, $usuario->correo, RecuperacionClave::hashToken($token), self::VIGENCIA_HORAS));

        $enlace = rtrim($this->origen, '/') . '/verificar.php?token=' . $token;
        $cuerpo = "Hola {$usuario->nombre},\n\n"
            . 'Para confirmar que este correo es tuyo y activar las invitaciones y los avisos de Lucas, abre este enlace (vale '
            . self::VIGENCIA_HORAS . " horas y solo se puede usar una vez):\n\n$enlace\n\n"
            . "Si no creaste una cuenta en Lucas, ignora este mensaje: no pasará nada.\n";

        try {
            $this->mailer->send($usuario->correo, 'Confirma tu correo en Lucas', $cuerpo);
        } catch (MailException $e) {
            Logger::exception('mail', $e);
        }

        return self::OK;
    }

    public function esValido(string $token): bool
    {
        return self::formaValida($token) && $this->tokens->isValid(RecuperacionClave::hashToken($token));
    }

    /**
     * Gasta el enlace y marca el correo como verificado, todo en una transacción. Si el usuario cambió de correo desde que se envió, o
     * está inactivo, no sirve.
     *
     * @return bool false si el enlace no existe, ya se usó, caducó o ya no corresponde a la cuenta
     */
    public function confirmar(string $token): bool
    {
        if (!self::formaValida($token)) {
            return false;
        }

        return Transaction::run($this->pdo, function () use ($token): bool {
            $fila = $this->tokens->lockValid(RecuperacionClave::hashToken($token));

            if ($fila === null) {
                return false;
            }

            $usuario = $this->usuarios->findById($fila['userId']);

            if ($usuario === null || !$usuario->activo || $usuario->correo !== $fila['correo']) {
                return false;
            }

            $this->usuarios->marcarCorreoVerificado($usuario->id);
            $this->tokens->markUsed($fila['id']);

            return true;
        });
    }
}
