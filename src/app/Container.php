<?php

declare(strict_types=1);

namespace App;

use App\Database\Connection;
use PDO;

/**
 * Construye y entrega las piezas de la aplicación, cada una con sus dependencias (el único sitio que sabe qué
 * lleva qué). La conexión a la BD es PEREZOSA: no se abre hasta que alguien la pide. Importa porque hay peticiones
 * que se rechazan sin tocar la BD (un id mal formado da 400 aunque la BD esté caída).
 *
 * Es un contenedor mínimo, hecho a mano. Al migrar cada módulo de Lucas se añade aquí su repositorio, con el mismo
 * patrón:
 *
 *     private ?CuentaRepository $cuentas = null;
 *     public function cuentas(): CuentaRepository { return $this->cuentas ??= new CuentaRepository($this->pdo()); }
 */
final class Container
{
    private ?PDO $pdo = null;

    /** La conexión (se abre la primera vez). @throws \App\Exceptions\DatabaseConnectionException */
    public function pdo(): PDO
    {
        return $this->pdo ??= Connection::create();
    }
}
