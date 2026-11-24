<?php

declare(strict_types=1);

namespace App\Mail;

/** Envío de correos. Una implementación real (SmtpMailer) y, en las pruebas, una que solo guarda lo «enviado». */
interface Mailer
{
    /**
     * @param string $subject Asunto (texto plano, UTF-8).
     * @param string $body Cuerpo en texto plano, UTF-8.
     * @throws MailException Si no se pudo enviar.
     */
    public function send(string $to, string $subject, string $body): void;
}
