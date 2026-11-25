<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Export\Csv;
use App\Http\Failure;
use App\Http\Response;
use App\Movimientos\Filtros;
use RuntimeException;

/**
 * Exportación de movimientos a CSV (RF-23). Es un GET que solo LEE (no cambia estado, así que no lleva CSRF) y exporta
 * únicamente los movimientos del usuario de la sesión, con los mismos filtros del listado. Se recorre por lotes (paginación por
 * cursor) para no cargar todo en memoria, y los textos pasan por Csv::texto() contra la inyección de fórmulas.
 */
final class ExportController
{
    /** Filas por consulta. */
    private const LOTE = 1000;

    /** Tope de seguridad: un solo usuario no puede mantener un proceso ocupado indefinidamente. */
    private const MAX_FILAS = 100_000;

    public function __construct(private readonly Container $container)
    {
    }

    public function movimientos(): void
    {
        $user = require_login();
        $espacioId = $user['espacio_id'] ?? null;
        $filtros = Filtros::desde($_GET);
        $repo = $this->container->movimientos();

        try {
            // La primera consulta va ANTES de enviar cabeceras: si la BD falla, se responde un error normal, no un CSV a medias.
            $lote = $repo->searchAfter($user['id'], $filtros, null, self::LOTE, $espacioId);
        } catch (RuntimeException $error) {
            [$status, $message] = Failure::describe($error, 'No se pudo exportar. Inténtalo más tarde.');
            Response::plain($status, $message . "\n");
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="lucas-movimientos-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        echo Csv::BOM;
        echo Csv::fila(array_map([Csv::class, 'celda'], ['Fecha', 'Tipo', 'Categoría', 'Cuenta', 'Descripción', 'Monto']));

        $enviadas = 0;
        while ($lote !== [] && $enviadas < self::MAX_FILAS) {
            foreach ($lote as $m) {
                echo Csv::fila([
                    Csv::celda($m->fecha),                                   // ISO: Excel la reconoce en cualquier idioma
                    Csv::celda($m->esIngreso() ? 'Ingreso' : 'Gasto'),
                    Csv::texto($m->categoriaNombre),
                    Csv::texto($m->cuentaNombre),
                    Csv::texto($m->descripcion ?? ''),
                    Csv::monto($m->monto),
                ]);
                $enviadas++;
            }

            $ultimo = $lote[array_key_last($lote)];

            if (count($lote) < self::LOTE) {
                break;
            }

            try {
                $lote = $repo->searchAfter($user['id'], $filtros, ['fecha' => $ultimo->fecha, 'id' => $ultimo->id], self::LOTE, $espacioId);
            } catch (RuntimeException $error) {
                // Ya se enviaron cabeceras: no se puede cambiar el código. Se registra y se corta (el archivo queda truncado,
                // sin pasar por una pantalla de error que alguien guardaría como si fuera el CSV).
                Failure::describe($error, '');
                return;
            }
        }
    }
}
