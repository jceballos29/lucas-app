<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\View\View;

/**
 * TEMPORAL: muestra todas las pantallas con datos de ejemplo y SIN funcionalidad (ni BD, ni sesión real, ni permisos),
 * para validar la interfaz antes de construirla de verdad. Cada método se reemplaza, fase a fase, por su controlador real
 * (AuthController, PanelController, AdminController...) y al terminar la migración esta clase se borra.
 *
 * Los POST solo comprueban origen y CSRF y redirigen para poder recorrer el flujo: no guardan nada.
 */
final class PreviewController
{
    /** @var array{id: int, name: string, email: string} */
    private const DEMO_USER = ['id' => 0, 'name' => 'Juan Ceballos', 'email' => 'juan@correo.com'];

    /** @var array{id: int, name: string, email: string} */
    private const DEMO_ADMIN = ['id' => 0, 'name' => 'Administrador', 'email' => 'admin@lucas.local'];

    public function login(): void
    {
        if ($this->isPost()) {
            $email = strtolower(trim((string) ($_POST['correo'] ?? '')));
            Response::redirect(str_contains($email, 'admin') ? '/admin/index.php' : '/panel.php');
        }

        View::page('auth/login', [], 'Iniciar sesión', null, 'auth');
    }

    public function register(): void
    {
        if ($this->isPost()) {
            Response::redirect('/login.php');
        }

        View::page('auth/registro', [], 'Crear cuenta', null, 'auth');
    }

    public function recover(): void
    {
        $notice = $this->isPost() ? 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.' : null;

        View::page('auth/recuperar', ['notice' => $notice], 'Recuperar contraseña', null, 'auth');
    }

    public function logout(): void
    {
        if ($this->isPost()) {
            Response::redirect('/login.php');
        }

        Response::plain(405, "Método no permitido. Usa POST.\n", ['Allow: POST']);
    }

    /** Páginas del usuario: `$view` es el nombre de la vista en views/app/ y a la vez la clave del menú. */
    public function app(string $view, string $title, ?string $nav = null): void
    {
        if ($this->isPost()) {
            Response::redirect('/' . ($view === 'movimiento-form' ? 'movimientos' : $view) . '.php');
        }

        View::page("app/$view", [], $title, self::DEMO_USER, 'app', $nav ?? $view);
    }

    /** Páginas del administrador: `$view` es el nombre en views/admin/ y la clave del menú. */
    public function admin(string $view, string $title, string $nav): void
    {
        if ($this->isPost()) {
            Response::redirect($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
        }

        View::page("admin/$view", [], $title, self::DEMO_ADMIN, 'admin', $nav);
    }

    private function isPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        reject_foreign_origin();
        require_csrf();

        return true;
    }
}
