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

        return new self(
            $host,
            (int) (getenv('MAIL_PORT') ?: 25),
            $encryption,
            (string) (getenv('MAIL_USER') ?: ''),
            (string) (getenv('MAIL_PASSWORD') ?: ''),
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
            bin2hex(random_bytes(12)) . '@' . $domain
        );

        $socket = @stream_socket_client(($this->encryption === 'tls' ? 'tls://' : 'tcp://') . $this->host . ':' . $this->port, $errno, $error, $this->timeout);

        if ($socket === false) {
            throw new MailException("No se pudo conectar al servidor de correo ($errno: $error).");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);

            if ($this->encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', [220]);

                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
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

    /** @param resource $socket @param list<int> $expected */
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
