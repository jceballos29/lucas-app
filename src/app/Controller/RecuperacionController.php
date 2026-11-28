<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Http\Failure;
use App\Http\Peticion;
use App\Http\Response;
use App\Logging\Logger;
use App\Validation\ClaveValidator;
use App\View\View;
use RuntimeException;
use Throwable;

/** Recuperar la contraseña por correo (RF-04): pedir el enlace (`recuperar.php`) y elegir una contraseña nueva (`restablecer.php`). */
final class RecuperacionController
{
    private const AVISO = 'Si ese correo está registrado, te enviamos un enlace para elegir una contraseña nueva. Revisa también la carpeta de spam; el enlace vale 30 minutos.';

    public function __construct(private readonly Container $container)
    {
    }

    public function solicitar(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            View::page('auth/recuperar', [], 'Recuperar contraseña', null, 'auth');

            return;
        }

        reject_foreign_origin();
        require_csrf();

        $correo = Peticion::post('correo');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        try {
            $limitador = $this->container->limitador();
            // Cada petición cuenta, exista o no el correo (RNF-24); si se pasó del límite no se envía nada ni se siguen
            // acumulando filas que renovarían indefinidamente la ventana de bloqueo, pero la respuesta es la misma.
            $bloqueado = $limitador->bloqueado('recuperacion', $correo, $ip);
            if (!$bloqueado) {
                $limitador->registrar('recuperacion', $correo, $ip);
            }
        } catch (RuntimeException $error) {
            [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
            http_response_code($status);
            View::page('auth/recuperar', ['error' => $message], 'Recuperar contraseña', null, 'auth');

            return;
        }

        // SIEMPRE la misma respuesta: no se revela qué correos están registrados.
        View::page('auth/recuperar', ['notice' => self::AVISO], 'Recuperar contraseña', null, 'auth');

        // La respuesta se envía YA y el trabajo (buscar al usuario, guardar el token y enviar el correo) sigue después: así el
        // tiempo de respuesta es el mismo exista o no el correo y no delata nada.
        session_write_close();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        if ($bloqueado || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            $this->container->recuperacion()->solicitar($correo);
        } catch (Throwable $error) {
            Logger::exception('recuperacion', $error);
        }
    }

    public function restablecer(): void
    {
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $source = $isPost ? $_POST : $_GET;
        $token = is_string($source['token'] ?? null) ? $source['token'] : '';
        $errors = [];

        try {
            $servicio = $this->container->recuperacion();

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $clave = Peticion::postSinRecortar('clave');
                $clave2 = Peticion::postSinRecortar('clave2');
                $errors = ClaveValidator::errores($clave, $clave2);

                if ($errors === [] && $servicio->restablecer($token, $clave)) {
                    Response::redirect('/login.php?msg=reset');
                }
            }

            // Un enlace que no sirve (falso, usado o caducado) se muestra igual: no se distingue por qué.
            $valido = $servicio->esValido($token);
        } catch (RuntimeException $error) {
            [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
            Response::plain($status, $message . "\n");
        }

        header('Cache-Control: no-store');
        View::page('auth/restablecer', ['token' => $token, 'valido' => $valido, 'errors' => $errors], 'Nueva contraseña', null, 'auth');
    }
}
