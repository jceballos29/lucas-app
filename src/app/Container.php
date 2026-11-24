<?php

declare(strict_types=1);

namespace App;

use App\Admin\GestionUsuarios;
use App\Auth\Autenticador;
use App\Auth\LimitadorIntentos;
use App\Database\Connection;
use App\Repository\AdminStatsRepository;
use App\Repository\AporteRepository;
use App\Repository\CategoriaRepository;
use App\Repository\CuentaRepository;
use App\Metas\GestionMetas;
use App\Movimientos\GestionMovimientos;
use App\Repository\IntentoRepository;
use App\Repository\MetaRepository;
use App\Repository\MovimientoRepository;
use App\Repository\PresupuestoRepository;
use App\Repository\UsuarioRepository;
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
    private static ?self $shared = null;

    private ?PDO $pdo = null;
    private ?UsuarioRepository $usuarios = null;
    private ?IntentoRepository $intentos = null;

    /**
     * El contenedor de la petición en curso. Lo usan las páginas y la sesión (que comprueba el usuario en la BD en
     * cada petición): así toda la petición comparte UNA conexión. Las pruebas construyen el suyo con `new`.
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /** La conexión (se abre la primera vez). @throws \App\Exceptions\DatabaseConnectionException */
    public function pdo(): PDO
    {
        return $this->pdo ??= Connection::create();
    }

    public function usuarios(): UsuarioRepository
    {
        return $this->usuarios ??= new UsuarioRepository($this->pdo());
    }

    public function intentos(): IntentoRepository
    {
        return $this->intentos ??= new IntentoRepository($this->pdo());
    }

    public function autenticador(): Autenticador
    {
        return new Autenticador($this->usuarios());
    }

    public function limitador(): LimitadorIntentos
    {
        return new LimitadorIntentos($this->intentos());
    }

    public function adminStats(): AdminStatsRepository
    {
        return new AdminStatsRepository($this->pdo());
    }

    public function gestionUsuarios(): GestionUsuarios
    {
        return new GestionUsuarios($this->usuarios());
    }

    public function cuentas(): CuentaRepository
    {
        return new CuentaRepository($this->pdo());
    }

    public function categorias(): CategoriaRepository
    {
        return new CategoriaRepository($this->pdo());
    }

    public function movimientos(): MovimientoRepository
    {
        return new MovimientoRepository($this->pdo());
    }

    public function gestionMovimientos(): GestionMovimientos
    {
        return new GestionMovimientos($this->pdo(), $this->movimientos(), $this->cuentas(), $this->categorias());
    }

    public function presupuestos(): PresupuestoRepository
    {
        return new PresupuestoRepository($this->pdo());
    }

    public function metas(): MetaRepository
    {
        return new MetaRepository($this->pdo());
    }

    public function aportes(): AporteRepository
    {
        return new AporteRepository($this->pdo());
    }

    public function gestionMetas(): GestionMetas
    {
        return new GestionMetas($this->pdo(), $this->metas(), $this->aportes(), $this->cuentas());
    }
}
