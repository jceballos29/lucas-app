<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\ReglasAdmin;
use App\Container;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Peticion;
use App\Http\Response;
use App\Security\AdminSemilla;
use App\View\Fecha;
use App\View\View;
use DateTimeImmutable;
use RuntimeException;

/**
 * Dashboard de administración y gestión de usuarios (RF-28 a RF-33). Toda acción exige sesión Y rol `admin`
 * (`require_role`, RNF-22) y solo muestra agregados y datos de cuenta, nunca movimientos ni saldos (RNF-21).
 */
final class AdminController
{
    private const POR_PAGINA = 20;
    private const BUSQUEDA_MAX = 80;

    public function __construct(private readonly Container $container)
    {
    }

    public function dashboard(): void
    {
        $user = require_role('admin');

        try {
            $stats = $this->container->adminStats();
            $totals = $stats->totals();
            $registrations = array_map(
                static fn (array $m): array => ['label' => Fecha::mesCorto($m['month']), 'value' => $m['value']],
                $stats->registrationsByMonth(12),
            );
            $latest = $stats->latestUsers(4);
            $semillaExpuesta = AdminSemilla::expuesto($this->container->usuarios()->findByEmail(AdminSemilla::CORREO), (string) getenv('APP_ENV'));
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('admin/index', [
            'totals' => $totals,
            'registrations' => $registrations,
            'latest' => $latest,
            'month' => Fecha::mesAnio(new DateTimeImmutable('now')),
            'semillaExpuesta' => $semillaExpuesta,
        ], 'Dashboard', $user, 'admin', 'dashboard');
    }

    public function usuarios(): void
    {
        $user = require_role('admin');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                $this->change($user['id']);
            }

            $usuarios = $this->container->usuarios();
            $q = self::query($_GET['q'] ?? '');
            $total = $usuarios->countSearch($q);
            $pages = max(1, (int) ceil($total / self::POR_PAGINA));
            $page = min($pages, self::page($_GET['pagina'] ?? 1));
            $rows = $usuarios->search($q, self::POR_PAGINA, ($page - 1) * self::POR_PAGINA);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('admin/usuarios', [
            'rows' => $rows,
            'actorId' => $user['id'],
            'q' => $q,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'flash' => Flash::pull(),
        ], 'Usuarios', $user, 'admin', 'usuarios');
    }

    /** Procesa un POST de activar/desactivar o cambiar rol y vuelve a la lista (PRG). */
    private function change(int $actorId): never
    {
        reject_foreign_origin();
        require_csrf();

        $id = Peticion::postEntero('id');
        $accion = Peticion::postSinRecortar('accion');

        if ($id < 1 || !in_array($accion, ReglasAdmin::ACCIONES, true)) {
            Response::plain(400, "Solicitud no válida.\n");
        }

        $resultado = $this->container->gestionUsuarios()->aplicar($actorId, $id, $accion);
        $nombre = $resultado['nombre'] ?? '';

        if ($resultado['codigo'] === ReglasAdmin::SIN_PERMISO) {
            Response::plain(403, "Acceso denegado: ya no eres un administrador activo.\n");
        }

        [$tone, $message] = match ($resultado['codigo']) {
            ReglasAdmin::OK => ['ok', match ($accion) {
                'activar' => "Se activó la cuenta de $nombre.",
                'desactivar' => "Se desactivó la cuenta de $nombre. Ya no puede iniciar sesión; sus datos se conservan.",
                'hacer_admin' => "$nombre ahora es administrador.",
                'quitar_admin' => "$nombre ya no es administrador.",
            }],
            ReglasAdmin::PROPIO => ['error', 'No puedes desactivar tu propia cuenta ni quitarte el rol de administrador.'],
            ReglasAdmin::ULTIMO_ADMIN => ['error', 'Debe quedar al menos un administrador activo.'],
            ReglasAdmin::NO_EXISTE => ['error', 'Ese usuario ya no existe.'],
            default => ['info', 'No hubo cambios: la cuenta ya estaba así.'],
        };

        Flash::set($tone, $message);
        Response::redirect(self::listUrl(self::query($_POST['q'] ?? ''), self::page($_POST['pagina'] ?? 1)));
    }

    /** El texto de búsqueda: recortado y con un largo máximo. Lo que no sea texto cuenta como vacío. */
    private static function query(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, self::BUSQUEDA_MAX) : '';
    }

    private static function page(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) ? max(1, (int) $value) : 1;
    }

    private static function listUrl(string $q, int $page): string
    {
        $params = array_filter(['q' => $q, 'pagina' => $page > 1 ? (string) $page : ''], static fn (string $v): bool => $v !== '');

        return '/admin/usuarios.php' . ($params === [] ? '' : '?' . http_build_query($params));
    }

    private static function fail(RuntimeException $error): never
    {
        [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
        Response::plain($status, $message . "\n");
    }
}
