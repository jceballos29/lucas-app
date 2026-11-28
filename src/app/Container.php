<?php

declare(strict_types=1);

namespace App;

use App\Admin\GestionUsuarios;
use App\Auth\Autenticador;
use App\Auth\LimitadorIntentos;
use App\Auth\RecuperacionClave;
use App\Cuentas\ConciliacionCuenta;
use App\Database\Connection;
use App\Deudas\GestionDeudas;
use App\Espacios\GestionEspacios;
use App\Repository\AdminStatsRepository;
use App\Repository\AporteRepository;
use App\Repository\CategoriaRepository;
use App\Repository\CuentaRepository;
use App\Repository\CuotaTarjetaRepository;
use App\Repository\DeudaRepository;
use App\Repository\EspacioRepository;
use App\Mail\MailException;
use App\Mail\Mailer;
use App\Mail\SmtpMailer;
use App\Metas\GestionMetas;
use App\Movimientos\GestionMovimientos;
use App\Movimientos\ImportadorCsv;
use App\Notificaciones\NotificadorPresupuesto;
use App\Notificaciones\ResumenMensual;
use App\Presupuestos\ClonadorPresupuestos;
use App\Recurrentes\GestionRecurrentes;
use App\Repository\IntentoRepository;
use App\Repository\MetaRepository;
use App\Repository\NotificacionRepository;
use App\Repository\MovimientoRepository;
use App\Repository\PresupuestoRepository;
use App\Repository\RecurrenteRepository;
use App\Repository\ReporteRepository;
use App\Repository\RestablecimientoRepository;
use App\Repository\SesionRepository;
use App\Repository\TasaCambioRepository;
use App\Repository\TransferenciaRepository;
use App\Repository\UsuarioRepository;
use App\Transferencias\GestionTransferencias;
use App\Usuarios\EliminarCuenta;
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
    private ?Mailer $mailer = null;
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

    public function conciliacionCuenta(): ConciliacionCuenta
    {
        return new ConciliacionCuenta($this->pdo(), $this->cuentas(), $this->movimientos(), $this->categorias());
    }

    public function categorias(): CategoriaRepository
    {
        return new CategoriaRepository($this->pdo());
    }

    public function movimientos(): MovimientoRepository
    {
        return new MovimientoRepository($this->pdo());
    }

    public function cuotasTarjeta(): CuotaTarjetaRepository
    {
        return new CuotaTarjetaRepository($this->pdo());
    }

    public function gestionMovimientos(): GestionMovimientos
    {
        return new GestionMovimientos($this->pdo(), $this->movimientos(), $this->cuentas(), $this->categorias(), $this->cuotasTarjeta());
    }

    public function importadorCsv(): ImportadorCsv
    {
        return new ImportadorCsv($this->pdo(), $this->movimientos(), $this->cuentas(), $this->categorias());
    }

    public function tasasCambio(): TasaCambioRepository
    {
        return new TasaCambioRepository($this->pdo());
    }

    public function transferencias(): TransferenciaRepository
    {
        return new TransferenciaRepository($this->pdo());
    }

    public function gestionTransferencias(): GestionTransferencias
    {
        return new GestionTransferencias($this->pdo(), $this->transferencias(), $this->cuentas(), $this->tasasCambio());
    }

    public function recurrentes(): RecurrenteRepository
    {
        return new RecurrenteRepository($this->pdo());
    }

    public function gestionRecurrentes(): GestionRecurrentes
    {
        return new GestionRecurrentes($this->pdo(), $this->recurrentes(), $this->movimientos(), $this->cuentas(), $this->categorias(), $this->notificadorPresupuesto());
    }

    public function notificaciones(): NotificacionRepository
    {
        return new NotificacionRepository($this->pdo());
    }

    /** Alertas de presupuesto por correo (Fase 20). Si el correo no está configurado, no envía nada (sin romper la petición). */
    public function notificadorPresupuesto(): NotificadorPresupuesto
    {
        try {
            $mailer = $this->mailer();
        } catch (MailException) {
            $mailer = null;
        }

        return new NotificadorPresupuesto($this->presupuestos(), $this->tasasCambio(), $this->usuarios(), $this->espacios(), $this->notificaciones(), $mailer);
    }

    /** @throws MailException Si el correo no está configurado. */
    public function resumenMensual(): ResumenMensual
    {
        return new ResumenMensual($this->reportes(), $this->presupuestos(), $this->metas(), $this->tasasCambio(), $this->notificaciones(), $this->mailer());
    }

    public function espacios(): EspacioRepository
    {
        return new EspacioRepository($this->pdo());
    }

    public function gestionEspacios(): GestionEspacios
    {
        return new GestionEspacios($this->pdo(), $this->espacios(), $this->notificaciones(), $this->mailer(), \App\Config\Environment::get('APP_ORIGIN'));
    }

    public function presupuestos(): PresupuestoRepository
    {
        return new PresupuestoRepository($this->pdo());
    }

    public function clonadorPresupuestos(): ClonadorPresupuestos
    {
        return new ClonadorPresupuestos($this->pdo(), $this->presupuestos(), $this->categorias());
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

    public function deudas(): DeudaRepository
    {
        return new DeudaRepository($this->pdo());
    }

    public function gestionDeudas(): GestionDeudas
    {
        return new GestionDeudas($this->pdo(), $this->deudas(), $this->cuentas());
    }

    public function reportes(): ReporteRepository
    {
        return new ReporteRepository($this->pdo());
    }

    /** El cliente de correo (D7), con las variables MAIL_*. En las pruebas se sustituye con {@see useMailer()}. */
    public function mailer(): Mailer
    {
        return $this->mailer ??= SmtpMailer::fromEnvironment();
    }

    public function useMailer(Mailer $mailer): void
    {
        $this->mailer = $mailer;
    }

    public function restablecimientos(): RestablecimientoRepository
    {
        return new RestablecimientoRepository($this->pdo());
    }

    public function sesiones(): SesionRepository
    {
        return new SesionRepository($this->pdo());
    }

    public function recuperacion(): RecuperacionClave
    {
        return new RecuperacionClave($this->pdo(), $this->usuarios(), $this->restablecimientos(), $this->mailer(), \App\Config\Environment::get('APP_ORIGIN'));
    }

    public function eliminarCuenta(): EliminarCuenta
    {
        return new EliminarCuenta($this->pdo(), $this->usuarios(), $this->intentos());
    }
}
