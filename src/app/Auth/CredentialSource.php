<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Usuario;

/** Lo que el Autenticador necesita de los datos: buscar por correo y guardar un hash nuevo. */
interface CredentialSource
{
    public function findByEmail(string $correo): ?Usuario;

    public function updatePasswordHash(int $id, string $hash): void;
}
