<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Usuario;
use App\Http\Failure;
use App\Http\Response;
use App\Logging\Logger;
use App\Validation\RegistroValidator;
use App\View\View;
use RuntimeException;

/** Registro, inicio y cierre de sesión, y la redirección de la portada según el rol (RF-01, RF-02, RF-24 a RF-27). */
final class AuthController
{
    private const MENSAJES_LOGIN = [
        'expired' => ['Tu sesión caducó por inactividad. Vuelve a iniciar sesión.', 'warn'],
        'revoked' => ['Tu sesión se cerró: tu cuenta ya no está activa o tu contraseña cambió. Inicia sesión de nuevo; si crees que es un error, contacta al administrador.', 'warn'],
        'reset' => ['Listo: tu contraseña se cambió. Ya puedes iniciar sesión con la nueva.', 'ok'],
        'deleted' => ['Tu cuenta y todos tus datos se eliminaron. Gracias por haber usado Lucas.', 'ok'],
    ];

    public function __construct(private readonly Container $container)
    {
    }

    /** index.php: sin sesión → login; admin → dashboard de administración; usuario → su panel. */
    public function home(): never
    {
        Response::redirect(self::homeFor(require_login()['role']));
    }

    public function login(): void
    {
        if (!$this->isPost()) {
            $this->redirectIfLoggedIn();
            [$notice, $tone] = self::MENSAJES_LOGIN[(string) ($_GET['msg'] ?? '')] ?? [null, 'info'];
            View::page('auth/login', ['notice' => $notice, 'noticeTone' => $tone], 'Iniciar sesión', null, 'auth');

            return;
        }

        $correo = self::field('correo');
        $clave = self::field('clave', false);
        $ip = self::ip();

        try {
            $limitador = $this->container->limitador();

            if ($limitador->bloqueado('login', $correo, $ip)) {
                $this->tooManyAttempts('auth/login', ['email' => $correo], 'Iniciar sesión');
            }

            $usuario = $this->container->autenticador()->authenticate($correo, $clave);

            if ($usuario === null) {
                $limitador->registrar('login', $correo, $ip);
                View::page('auth/login', ['email' => $correo, 'error' => 'Correo o contraseña incorrectos.'], 'Iniciar sesión', null, 'auth');

                return;
            }

            $limitador->perdonar('login', $correo, $ip);
        } catch (RuntimeException $error) {
            $this->failed($error, 'auth/login', ['email' => $correo], 'Iniciar sesión');
        }

        self::startSession($usuario);
        Response::redirect(self::homeFor($usuario->rol));
    }

    public function register(): void
    {
        if (!$this->isPost()) {
            $this->redirectIfLoggedIn();
            View::page('auth/registro', [], 'Crear cuenta', null, 'auth');

            return;
        }

        // El formulario NO tiene campo de rol y aquí tampoco se lee: el rol siempre es «usuario» (RF-25).
        $result = (new RegistroValidator())->validate($_POST);
        $data = $result['data'];
        $errors = $result['errors'];
        $old = ['nombre' => $data['nombre'], 'correo' => $data['correo']];

        try {
            $limitador = $this->container->limitador();

            if ($limitador->bloqueado('registro', $data['correo'], self::ip())) {
                $this->tooManyAttempts('auth/registro', ['old' => $old], 'Crear cuenta');
            }

            if ($errors === []) {
                // Solo se contabiliza para el correo cuando el formulario es válido: así un tercero no puede bloquear un correo
                // ajeno enviando contraseñas que no coinciden.
                $limitador->registrar('registro', $data['correo'], self::ip());
                $usuarios = $this->container->usuarios();
                $hash = password_hash($data['clave'], PASSWORD_DEFAULT);
                $id = $usuarios->findByEmail($data['correo']) === null
                    ? $usuarios->create($data['nombre'], $data['correo'], $hash)
                    : null;

                if ($id === null) {
                    // D2: el registro sí avisa que el correo existe (no hay correo transaccional para ocultarlo).
                    $errors['correo'] = 'Este correo ya está registrado. ¿Quieres iniciar sesión?';
                } else {
                    $nuevo = new Usuario($id, $data['nombre'], $data['correo'], $hash, 'COP', Usuario::ROL_USUARIO, true);
                    self::startSession($nuevo);
                    $this->redirigirYEnviarVerificacion('/panel.php', $nuevo);
                }
            }
        } catch (RuntimeException $error) {
            $this->failed($error, 'auth/registro', ['old' => $old], 'Crear cuenta');
        }

        View::page('auth/registro', ['old' => $old, 'errors' => $errors], 'Crear cuenta', null, 'auth');
    }

    /**
     * Redirige YA al usuario recién registrado y, después de enviar la respuesta, le manda el enlace para verificar su correo (A1): así el
     * registro no espera al servidor de correo. Si el envío falla se registra; puede pedir otro enlace desde Mi perfil.
     */
    private function redirigirYEnviarVerificacion(string $url, Usuario $usuario): never
    {
        header('Location: ' . $url);
        session_write_close();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        try {
            $this->container->verificacionCorreo()->enviar($usuario);
        } catch (\Throwable $error) {
            Logger::exception('verificacion-correo', $error);
        }

        exit;
    }

    public function logout(): never
    {
        Response::requirePost();
        reject_foreign_origin();

        // Sin sesión (caducó o ya salió) no hay token que comprobar: basta con ir a login.
        if (isset($_COOKIE[SESSION_NAME])) {
            require_csrf();
            log_out_user();
        }

        Response::redirect('/login.php');
    }

    private static function homeFor(string $rol): string
    {
        return $rol === Usuario::ROL_ADMIN ? '/admin/index.php' : '/panel.php';
    }

    private static function startSession(Usuario $usuario): void
    {
        log_in_user(['id' => $usuario->id, 'name' => $usuario->nombre, 'email' => $usuario->correo, 'role' => $usuario->rol, 'fp' => password_fingerprint($usuario->contrasenaHash)]);
    }

    private function redirectIfLoggedIn(): void
    {
        try {
            $user = current_user();
        } catch (RuntimeException) {
            return; // Sin BD se muestra el formulario; el error saldrá al enviarlo.
        }

        if ($user !== null) {
            Response::redirect(self::homeFor($user['role']));
        }
    }

    /** ¿Es un POST? Si lo es, ya pasó la comprobación de origen y de token CSRF. */
    private function isPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        reject_foreign_origin();
        require_csrf();

        return true;
    }

    /** Un campo de texto del POST; lo que no sea texto (`campo[]=x`) cuenta como vacío. */
    private static function field(string $name, bool $trim = true): string
    {
        $value = $_POST[$name] ?? '';
        $value = is_string($value) ? $value : '';

        return $trim ? trim($value) : $value;
    }

    private static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** @param array<string, mixed> $data */
    private function tooManyAttempts(string $view, array $data, string $title): never
    {
        http_response_code(429);
        header('Retry-After: 900');
        View::page($view, $data + ['error' => 'Demasiados intentos. Espera unos minutos antes de volver a intentarlo.'], $title, null, 'auth');
        exit;
    }

    /** @param array<string, mixed> $data */
    private function failed(RuntimeException $error, string $view, array $data, string $title): never
    {
        [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
        http_response_code($status);
        View::page($view, $data + ['error' => $message], $title, null, 'auth');
        exit;
    }
}
