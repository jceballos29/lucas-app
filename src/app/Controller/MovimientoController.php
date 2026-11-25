<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Response;
use App\Movimientos\Filtros;
use App\Movimientos\ReglasMovimiento;
use App\Support\Dinero;
use App\Support\Mes;
use App\Validation\MovimientoValidator;
use App\View\View;
use RuntimeException;

/** Movimientos del usuario (RF-10 a RF-14): listado con filtros y paginación, alta, edición y eliminación. */
final class MovimientoController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;
        $filtros = Filtros::desde($_GET);

        try {
            $repo = $this->container->movimientos();
            $total = $repo->count($user['id'], $filtros, $espacioId);
            $paginas = max(1, (int) ceil($total / Filtros::POR_PAGINA));
            $pagina = min($paginas, self::page($_GET['pagina'] ?? 1));
            $filas = $repo->search($user['id'], $filtros, Filtros::POR_PAGINA, ($pagina - 1) * Filtros::POR_PAGINA, $espacioId);
            $totales = $repo->totals($user['id'], $filtros, $espacioId);
            $cuentas = $this->container->cuentas()->listByUser($user['id'], $espacioId);
            $categorias = $this->container->categorias()->listVisible($user['id'], $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/movimientos', [
            'rows' => $filas,
            'filtros' => $filtros,
            'cuentas' => $cuentas,
            'categorias' => $categorias,
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => $paginas,
            'totales' => $totales,
            'volver' => Filtros::query($filtros, $pagina),
            'flash' => Flash::pull(),
        ], 'Movimientos', $user, 'app', 'movimientos');
    }

    /** Alta (sin `id`) o edición (`?id=`) de un movimiento. */
    public function form(): void
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $raw = $isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '');
        $id = is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;

        if ($raw !== '' && $id < 1) {
            Response::plain(404, "No encontrado.\n");
        }

        try {
            $movimiento = $id > 0 ? $this->container->movimientos()->findOwned($user['id'], $id, $espacioId) : null;

            if ($id > 0 && $movimiento === null) {
                Response::plain(404, "No encontrado.\n");
            }

            $errors = [];
            $old = $movimiento === null
                ? ['tipo' => 'gasto', 'monto' => '', 'fecha' => date('Y-m-d'), 'id_cuenta' => '', 'id_categoria' => '', 'descripcion' => '']
                : [
                    'tipo' => $movimiento->tipo,
                    'monto' => Dinero::paraCampo($movimiento->monto),
                    'fecha' => $movimiento->fecha,
                    'id_cuenta' => (string) $movimiento->idCuenta,
                    'id_categoria' => (string) $movimiento->idCategoria,
                    'descripcion' => $movimiento->descripcion ?? '',
                ];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $result = (new MovimientoValidator())->validate($_POST);
                $errors = $result['errors'];
                $old = [
                    'tipo' => $result['data']['tipo'],
                    'monto' => is_string($_POST['monto'] ?? null) ? trim($_POST['monto']) : '',
                    'fecha' => is_string($_POST['fecha'] ?? null) ? trim($_POST['fecha']) : '',
                    'id_cuenta' => (string) ($result['data']['id_cuenta'] ?: ''),
                    'id_categoria' => (string) ($result['data']['id_categoria'] ?: ''),
                    'descripcion' => $result['data']['descripcion'] ?? '',
                ];

                if ($errors === []) {
                    $saved = $this->container->gestionMovimientos()->guardar($user['id'], $movimiento?->id, $result['data'], $espacioId);

                    switch ($saved['codigo']) {
                        case ReglasMovimiento::OK:
                            $data = $result['data'];
                            $mensaje = $movimiento === null
                                ? 'Se registró ' . ($data['tipo'] === 'ingreso' ? 'el ingreso' : 'el gasto') . ' de ' . Dinero::formatear($data['monto']) . " en «{$saved['cuenta']}»."
                                : 'Se guardaron los cambios del movimiento.';
                            // RF-17: si el gasto deja la categoría cerca o por encima de su presupuesto del mes, se avisa aquí mismo.
                            $aviso = $data['tipo'] === 'gasto' ? $this->avisoPresupuesto($user['id'], $data['id_categoria'], $data['fecha'], $espacioId) : null;
                            Flash::set($aviso === null ? 'ok' : 'warn', $aviso === null ? $mensaje : "$mensaje $aviso");
                            Response::redirect('/movimientos.php');

                        case ReglasMovimiento::TIPO_DISCORDANTE:
                            $errors['id_categoria'] = 'Esa categoría no es de ' . ($result['data']['tipo'] === 'ingreso' ? 'ingresos' : 'gastos') . '. Elige otra o cambia el tipo.';
                            break;

                        case ReglasMovimiento::CUENTA_INACTIVA:
                            $errors['id_cuenta'] = "La cuenta «{$saved['cuenta']}» está inactiva. Actívala en Cuentas o elige otra.";
                            break;

                        case ReglasMovimiento::SALDO_INSUFICIENTE:
                            $disponible = Dinero::formatear((string) ($saved['saldo'] ?? '0.00'));
                            $errors['monto'] = "La cuenta «{$saved['cuenta']}» tiene $disponible disponibles: no alcanza para registrar este movimiento sin dejarla en negativo.";
                            break;

                        // Un movimiento, una cuenta o una categoría que no son tuyos: como si no existieran (RNF-04).
                        default:
                            Response::plain(404, "No encontrado.\n");
                    }
                }
            }

            // Cuentas activas; si el movimiento está en una que ya no lo es, también esa (marcada), para que el formulario diga la verdad.
            $cuentas = array_values(array_filter(
                $this->container->cuentas()->listByUser($user['id'], $espacioId),
                static fn (Cuenta $c): bool => $c->activa || (string) $c->id === $old['id_cuenta']
            ));
            $categorias = $this->container->categorias()->listVisible($user['id'], $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/movimiento-form', [
            'movimiento' => $movimiento,
            'old' => $old,
            'errors' => $errors,
            'cuentas' => $cuentas,
            'categorias' => $categorias,
        ], $movimiento === null ? 'Nuevo movimiento' : 'Editar movimiento', $user, 'app', 'movimientos');
    }

    /** POST de eliminación desde el listado; vuelve a la misma página de resultados. */
    public function eliminar(): never
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        Response::requirePost();
        reject_foreign_origin();
        require_csrf();

        $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;

        if ($id < 1) {
            Response::plain(400, "Solicitud no válida.\n");
        }

        try {
            $borrado = $this->container->gestionMovimientos()->eliminar($user['id'], $id, $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        if (!$borrado) {
            Response::plain(404, "No encontrado.\n");
        }

        // Se vuelve a los mismos filtros y página: solo se aceptan los parámetros conocidos y ya limpios.
        parse_str(is_string($_POST['volver'] ?? null) ? $_POST['volver'] : '', $previos);
        $filtros = Filtros::desde($previos);
        $query = Filtros::query($filtros, self::page($previos['pagina'] ?? 1));

        Flash::set('ok', 'Se eliminó el movimiento.');
        Response::redirect('/movimientos.php' . ($query !== '' ? '?' . $query : ''));
    }

    /**
     * El aviso de RF-17 para un gasto de esa categoría y fecha, o null si no hay presupuesto o está en orden (menos del
     * 80 %). Se calcula al momento, no se guarda.
     */
    private function avisoPresupuesto(int $userId, int $categoriaId, string $fecha, ?int $espacioId = null): ?string
    {
        $mes = Mes::deFecha($fecha);
        $presupuesto = $mes === null ? null : $this->container->presupuestos()->findForCategory($userId, $categoriaId, $mes['mes'], $mes['anio'], $espacioId);

        if ($presupuesto === null) {
            return null;
        }

        $pr = $presupuesto->progreso();
        $gastado = Dinero::formatear($presupuesto->gastado);
        $limite = Dinero::formatear($presupuesto->montoLimite);

        return match ($pr['estado']) {
            'cerca' => "Aviso: llevas el {$pr['percent']} % del presupuesto de «{$presupuesto->categoriaNombre}» ($gastado de $limite).",
            'limite' => "Aviso: llegaste al límite del presupuesto de «{$presupuesto->categoriaNombre}» ($limite).",
            'excedido' => "Aviso: superaste el presupuesto de «{$presupuesto->categoriaNombre}» ($gastado de $limite).",
            default => null,
        };
    }

    private static function page(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) ? max(1, (int) $value) : 1;
    }

    private static function fail(RuntimeException $error): never
    {
        [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
        Response::plain($status, $message . "\n");
    }
}
