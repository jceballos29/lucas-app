<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Lee la configuración del entorno (variables de entorno que pone docker-compose.yml).
 */
final class Environment
{
    /**
     * Obtiene el valor de una variable de entorno obligatoria.
     *
     * @param string $name El nombre de la variable.
     * @return string El valor (nunca vacío).
     * @throws RuntimeException Si falta o está vacía (error de configuración, no del visitante).
     */
    public static function get(string $name): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            throw new RuntimeException("Falta la variable de entorno $name");
        }

        return $value;
    }
}
