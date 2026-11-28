<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detecta comprobantes HUÉRFANOS: archivos de `storage/adjuntos` que ninguna fila de `transacciones` referencia (M3 de
 * docs/AUDITORIA.md). Pueden quedar por un borrado interrumpido o por una subida cuyo movimiento no llegó a guardarse. Solo se
 * consideran los archivos con el nombre exacto que genera AlmacenAdjuntos (`YYYY/MM/<32 hex>.<ext>`) y más antiguos que una edad
 * mínima, para no tocar una subida que está ocurriendo ahora mismo (el archivo se escribe un instante antes que su fila).
 */
final class HuerfanosAdjuntos
{
    /** Edad mínima por defecto antes de considerar huérfano un archivo: 24 horas. */
    public const EDAD_MINIMA = 86400;

    private const PATRON = '/^\d{4}\/\d{2}\/[0-9a-f]{32}\.(?:jpg|png|webp|pdf)$/';

    /**
     * Rutas relativas de los comprobantes que hay en disco (solo las que tienen el nombre canónico).
     *
     * @return list<string>
     */
    public static function enDisco(?string $baseDir = null): array
    {
        $base = AlmacenAdjuntos::directorioBase($baseDir);
        $rutas = [];

        foreach (glob($base . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*', GLOB_NOSORT) ?: [] as $archivo) {
            $relativa = substr($archivo, strlen($base) + 1);

            if (is_file($archivo) && preg_match(self::PATRON, $relativa) === 1) {
                $rutas[] = $relativa;
            }
        }

        sort($rutas);

        return $rutas;
    }

    /**
     * Archivos en disco que ninguna fila referencia y que tienen al menos `$edadMinima` segundos.
     *
     * @param list<string> $rutasEnBd Valores de `transacciones.adjunto_ruta`.
     * @return list<string>
     */
    public static function huerfanos(array $rutasEnBd, int $edadMinima = self::EDAD_MINIMA, ?string $baseDir = null, ?int $ahora = null): array
    {
        $ahora ??= time();
        $enBd = array_flip($rutasEnBd);
        $base = AlmacenAdjuntos::directorioBase($baseDir);
        $huerfanos = [];

        foreach (self::enDisco($baseDir) as $ruta) {
            $modificado = filemtime($base . '/' . $ruta);

            if (!isset($enBd[$ruta]) && $modificado !== false && $ahora - $modificado >= $edadMinima) {
                $huerfanos[] = $ruta;
            }
        }

        return $huerfanos;
    }

    /**
     * Filas que apuntan a un archivo que ya no existe (el caso contrario: el usuario vería «comprobante» pero la descarga da 404).
     *
     * @param list<string> $rutasEnBd
     * @return list<string>
     */
    public static function faltantes(array $rutasEnBd, ?string $baseDir = null): array
    {
        $enDisco = array_flip(self::enDisco($baseDir));

        return array_values(array_filter($rutasEnBd, static fn (string $ruta): bool => !isset($enDisco[$ruta])));
    }
}
