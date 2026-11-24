<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/** No se pudo construir o enviar un correo. */
final class MailException extends RuntimeException
{
}
