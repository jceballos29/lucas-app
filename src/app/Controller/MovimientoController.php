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
use App\Support\AlmacenAdjuntos;
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
        } catch (RuntimeException $error) {
            self::fail($error);
        }

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
            $splitOld = [
                ['id_categoria' => '', 'monto' => '', 'descripcion' => ''],
                ['id_categoria' => '', 'monto' => '', 'descripcion' => ''],
                ['id_categoria' => '', 'monto' => '', 'descripcion' => ''],
                ['id_categoria' => '', 'monto' => '', 'descripcion' => ''],
            ];
            $old = $movimiento === null
                ? ['tipo' => 'gasto', 'monto' => '', 'fecha' => date('Y-m-d'), 'id_cuenta' => '', 'id_categoria' => '', 'descripcion' => '', 'etiqueta' => '', 'num_cuotas' => '1', 'con_intereses' => '1']
                : [
                    'tipo' => $movimiento->tipo,
                    'monto' => Dinero::paraCampo($movimiento->monto),
                    'fecha' => $movimiento->fecha,
                    'id_cuenta' => (string) $movimiento->idCuenta,
                    'id_categoria' => (string) $movimiento->idCategoria,
                    'descripcion' => $movimiento->descripcion ?? '',
                    'etiqueta' => $movimiento->etiqueta ?? '',
                    'num_cuotas' => (string) $movimiento->numCuotas,
                    'con_intereses' => $movimiento->conIntereses ? '1' : '0',
                ];

            if ($isPost) {
                reject_foreign_origin();
                require_csrf();

                $subida = AlmacenAdjuntos::validarSubida(is_array($_FILES['comprobante'] ?? null) ? $_FILES['comprobante'] : null);

                $catsSplit = is_array($_POST['split_categoria'] ?? null) ? $_POST['split_categoria'] : [];
                $montosSplit = is_array($_POST['split_monto'] ?? null) ? $_POST['split_monto'] : [];
                $descsSplit = is_array($_POST['split_descripcion'] ?? null) ? $_POST['split_descripcion'] : [];

                $usaSplit = false;
                for ($i = 0; $i < 4; $i++) {
                    $cVal = is_string($catsSplit[$i] ?? null) ? trim($catsSplit[$i]) : '';
                    $mVal = is_string($montosSplit[$i] ?? null) ? trim($montosSplit[$i]) : '';
                    $dVal = is_string($descsSplit[$i] ?? null) ? trim($descsSplit[$i]) : '';
                    $splitOld[$i] = ['id_categoria' => $cVal, 'monto' => $mVal, 'descripcion' => $dVal];
                    if ($cVal !== '' || $mVal !== '') {
                        $usaSplit = true;
                    }
                }

                if ($movimiento === null && ($usaSplit || ($_POST['dividir'] ?? '') === '1')) {
                    $splitRes = (new MovimientoValidator())->validateSplit($_POST);
                    $errors = $splitRes['errors'];
                    if (!$subida['ok'] && $subida['error'] !== null) {
                        $errors['comprobante'] = $subida['error'];
                    }
                    $old = [
                        'tipo' => $splitRes['base']['tipo'],
                        'monto' => is_string($_POST['monto'] ?? null) ? trim($_POST['monto']) : '',
                        'fecha' => is_string($_POST['fecha'] ?? null) ? trim($_POST['fecha']) : '',
                        'id_cuenta' => (string) ($splitRes['base']['id_cuenta'] ?: ''),
                        'id_categoria' => '',
                        'descripcion' => $splitRes['base']['descripcion'] ?? '',
                        'etiqueta' => is_string($_POST['etiqueta'] ?? null) ? trim($_POST['etiqueta']) : '',
                        'num_cuotas' => (string) ($splitRes['base']['num_cuotas'] ?? 1),
                        'con_intereses' => ($splitRes['base']['con_intereses'] ?? true) ? '1' : '0',
                    ];

                    if ($errors === []) {
                        $saved = $this->container->gestionMovimientos()->guardarDividido(
                            $user['id'],
                            $splitRes['base'],
                            $splitRes['partes'],
                            $espacioId,
                        );

                        switch ($saved['codigo']) {
                            case ReglasMovimiento::OK:
                                if (!$subida['vacio'] && $subida['tmp'] !== null && $subida['mime'] !== null && isset($saved['ids'][0])) {
                                    $guardado = AlmacenAdjuntos::guardarDesdeArchivo($subida['tmp'], $subida['mime'], (string) $subida['nombre']);
                                    $this->container->movimientos()->actualizarAdjunto($user['id'], (int) $saved['ids'][0], $guardado['ruta'], $guardado['nombre'], $guardado['mime'], $espacioId);
                                }
                                $base = $splitRes['base'];
                                $nPartes = count($splitRes['partes']);
                                $mensaje = 'Se registró ' . ($base['tipo'] === 'ingreso' ? 'el ingreso dividido' : 'el gasto dividido')
                                    . " ($nPartes categorías) por " . Dinero::formatear($base['monto_total']) . " en «{$saved['cuenta']}».";
                                $avisos = [];
                                if ($base['tipo'] === 'gasto') {
                                    foreach ($splitRes['partes'] as $p) {
                                        $this->container->notificadorPresupuesto()->notificarGasto($user['id'], $p['id_categoria'], $base['fecha'], $espacioId);
                                        $av = $this->avisoPresupuesto($user['id'], $p['id_categoria'], $base['fecha'], $espacioId);
                                        if ($av !== null && !in_array($av, $avisos, true)) {
                                            $avisos[] = $av;
                                        }
                                    }
                                }
                                $avisoTexto = implode(' ', $avisos);
                                Flash::set($avisoTexto === '' ? 'ok' : 'warn', $avisoTexto === '' ? $mensaje : "$mensaje $avisoTexto");
                                Response::redirect('/movimientos.php');

                                // no break
                            case ReglasMovimiento::TIPO_DISCORDANTE:
                                $errors['split'] = 'Alguna categoría de la división no coincide con el tipo (' . ($splitRes['base']['tipo'] === 'ingreso' ? 'ingresos' : 'gastos') . ').';
                                break;

                            case ReglasMovimiento::CUENTA_INACTIVA:
                                $errors['id_cuenta'] = "La cuenta «{$saved['cuenta']}» está inactiva. Actívala en Cuentas o elige otra.";
                                break;

                            case ReglasMovimiento::SALDO_INSUFICIENTE:
                                $disponible = Dinero::formatear((string) ($saved['saldo'] ?? '0.00'));
                                $errors['monto'] = "La cuenta «{$saved['cuenta']}» tiene $disponible disponibles: no alcanza para registrar este movimiento dividido sin dejarla en negativo.";
                                break;

                            default:
                                Response::plain(404, "No encontrado.\n");
                        }
                    }
                } else {
                    $result = (new MovimientoValidator())->validate($_POST);
                    $errors = $result['errors'];
                    if (!$subida['ok'] && $subida['error'] !== null) {
                        $errors['comprobante'] = $subida['error'];
                    }
                    $old = [
                        'tipo' => $result['data']['tipo'],
                        'monto' => is_string($_POST['monto'] ?? null) ? trim($_POST['monto']) : '',
                        'fecha' => is_string($_POST['fecha'] ?? null) ? trim($_POST['fecha']) : '',
                        'id_cuenta' => (string) ($result['data']['id_cuenta'] ?: ''),
                        'id_categoria' => (string) ($result['data']['id_categoria'] ?: ''),
                        'descripcion' => $result['data']['descripcion'] ?? '',
                        'etiqueta' => is_string($_POST['etiqueta'] ?? null) ? trim($_POST['etiqueta']) : '',
                        'num_cuotas' => (string) ($result['data']['num_cuotas'] ?? 1),
                        'con_intereses' => ($result['data']['con_intereses'] ?? true) ? '1' : '0',
                    ];

                    if ($errors === []) {
                        $saved = $this->container->gestionMovimientos()->guardar($user['id'], $movimiento?->id, $result['data'], $espacioId);

                        switch ($saved['codigo']) {
                            case ReglasMovimiento::OK:
                                $movIdGuardado = (int) $saved['id'];
                                if (!$subida['vacio'] && $subida['tmp'] !== null && $subida['mime'] !== null) {
                                    $guardado = AlmacenAdjuntos::guardarDesdeArchivo($subida['tmp'], $subida['mime'], (string) $subida['nombre']);
                                    $this->container->movimientos()->actualizarAdjunto($user['id'], $movIdGuardado, $guardado['ruta'], $guardado['nombre'], $guardado['mime'], $espacioId);
                                    if ($movimiento !== null && $movimiento->adjuntoRuta !== null) {
                                        AlmacenAdjuntos::eliminar($movimiento->adjuntoRuta);
                                    }
                                } elseif ($movimiento !== null && ($_POST['eliminar_adjunto'] ?? '') === '1' && $movimiento->adjuntoRuta !== null) {
                                    $this->container->movimientos()->actualizarAdjunto($user['id'], $movIdGuardado, null, null, null, $espacioId);
                                    AlmacenAdjuntos::eliminar($movimiento->adjuntoRuta);
                                }
                                $data = $result['data'];
                                $mensaje = $movimiento === null
                                    ? 'Se registró ' . ($data['tipo'] === 'ingreso' ? 'el ingreso' : 'el gasto') . ' de ' . Dinero::formatear($data['monto']) . " en «{$saved['cuenta']}»."
                                    : 'Se guardaron los cambios del movimiento.';
                                // RF-17: si el gasto deja la categoría cerca o por encima de su presupuesto del mes, se avisa aquí mismo.
                                if ($data['tipo'] === 'gasto') {
                                    $this->container->notificadorPresupuesto()->notificarGasto($user['id'], $data['id_categoria'], $data['fecha'], $espacioId);
                                }
                                $aviso = $data['tipo'] === 'gasto' ? $this->avisoPresupuesto($user['id'], $data['id_categoria'], $data['fecha'], $espacioId) : null;
                                Flash::set($aviso === null ? 'ok' : 'warn', $aviso === null ? $mensaje : "$mensaje $aviso");
                                Response::redirect('/movimientos.php');

                                // no break
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
            }

            // Cuentas activas; si el movimiento está en una que ya no lo es, también esa (marcada), para que el formulario diga la verdad.
            $cuentas = array_values(array_filter(
                $this->container->cuentas()->listByUser($user['id'], $espacioId),
                static fn (Cuenta $c): bool => $c->activa || (string) $c->id === $old['id_cuenta'],
            ));
            $categorias = $this->container->categorias()->listVisible($user['id'], $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

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

        try {
            $mov = $this->container->movimientos()->findOwned($user['id'], $id, $espacioId);
        } catch (RuntimeException $error) {
            self::fail($error);
        }

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

    /** Importación asistida de movimientos desde archivo o texto CSV (Fase 13). */
    public function importar(): void
    {
        $user = require_login();
        require_espacio_write($user);
        $espacioId = $user['espacio_id'] ?? null;
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

        try {
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

                $cuentaId = is_string($_POST['id_cuenta'] ?? null) && ctype_digit($_POST['id_cuenta']) ? (int) $_POST['id_cuenta'] : 0;
                $catGastoId = is_string($_POST['id_categoria_gasto'] ?? null) && ctype_digit($_POST['id_categoria_gasto']) ? (int) $_POST['id_categoria_gasto'] : 0;
                $catIngresoId = is_string($_POST['id_categoria_ingreso'] ?? null) && ctype_digit($_POST['id_categoria_ingreso']) ? (int) $_POST['id_categoria_ingreso'] : 0;
                $csvTexto = is_string($_POST['csv_texto'] ?? null) ? trim($_POST['csv_texto']) : '';

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
                $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : 'previsualizar';

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
        } catch (RuntimeException $error) {
            self::fail($error);
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

    /**
     * El aviso de RF-17 para un gasto de esa categoría y fecha, o null si no hay presupuesto o está en orden (menos del
     * 80 %). Se calcula al momento, no se guarda.
     */
    private function avisoPresupuesto(int $userId, int $categoriaId, string $fecha, ?int $espacioId = null): ?string
    {
        $mes = Mes::deFecha($fecha);
        if ($mes === null) {
            return null;
        }
        $monedaBase = Dinero::monedaActual();
        $tasas = $this->container->tasasCambio()->listMap($userId, $espacioId);
        $presupuesto = $this->container->presupuestos()->findForCategory($userId, $categoriaId, $mes['mes'], $mes['anio'], $espacioId, $monedaBase, $tasas);

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
