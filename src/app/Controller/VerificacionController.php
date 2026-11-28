<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Response;
use App\View\View;
use RuntimeException;

/**
 * Confirmar el correo con el enlace que llega al buzón (A1). El enlace (GET) solo MUESTRA un botón: confirmar es un POST con token CSRF, para
 * que un antivirus, un previsualizador de enlaces o cualquier GET automático no gaste el enlace ni cambie nada. No exige sesión: quien
 * tiene el enlace demuestra que controla el buzón, esté o no conectado en ese navegador.
 */
final class VerificacionController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function verificar(): void
    {
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $source = $isPost ? $_POST : $_GET;
        $token = is_string($source['token'] ?? null) ? $source['token'] : '';

        try {
            $servicio = $this->container->verificacionCorreo();

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                if ($servicio->confirmar($token)) {
                    // Con sesión abierta se vuelve a Mi perfil; sin ella, al inicio de sesión.
                    if (isset($_COOKIE[SESSION_NAME]) && current_user() !== null) {
                        Flash::set('ok', 'Listo: tu correo quedó verificado.');
                        Response::redirect('/perfil.php');
                    }

                    Response::redirect('/login.php?msg=verified');
                }
            }

            $valido = $servicio->esValido($token);
        } catch (RuntimeException $error) {
            [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
            Response::plain($status, $message . "\n");
        }

        header('Cache-Control: no-store');
        View::page('auth/verificar', ['token' => $token, 'valido' => $valido], 'Confirmar correo', null, 'auth');
    }
}
