<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\Cuenta;
use App\Http\Flash;
use App\Http\Peticion;
use App\Http\Response;
use App\Movimientos\Filtros;
use App\Movimientos\FormularioMovimiento;
use App\Movimientos\ReglasMovimiento;
use App\Support\AlmacenAdjuntos;
use App\Support\Dinero;
use App\View\View;

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

        $repo = $this->container->movimientos();
        $monedaBase = Dinero::monedaActual();
        $tasas = $this->container->tasasCambio()->listMap($user['id'], $espacioId);
        $total = $repo->count($user['id'], $filtros, $espacioId);
        $paginas = max(1, (int) ceil($total / Filtros::POR_PAGINA));
        $pagina = min($paginas, self::page($_GET['pagina'] ?? 1));
        $filas = $repo->search($user['id'], $filtros, Filtros::POR_PAGINA, ($pagina - 1) * Filtros::POR_PAGINA, $espacioId);
        $totales = $repo->totals($user['id'], $filtros, $espacioId, $monedaBase, $tasas);
        $etiquetas = $repo->listEtiquetas($user['id'], $espacioId);
        $cuentas = $this->container->cuentas()->listByUser($user['id'], $espacioId);
        $categorias = $this->container->categorias()->listVisible($user['id'], $espacioId);

        View::page('app/movimientos', [
            'rows' => $filas,
            'filtros' => $filtros,
            'etiquetas' => $etiquetas,
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

    /** Alta (sin `id`) o edición (`?id=`) de un movimiento. La lectura del formulario y el guardado viven en `Movimientos\\*` (R1). */
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

        $movimiento = $id > 0 ? $this->container->movimientos()->findOwned($user['id'], $id, $espacioId) : null;

        if ($id > 0 && $movimiento === null) {
            Response::plain(404, "No encontrado.\n");
        }

        $errors = [];
        $splitOld = FormularioMovimiento::filasDivisionVacias();
        $old = FormularioMovimiento::inicial($movimiento);

        if ($isPost) {
            reject_foreign_origin();
            require_csrf();

            $envio = FormularioMovimiento::leer($_POST, is_array($_FILES['comprobante'] ?? null) ? $_FILES['comprobante'] : null, $movimiento !== null);
            [$old, $splitOld, $errors] = [$envio->old, $envio->splitOld, $envio->errors];

            if ($envio->valido()) {
                $res = $this->container->guardarMovimiento()->guardar($user['id'], $movimiento, $envio, $espacioId);

                if ($res->noEncontrado) {
                    Response::plain(404, "No encontrado.\n");
                }

                if ($res->ok) {
                    Flash::set($res->tono, $res->mensaje);
                    Response::redirect('/movimientos.php');
                }

                $errors = $res->errors;
            }
        }

        // Cuentas activas; si el movimiento está en una que ya no lo es, también esa (marcada), para que el formulario diga la verdad.
        $cuentas = array_values(array_filter(
            $this->container->cuentas()->listByUser($user['id'], $espacioId),
            static fn (Cuenta $c): bool => $c->activa || (string) $c->id === $old['id_cuenta'],
        ));
        $categorias = $this->container->categorias()->listVisible($user['id'], $espacioId);

        View::page('app/movimiento-form', [
            'movimiento' => $movimiento,
            'old' => $old,
            'splitOld' => $splitOld,
            'errors' => $errors,
            'cuentas' => $cuentas,
            'categorias' => $categorias,
        ], $movimiento === null ? 'Nuevo movimiento' : 'Editar movimiento', $user, 'app', 'movimientos');
    }

    /** Descarga/visualización autenticada de un comprobante guardado fuera de `public/` (Fase 19). */
    public function adjunto(): never
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;
        $raw = $_GET['id'] ?? '';
        $id = is_string($raw) && ctype_digit($raw) && strlen($raw) < 10 ? (int) $raw : 0;

        if ($id < 1) {
            Response::plain(404, "No encontrado.\n");
        }

        $mov = $this->container->movimientos()->findOwned($user['id'], $id, $espacioId);

        if ($mov === null || !$mov->tieneAdjunto()) {
            Response::plain(404, "No encontrado.\n");
        }

        $abs = AlmacenAdjuntos::rutaLegible($mov->adjuntoRuta);
        if ($abs === null) {
            Response::plain(404, "No encontrado.\n");
        }

        $mime = $mov->adjuntoMime !== null && isset(AlmacenAdjuntos::MIMES_PERMITIDOS[$mov->adjuntoMime])
            ? $mov->adjuntoMime
            : 'application/octet-stream';
        // M6: un PDF puede llevar JavaScript y se abriría en el origen de la aplicación: siempre se descarga. Las imágenes se muestran,
        // pero con una CSP que no deja ejecutar ni cargar nada (sandbox sin permisos) aunque el archivo fuera algo más que una imagen.
        $descargar = ($_GET['descargar'] ?? '') === '1' || $mime === 'application/pdf';
        $nombreDescarga = AlmacenAdjuntos::nombreSeguroDescarga($mov->adjuntoNombre ?? 'comprobante', $mime);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($abs));
        header('Content-Disposition: ' . ($descargar ? 'attachment' : 'inline') . '; filename="' . $nombreDescarga . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cache-Control: private, no-store');
        readfile($abs);
        exit;
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

        $id = Peticion::postEntero('id');

        if ($id < 1) {
            Response::plain(400, "Solicitud no válida.\n");
        }

        $borrado = $this->container->gestionMovimientos()->eliminar($user['id'], $id, $espacioId);

        if (!$borrado) {
            Response::plain(404, "No encontrado.\n");
        }

        // Se vuelve a los mismos filtros y página: solo se aceptan los parámetros conocidos y ya limpios.
        parse_str(Peticion::postSinRecortar('volver'), $previos);
        $filtros = Filtros::desde($previos);
        $query = Filtros::query($filtros, self::page($previos['pagina'] ?? 1));

        Flash::set('ok', 'Se eliminó el movimiento.');
        Response::redirect('/movimientos.php' . ($query !== '' ? '?' . $query : ''));
    }

    /** Importación asistida de movimientos desde archivo o texto CSV (Fase 13). */
    public function importar(): void
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

        $cuentas = array_values(array_filter(
            $this->container->cuentas()->listByUser($user['id'], $espacioId),
            static fn (Cuenta $c): bool => $c->activa,
        ));
        $categorias = $this->container->categorias()->listVisible($user['id'], $espacioId);

        $catGastoDef = 0;
        $catIngresoDef = 0;
        foreach ($categorias as $k) {
            if ($k->tipo === 'gasto' && ($catGastoDef === 0 || mb_strtolower($k->nombre) === 'otros gastos')) {
                $catGastoDef = $k->id;
            }
            if ($k->tipo === 'ingreso' && ($catIngresoDef === 0 || mb_strtolower($k->nombre) === 'otros ingresos')) {
                $catIngresoDef = $k->id;
            }
        }

        $errors = [];
        $erroresCsv = [];
        $preview = null;
        $old = [
            'id_cuenta' => isset($cuentas[0]) ? (string) $cuentas[0]->id : '',
            'id_categoria_gasto' => (string) $catGastoDef,
            'id_categoria_ingreso' => (string) $catIngresoDef,
            'csv_texto' => '',
        ];

        if ($isPost) {
            reject_foreign_origin();
            require_csrf();

            $cuentaId = Peticion::postEntero('id_cuenta');
            $catGastoId = Peticion::postEntero('id_categoria_gasto');
            $catIngresoId = Peticion::postEntero('id_categoria_ingreso');
            $csvTexto = Peticion::post('csv_texto');

            // Si subió un archivo .csv, usar su contenido si es válido.
            if (isset($_FILES['csv_archivo']) && is_array($_FILES['csv_archivo']) && ($_FILES['csv_archivo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmpName = (string) ($_FILES['csv_archivo']['tmp_name'] ?? '');
                $size = (int) ($_FILES['csv_archivo']['size'] ?? 0);
                if ($size > 0 && $size <= 512_000 && is_uploaded_file($tmpName)) {
                    $csvTexto = trim((string) file_get_contents($tmpName));
                }
            }

            $old = [
                'id_cuenta' => (string) ($cuentaId ?: ''),
                'id_categoria_gasto' => (string) ($catGastoId ?: ''),
                'id_categoria_ingreso' => (string) ($catIngresoId ?: ''),
                'csv_texto' => $csvTexto,
            ];

            if ($cuentaId < 1) {
                $errors['id_cuenta'] = 'Elige la cuenta donde se importarán los movimientos.';
            }
            if ($catGastoId < 1) {
                $errors['id_categoria_gasto'] = 'Elige la categoría por defecto para los gastos.';
            }
            if ($catIngresoId < 1) {
                $errors['id_categoria_ingreso'] = 'Elige la categoría por defecto para los ingresos.';
            }
            if ($csvTexto === '') {
                $errors['csv_texto'] = 'Pega las líneas CSV o selecciona un archivo .csv.';
            }

            // M8/U4: primero una VISTA PREVIA; solo «confirmar» guarda. «editar» vuelve al formulario con el texto.
            $accion = Peticion::postSinRecortar('accion', 'previsualizar');

            if ($accion === 'editar') {
                $errors = [];
            } elseif ($errors === []) {
                $parsed = \App\Movimientos\ImportadorCsv::parsearTexto($csvTexto);
                if ($parsed['errores'] !== []) {
                    $n = count($parsed['errores']);
                    // Se listan todos los problemas con su línea, hasta 50 (un archivo basura de 500 KB no debe generar una página gigante).
                    $erroresCsv = array_slice($parsed['errores'], 0, 50);
                    if ($n > 50) {
                        $erroresCsv[] = '… y ' . ($n - 50) . ' problemas más.';
                    }
                    $errors['csv_texto'] = $n === 1 ? 'Hay 1 línea con problemas. Corrígela y vuelve a previsualizar.' : "Hay $n líneas con problemas. Corrígelas y vuelve a previsualizar.";
                } elseif ($parsed['filas'] === []) {
                    $errors['csv_texto'] = 'No se encontraron filas de movimientos válidas en el CSV.';
                } else {
                    $importador = $this->container->importadorCsv();
                    $preview = $importador->vistaPrevia($user['id'], $cuentaId, $parsed['filas'], $espacioId);

                    if ($preview === null) {
                        Response::plain(404, "No encontrado.\n");
                    }

                    $preview['filas'] = $parsed['filas'];
                    $preview['truncado'] = $parsed['truncado'];

                    if ($accion === 'confirmar') {
                        $marcadas = [];
                        foreach (is_array($_POST['incluir'] ?? null) ? $_POST['incluir'] : [] as $l) {
                            if (is_string($l) && ctype_digit($l) && strlen($l) < 9) {
                                $marcadas[(int) $l] = true;
                            }
                        }
                        $elegidas = array_values(array_filter($parsed['filas'], static fn (array $f): bool => isset($marcadas[$f['linea']])));
                        $preview['marcadas'] = array_keys($marcadas);

                        if ($elegidas === []) {
                            $errors['incluir'] = 'Marca al menos un movimiento para importarlo.';
                        } else {
                            $res = $importador->importar($user['id'], $cuentaId, $catGastoId, $catIngresoId, $elegidas, $espacioId);

                            switch ($res['codigo']) {
                                case ReglasMovimiento::OK:
                                    $n = $res['importados'];
                                    $omitidas = count($parsed['filas']) - $n;
                                    Flash::set('ok', 'Se ' . ($n === 1 ? 'importó 1 movimiento' : "importaron $n movimientos") . " en «{$res['cuenta']}»."
                                        . ($omitidas > 0 ? ' Se omitieron ' . $omitidas . ($omitidas === 1 ? ' fila.' : ' filas.') : ''));
                                    Response::redirect('/movimientos.php');

                                    // no break
                                case ReglasMovimiento::SALDO_INSUFICIENTE:
                                    $disp = Dinero::formatear((string) ($res['saldo'] ?? '0.00'));
                                    $errors['incluir'] = "La cuenta «{$res['cuenta']}» tiene $disp disponibles y al aplicar los movimientos marcados quedaría en saldo negativo.";
                                    break;

                                default:
                                    Response::plain(404, "No encontrado.\n");
                            }
                        }
                    }
                }
            }
        }

        View::page('app/movimientos-importar', [
            'cuentas' => $cuentas,
            'categorias' => $categorias,
            'old' => $old,
            'errors' => $errors,
            'erroresCsv' => $erroresCsv,
            'preview' => $preview,
        ], 'Importar movimientos CSV', $user, 'app', 'movimientos');
    }

    private static function page(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) ? max(1, (int) $value) : 1;
    }

}
