<?php

declare(strict_types=1);

namespace App\Http;

/** Mensaje de una sola vez que sobrevive a una redirección (patrón PRG): se guarda en la sesión y se lee una vez. */
final class Flash
{
    public static function set(string $tone, string $message): void
    {
        start_app_session();
        $_SESSION['flash'] = ['tone' => $tone, 'message' => $message];
    }

    /** @return array{tone: string, message: string}|null */
    public static function pull(): ?array
    {
        start_app_session();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($flash) && isset($flash['tone'], $flash['message']) ? $flash : null;
    }
}
