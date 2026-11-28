<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Http\Failure;
use App\Security\AdminSemilla;
use Throwable;

/**
 * Comprobación de salud para monitorizar la disponibilidad (RNF-14): 200 si la aplicación y la base de datos responden y 503 si no.
 * Es pública pero NO revela nada (ni versiones ni errores): solo «ok» o «no disponible».
 */
final class SaludController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function estado(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');

        try {
            $this->container->pdo()->query('SELECT 1')->fetchColumn();

            // C1: fuera de «local», el administrador de la seed con su clave pública hace la instancia insegura: no se declara sana.
            if (getenv('APP_ENV') !== 'local' && AdminSemilla::expuesto($this->container->usuarios()->findByEmail(AdminSemilla::CORREO), (string) getenv('APP_ENV'))) {
                error_log('[seguridad] ' . AdminSemilla::CORREO . ' sigue activo con la clave pública de desarrollo: cambia su clave o desactívalo (docs/DESPLIEGUE.md).');
                http_response_code(503);
                echo '{"estado":"no disponible"}';

                return;
            }

            echo '{"estado":"ok"}';
        } catch (Throwable $error) {
            Failure::describe($error, ''); // el detalle va al log, no a la respuesta
            http_response_code(503);
            echo '{"estado":"no disponible"}';
        }
    }
}
