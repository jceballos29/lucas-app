<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\Environment;
use RuntimeException;

/**
 * Cliente SMTP mínimo (D7): sin dependencias de terceros, como el resto del proyecto. Habla el protocolo básico —EHLO,
 * STARTTLS opcional, AUTH LOGIN opcional, MAIL FROM, RCPT TO, DATA, QUIT— suficiente para cualquier proveedor (Mailpit en
 * desarrollo; SES, Mailgun, Gmail o un SMTP propio en producción) cambiando solo las variables MAIL_*.
 */
final class SmtpMailer implements Mailer
{
    /** @param string $encryption none (sin cifrar, solo para Mailpit local), starttls (puerto 587) o tls (puerto 465) */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $user,
        private readonly string $password,
        private readonly string $from,
        private readonly string $fromName,
        private readonly int $timeout = 10,
    ) {
    }

    /**
     * B5 de docs/AUDITORIA.md: `AUTH LOGIN` viaja en base64, o sea, en claro. Con `MAIL_ENCRYPTION=none` el usuario y la clave del SMTP
     * irían por la red sin cifrar; solo se admite en desarrollo (`APP_ENV=local`, Mailpit). Lógica pura, para probarla sin servidor.
     *
     * @return string|null El motivo por el que la configuración se rechaza, o null si es aceptable.
     */
    public static function problemaDeConfiguracion(string $encryption, string $usuario, string $clave, string $appEnv): ?string
    {
        if ($encryption === 'none' && ($usuario !== '' || $clave !== '') && $appEnv !== 'local') {
            return 'MAIL_ENCRYPTION=none con MAIL_USER/MAIL_PASSWORD enviaría las credenciales del SMTP sin cifrar: usa starttls (puerto 587) o tls (465).';
        }

        return null;
    }

    /** Construye el cliente con las variables MAIL_* del entorno. @throws MailException Si falta la configuración. */
    public static function fromEnvironment(): self
    {
        try {
            $host = Environment::get('MAIL_HOST');
            $from = Environment::get('MAIL_FROM');
        } catch (RuntimeException $e) {
            throw new MailException('El correo no está configurado (faltan MAIL_HOST o MAIL_FROM).', 0, $e);
        }

        $encryption = strtolower((string) (getenv('MAIL_ENCRYPTION') ?: 'none'));

        if (!in_array($encryption, ['none', 'starttls', 'tls'], true)) {
            throw new MailException('MAIL_ENCRYPTION debe ser none, starttls o tls.');
        }

        $usuario = (string) (getenv('MAIL_USER') ?: '');
        $clave = (string) (getenv('MAIL_PASSWORD') ?: '');
        $problema = self::problemaDeConfiguracion($encryption, $usuario, $clave, (string) (getenv('APP_ENV') ?: 'production'));

        if ($problema !== null) {
            throw new MailException($problema);
        }

        return new self(
            $host,
            (int) (getenv('MAIL_PORT') ?: 25),
            $encryption,
            $usuario,
            $clave,
            $from,
            (string) (getenv('MAIL_FROM_NAME') ?: 'Lucas'),
        );
    }

    public function send(string $to, string $subject, string $body): void
    {
        $domain = substr(strrchr($this->from, '@') ?: '@localhost', 1);
        $message = Message::build(
            $this->from,
            $this->fromName,
            $to,
            $subject,
            $body,
            date('D, d M Y H:i:s O'),
            bin2hex(random_bytes(12)) . '@' . $domain,
        );

        // Con TLS (directo o STARTTLS) se exige un certificado válido y a nombre del servidor: sin esto un intermediario podría suplantarlo.
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'allow_self_signed' => false]]);
        $socket = @stream_socket_client(($this->encryption === 'tls' ? 'tls://' : 'tcp://') . $this->host . ':' . $this->port, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $contexto);

        if ($socket === false) {
            throw new MailException("No se pudo conectar al servidor de correo ($errno: $error).");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);

            if ($this->encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', [220]);

                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                if (@stream_socket_enable_crypto($socket, true, $cryptoMethod) !== true) {
                    throw new MailException('No se pudo establecer la conexión cifrada (STARTTLS).');
                }

                $this->command($socket, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);
            }

            if ($this->user !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->user), [334]);
                $this->command($socket, base64_encode($this->password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            fwrite($socket, Message::forData($message));
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param list<int> $expected
     */
    private function command($socket, string $line, array $expected): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expected);
    }

    /**
     * Lee la respuesta (que puede tener varias líneas: «250-...» y termina en «250 ...») y comprueba el código.
     *
     * @param resource $socket
     * @param list<int> $expected
     */
    private function expect($socket, array $expected): void
    {
        $reply = '';

        do {
            $line = fgets($socket, 1024);

            if ($line === false) {
                throw new MailException('El servidor de correo no respondió a tiempo.');
            }

            $reply .= $line;
        } while (strlen($line) >= 4 && $line[3] === '-');

        if (!in_array((int) substr($reply, 0, 3), $expected, true)) {
            throw new MailException('El servidor de correo rechazó la operación: ' . trim(substr($reply, 0, 200)));
        }
    }
}
