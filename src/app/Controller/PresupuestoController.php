<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Categoria;
use App\Domain\Presupuesto;
use App\Http\Failure;
use App\Http\Flash;
use App\Http\Response;
use App\Presupuestos\ReglasPresupuesto;
use App\Support\Dinero;
use App\Support\Mes;
use App\Validation\PresupuestoValidator;
use App\View\View;
use DateTimeImmutable;
use RuntimeException;

/** Presupuestos mensuales por categoría de gasto (RF-15 a RF-17): listado del mes con avisos, alta, edición del límite y baja. */
final class PresupuestoController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;
        ['mes' => $mes, 'anio' => $anio] = Mes::desde($_GET, new DateTimeImmutable('now'));
        $anterior = Mes::anterior($mes, $anio);

        try {
            $repo = $this->container->presupuestos();

            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                require_espacio_write($user);
                $this->procesarAccion($user['id'], $mes, $anio, $espacioId);
            }

            $lista = $repo->listByMonth($user['id'], $mes, $anio, $espacioId);
            $listaAnterior = $repo->listByMonth($user['id'], $anterior['mes'], $anterior['anio'], $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        $presupuestado = Dinero::sumar(array_map(static fn (Presupuesto $p): string => $p->montoLimite, $lista));
        $gastado = Dinero::sumar(array_map(static fn (Presupuesto $p): string => $p->gastado, $lista));

        View::page('app/presupuestos', [
            'mes' => $mes,
            'anio' => $anio,
            'anterior' => $anterior,
            'siguiente' => Mes::siguiente($mes, $anio),
            'presupuestos' => $lista,
            'numMesAnterior' => count($listaAnterior),
            'presupuestado' => $presupuestado,
            'gastado' => $gastado,
            'flash' => Flash::pull(),
        ], 'Presupuestos', $user, 'app', 'presupuestos');
    }

    /** Alta (sin `id`) o edición del límite (`?id=`) de un presupuesto. */
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
            $presupuesto = $id > 0 ? $this->container->presupuestos()->findOwned($user['id'], $id, $espacioId) : null;

            if ($id > 0 && $presupuesto === null) {
                Response::plain(404, "No encontrado.\n");
            }

            ['mes' => $mes, 'anio' => $anio] = $presupuesto !== null
                ? ['mes' => $presupuesto->mes, 'anio' => $presupuesto->anio]
                : Mes::desde($isPost ? $_POST : $_GET, new DateTimeImmutable('now'));
            $errors = [];
            $old = [
                'id_categoria' => '',
                'mes' => (string) $mes,
                'anio' => (string) $anio,
                'monto_limite' => $presupuesto === null ? '' : Dinero::paraCampo($presupuesto->montoLimite),
            ];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                // Al editar solo cuenta el límite: la categoría y el mes los fija el presupuesto, no el formulario.
                $input = $presupuesto === null
                    ? $_POST
                    : ['id_categoria' => (string) $presupuesto->idCategoria, 'mes' => (string) $presupuesto->mes, 'anio' => (string) $presupuesto->anio, 'monto_limite' => $_POST['monto_limite'] ?? ''];
                $result = (new PresupuestoValidator())->validate($input);
                $errors = $result['errors'];
                $data = $result['data'];
                $old = [
                    'id_categoria' => (string) ($data['id_categoria'] ?: ''),
                    'mes' => (string) ($data['mes'] ?: ''),
                    'anio' => (string) ($data['anio'] ?: ''),
                    'monto_limite' => is_string($_POST['monto_limite'] ?? null) ? trim($_POST['monto_limite']) : '',
                ];

                if ($errors === []) {
                    if ($presupuesto !== null) {
                        $this->container->presupuestos()->updateLimit($user['id'], $presupuesto->id, $data['monto_limite'], $espacioId);
                        Flash::set('ok', "Se actualizó el límite de «{$presupuesto->categoriaNombre}».");
                        Response::redirect(self::listUrl($presupuesto->mes, $presupuesto->anio));
                    }

                    $categoria = $this->container->categorias()->findById($data['id_categoria']);

                    switch (ReglasPresupuesto::evaluar($categoria, $user['id'], $espacioId)) {
                        case ReglasPresupuesto::OK:
                            if ($this->container->presupuestos()->create($user['id'], $data['id_categoria'], $data['mes'], $data['anio'], $data['monto_limite'], $espacioId) === null) {
                                $errors['id_categoria'] = "Ya tienes un presupuesto para «{$categoria?->nombre}» en " . Mes::etiqueta($data['mes'], $data['anio']) . '. Edítalo en lugar de crear otro.';
                                break;
                            }

                            Flash::set('ok', "Se creó el presupuesto de «{$categoria?->nombre}» para " . Mes::etiqueta($data['mes'], $data['anio']) . '.');
                            Response::redirect(self::listUrl($data['mes'], $data['anio']));

                        case ReglasPresupuesto::NO_ES_GASTO:
                            $errors['id_categoria'] = 'Los presupuestos son solo para categorías de gastos.';
                            break;

                        // Una categoría de otra persona o inexistente: como si no existiera (RNF-04).
                        default:
                            Response::plain(404, "No encontrado.\n");
                    }
                }
            }

            $categorias = array_values(array_filter(
                $this->container->categorias()->listVisible($user['id'], $espacioId),
                static fn (Categoria $c): bool => $c->tipo === 'gasto'
            ));
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/presupuesto-form', [
            'presupuesto' => $presupuesto,
            'old' => $old,
            'errors' => $errors,
            'categorias' => $categorias,
        ], $presupuesto === null ? 'Nuevo presupuesto' : 'Editar presupuesto', $user, 'app', 'presupuestos');
    }

    /** Asistente guiado de presupuestos con la regla 50/30/20 (Fase 12). */
    public function asistente(): void
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        ['mes' => $mes, 'anio' => $anio] = Mes::desde($isPost ? $_POST : $_GET, new DateTimeImmutable('now'));

        try {
            $categorias = array_values(array_filter(
                $this->container->categorias()->listVisible($user['id'], $espacioId),
                static fn (Categoria $c): bool => $c->tipo === 'gasto'
            ));

            // Estimar ingreso mensual a partir de los ingresos recurrentes fijos o los ingresos del mes.
            $ingresosFijos = [];
            foreach ($this->container->recurrentes()->listByUser($user['id'], $espacioId) as $r) {
                if ($r->activo && $r->esIngreso()) {
                    $ingresosFijos[] = \App\Support\Frecuencia::equivalenteMensual($r->monto, $r->frecuencia);
                }
            }
            $ingresoEstimado = Dinero::sumar($ingresosFijos);
            if (Dinero::aCentavos($ingresoEstimado) <= 0) {
                $ingresoEstimado = $this->container->reportes()->totalesMes($user['id'], $mes, $anio, $espacioId)['ingresos'];
            }
            if (Dinero::aCentavos($ingresoEstimado) <= 0) {
                $ingresoEstimado = '3000000.00';
            }

            $ingresoInput = is_string($_GET['ingreso'] ?? null) ? trim($_GET['ingreso']) : '';
            $ingresoBase = ($ingresoInput !== '' ? Dinero::parse($ingresoInput) : null) ?? $ingresoEstimado;
            if (Dinero::aCentavos($ingresoBase) <= 0) {
                $ingresoBase = $ingresoEstimado;
            }

            $distribucion = \App\Presupuestos\Asistente503020::distribuir($ingresoBase, $categorias);
            $errors = [];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $limites = is_array($_POST['limites'] ?? null) ? $_POST['limites'] : [];
                $montosValidos = [];
                foreach ($limites as $catIdRaw => $textoMonto) {
                    if (!is_int($catIdRaw) && (!is_string($catIdRaw) || !ctype_digit($catIdRaw))) {
                        continue;
                    }
                    $txt = is_string($textoMonto) ? trim($textoMonto) : '';
                    if ($txt === '' || $txt === '0') {
                        continue;
                    }
                    $parsed = Dinero::parse($txt);
                    if ($parsed === null || str_starts_with($parsed, '-')) {
                        $errors['general'] = 'Revisa los montos: todos deben ser números válidos mayores o iguales a cero.';
                        break;
                    }
                    if (Dinero::aCentavos($parsed) > 0) {
                        $montosValidos[(int) $catIdRaw] = $parsed;
                    }
                }

                if ($errors === [] && $montosValidos === []) {
                    $errors['general'] = 'Asigna un límite mayor que cero a al menos una categoría para guardar.';
                }

                if ($errors === []) {
                    $n = $this->container->clonadorPresupuestos()->guardarLote($user['id'], $mes, $anio, $montosValidos, $espacioId);
                    Flash::set('ok', "Se aplicó la regla 50/30/20 y se guardaron $n " . ($n === 1 ? 'presupuesto' : 'presupuestos') . ' para ' . Mes::etiqueta($mes, $anio) . '.');
                    Response::redirect(self::listUrl($mes, $anio));
                }
            }
        } catch (RuntimeException $error) {
            self::fail($error);
        }

        View::page('app/presupuesto-asistente', [
            'mes' => $mes,
            'anio' => $anio,
            'ingresoCampo' => Dinero::paraCampo($ingresoBase),
            'distribucion' => $distribucion,
            'errors' => $errors,
        ], 'Asistente 50/30/20', $user, 'app', 'presupuestos');
    }

    /** POST de acciones desde el listado (`eliminar` o `clonar_anterior`). */
    private function procesarAccion(int $userId, int $mes, int $anio, ?int $espacioId): never
    {
        reject_foreign_origin();
        require_csrf();

        $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

        if ($accion === 'clonar_anterior') {
            $res = $this->container->clonadorPresupuestos()->clonarDelMesAnterior($userId, $mes, $anio, $espacioId);
            if ($res['copiados'] === 0) {
                Flash::set('info', 'No había presupuestos nuevos por copiar desde el mes anterior.');
            } else {
                $n = $res['copiados'];
                Flash::set('ok', 'Se ' . ($n === 1 ? 'copió 1 presupuesto' : "copiaron $n presupuestos") . ' del mes anterior a ' . Mes::etiqueta($mes, $anio) . '.');
            }
            Response::redirect(self::listUrl($mes, $anio));
        }

        $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;

        if ($id < 1 || $accion !== 'eliminar') {
            Response::plain(400, "Solicitud no válida.\n");
        }

        $repo = $this->container->presupuestos();
        $presupuesto = $repo->findOwned($userId, $id, $espacioId);

        if ($presupuesto === null || !$repo->delete($userId, $id, $espacioId)) {
            Response::plain(404, "No encontrado.\n");
        }

        Flash::set('ok', "Se eliminó el presupuesto de «{$presupuesto->categoriaNombre}».");
        Response::redirect(self::listUrl($presupuesto->mes, $presupuesto->anio));
    }

    private static function listUrl(int $mes, int $anio): string
    {
        return '/presupuestos.php?' . http_build_query(['mes' => $mes, 'anio' => $anio]);
    }

    private static function fail(RuntimeException $error): never
    {
        [$status, $message] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
        Response::plain($status, $message . "\n");
    }
}
