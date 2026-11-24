<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * No se pudo conectar a la base de datos (clave mala, servidor apagado...). Una excepción «marcadora»: su valor
 * está en el tipo, que permite responder 503 (temporal) en lugar de 500.
 */
final class DatabaseConnectionException extends RuntimeException
{
}
