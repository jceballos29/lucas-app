<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\View\View;

/**
 * TEMPORAL: muestra las pantallas pendientes con datos de ejemplo y SIN funcionalidad (no guardan nada), para validar la
 * interfaz antes de construirla de verdad. Desde la Fase 1 SÍ exigen sesión (`require_login()`). Cada método se reemplaza, fase a fase, por su controlador real
 * (PanelController, CuentaController...) y al terminar la migración esta clase se borra.
 *
 * Los POST solo comprueban origen y CSRF y redirigen para poder recorrer el flujo: no guardan nada.
 */
final class PreviewController
{
    public function recover(): void
    {
        $notice = $this->isPost() ? 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.' : null;

        View::page('auth/recuperar', ['notice' => $notice], 'Recuperar contraseña', null, 'auth');
    }

    /** Páginas del usuario: `$view` es el nombre de la vista en views/app/ y a la vez la clave del menú. */
    public function app(string $view, string $title, ?string $nav = null): void
    {
        $user = require_login();

        if ($this->isPost()) {
            Response::redirect('/' . $view . '.php');
        }

        View::page("app/$view", [], $title, $user, 'app', $nav ?? $view);
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
