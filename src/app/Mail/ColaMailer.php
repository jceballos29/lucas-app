<?php

declare(strict_types=1);

namespace App\Mail;

use App\Repository\ColaCorreoRepository;

/**
 * El «correo» de las peticiones web (M4): no habla con el servidor SMTP, solo deja el mensaje en la cola (un INSERT, milisegundos).
 * Quien lo envía de verdad, con reintentos, es `scripts/enviar-cola.php`. Así un SMTP lento o caído no ocupa procesos de PHP-FPM.
 * Lo que sí se comprueba aquí, para que quien llama se entere de un error evidente: que el destinatario sea una dirección válida y
 * que ni él ni el asunto lleven saltos de línea (inyección de cabeceras).
 */
final class ColaMailer implements Mailer
{
    public function __construct(private readonly ColaCorreoRepository $cola)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (preg_match('/[\r\n\0]/', $to . $subject) === 1) {
            throw new MailException('El destinatario y el asunto no pueden contener saltos de línea.');
        }

        if (preg_match('/[<>\s]/', $to) === 1 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException('El destinatario no es una dirección válida.');
        }

        $this->cola->encolar($to, $subject, $body);
    }
}
