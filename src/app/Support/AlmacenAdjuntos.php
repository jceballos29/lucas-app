<?php

declare(strict_types=1);

namespace App\Support;

use finfo;
use RuntimeException;

/**
 * Almacenamiento seguro de comprobantes y recibos FUERA de `public/` (Fase 19).
 *
 * - Solo acepta JPG, PNG, WebP y PDF hasta 5 MB.
 * - Valida SIEMPRE el contenido real (firma binaria + `finfo`), nunca la extensión ni el MIME enviado por el navegador.
 * - Genera nombres aleatorios no adivinables (`YYYY/MM/<32-hex>.<ext>`), impide path traversal y limpia nombres para descarga.
 */
final class AlmacenAdjuntos
{
    /** Límite máximo de un comprobante: 5 MiB. */
    public const MAX_BYTES = 5_242_880;

    private const MENSAJE_DEMASIADO_GRANDE = 'El comprobante supera el tamaño máximo permitido (5 MB).';

    /** Tipos MIME reales admitidos y su extensión canónica en disco. */
    public const MIMES_PERMITIDOS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Ruta base fuera de `public/`: `<proyecto>/src/storage/adjuntos`. */
    public static function directorioBase(?string $baseDir = null): string
    {
        return rtrim($baseDir ?? dirname(__DIR__, 2) . '/storage/adjuntos', '/');
    }

    /**
     * Detecta el MIME real a partir de los primeros bytes (magic bytes). Devuelve null si no corresponde a un tipo admitido.
     */
    public static function detectarMimePorFirma(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($bytes, '%PDF-')) {
            return 'application/pdf';
        }

        return null;
    }

    /** Limpia el nombre original del archivo para guardarlo como metadato legible (sin rutas ni caracteres de control). */
    public static function limpiarNombreOriginal(string $nombre): string
    {
        $base = basename(str_replace('\\', '/', $nombre));
        $limpio = preg_replace('/[^\p{L}\p{N}._\- ]+/u', '', $base) ?? '';
        $limpio = trim(preg_replace('/\s+/u', ' ', $limpio) ?? '');
        $limpio = ltrim($limpio, '.');

        if ($limpio === '') {
            return 'comprobante';
        }

        return mb_substr($limpio, 0, 100);
    }

    /** Nombre seguro (ASCII sin comillas ni saltos de línea) para la cabecera `Content-Disposition`. */
    public static function nombreSeguroDescarga(string $nombreOriginal, string $mime): string
    {
        $ext = self::MIMES_PERMITIDOS[$mime] ?? 'bin';
        $limpio = self::limpiarNombreOriginal($nombreOriginal);
        $sinExt = (string) preg_replace('/(?:\.[A-Za-z0-9]{1,8})+$/', '', $limpio);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sinExt) ?? '';
        $ascii = trim($ascii, '.-');
        if ($ascii === '') {
            $ascii = 'comprobante';
        }

        return substr($ascii, 0, 80) . '.' . $ext;
    }

    /**
     * Valida una entrada de `$_FILES`. Si el usuario no adjuntó archivo devuelve {@see ResultadoSubida::vacio()} (no es un error).
     *
     * @param array<string, mixed>|null $file
     */
    public static function validarSubida(?array $file): ResultadoSubida
    {
        if ($file === null || !isset($file['error'])) {
            return ResultadoSubida::vacio();
        }

        $codigoError = is_int($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($codigoError === UPLOAD_ERR_NO_FILE) {
            return ResultadoSubida::vacio();
        }

        if ($codigoError === UPLOAD_ERR_INI_SIZE || $codigoError === UPLOAD_ERR_FORM_SIZE) {
            return ResultadoSubida::rechazado(self::MENSAJE_DEMASIADO_GRANDE);
        }

        if ($codigoError !== UPLOAD_ERR_OK) {
            return ResultadoSubida::rechazado('No se pudo subir el comprobante. Inténtalo de nuevo.');
        }

        $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $size = is_int($file['size'] ?? null) ? $file['size'] : 0;
        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp) || $size <= 0) {
            return ResultadoSubida::rechazado('El archivo subido está vacío o no se pudo leer.');
        }

        if ($size > self::MAX_BYTES) {
            return ResultadoSubida::rechazado(self::MENSAJE_DEMASIADO_GRANDE);
        }

        $fh = fopen($tmp, 'rb');
        $cabecera = $fh !== false ? (string) fread($fh, 32) : '';
        if ($fh !== false) {
            fclose($fh);
        }

        $mimeFirma = self::detectarMimePorFirma($cabecera);
        if ($mimeFirma === null) {
            return ResultadoSubida::rechazado('Formato no permitido. Adjunta solo imágenes JPG, PNG, WebP o documentos PDF.');
        }

        if (class_exists(finfo::class)) {
            $mimeFinfo = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if ($mimeFinfo !== false && $mimeFinfo !== $mimeFirma) {
                return ResultadoSubida::rechazado('El contenido del archivo no coincide con un formato permitido (JPG, PNG, WebP o PDF).');
            }
        }

        return ResultadoSubida::valido(
            $tmp,
            $mimeFirma,
            self::MIMES_PERMITIDOS[$mimeFirma],
            self::limpiarNombreOriginal(is_string($file['name'] ?? null) ? $file['name'] : ''),
        );
    }

    /**
     * Guarda en disco un archivo ya validado (subido por HTTP o temporal) fuera de `public/`.
     *
     * @return array{ruta: string, nombre: string, mime: string}
     */
    public static function guardarDesdeArchivo(string $tmpPath, string $mime, string $nombreOriginal, ?string $baseDir = null): array
    {
        if (!isset(self::MIMES_PERMITIDOS[$mime])) {
            throw new RuntimeException('Tipo MIME no permitido.');
        }

        ['relativa' => $relativa, 'destino' => $destino] = self::rutaNueva($mime, $baseDir);

        $ok = is_uploaded_file($tmpPath) ? @move_uploaded_file($tmpPath, $destino) : @copy($tmpPath, $destino);
        if (!$ok) {
            throw new RuntimeException('No se pudo guardar el comprobante en disco.');
        }

        @chmod($destino, 0640);

        return ['ruta' => $relativa, 'nombre' => self::limpiarNombreOriginal($nombreOriginal), 'mime' => $mime];
    }

    /**
     * Guarda bytes directamente (útil para pruebas o procesamiento en memoria).
     *
     * @return array{ruta: string, nombre: string, mime: string}
     */
    public static function guardarDesdeContenido(string $bytes, string $mime, string $nombreOriginal, ?string $baseDir = null): array
    {
        if (!isset(self::MIMES_PERMITIDOS[$mime]) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Contenido o tipo MIME de comprobante no válido.');
        }

        ['relativa' => $relativa, 'destino' => $destino] = self::rutaNueva($mime, $baseDir);

        if (@file_put_contents($destino, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir el comprobante en disco.');
        }

        @chmod($destino, 0640);

        return ['ruta' => $relativa, 'nombre' => self::limpiarNombreOriginal($nombreOriginal), 'mime' => $mime];
    }

    /**
     * Elige el sitio de un comprobante nuevo (`YYYY/MM/<32 hex>.<ext>`) y prepara su carpeta. Común a las dos formas de guardar.
     *
     * @return array{relativa: string, destino: string}
     */
    private static function rutaNueva(string $mime, ?string $baseDir): array
    {
        $subDir = date('Y/m');
        $relativa = $subDir . '/' . bin2hex(random_bytes(16)) . '.' . self::MIMES_PERMITIDOS[$mime];
        $destinoDir = self::directorioBase($baseDir) . '/' . $subDir;

        if (!is_dir($destinoDir) && !@mkdir($destinoDir, 0750, true) && !is_dir($destinoDir)) {
            throw new RuntimeException('No se pudo preparar el directorio de comprobantes.');
        }

        return ['relativa' => $relativa, 'destino' => self::directorioBase($baseDir) . '/' . $relativa];
    }

    /**
     * Resuelve la ruta absoluta validando estrictamente el patrón `YYYY/MM/<32-hex>.<ext>` (impide path traversal).
     */
    public static function rutaAbsoluta(?string $rutaRelativa, ?string $baseDir = null): ?string
    {
        if ($rutaRelativa === null || preg_match('/^\d{4}\/\d{2}\/[0-9a-f]{32}\.(?:jpg|png|webp|pdf)$/', $rutaRelativa) !== 1) {
            return null;
        }

        return self::directorioBase($baseDir) . '/' . $rutaRelativa;
    }

    /**
     * Ruta del comprobante SOLO si es un archivo normal dentro de la carpeta de comprobantes: no sigue enlaces simbólicos ni sale de ella
     * (defensa en profundidad: la ruta ya sale de la base de datos con un formato fijo, pero una carpeta restaurada o tocada a mano podría
     * traer un enlace hacia otro sitio del servidor).
     */
    public static function rutaLegible(?string $rutaRelativa, ?string $baseDir = null): ?string
    {
        $abs = self::rutaAbsoluta($rutaRelativa, $baseDir);
        if ($abs === null || is_link($abs) || !is_file($abs) || !is_readable($abs)) {
            return null;
        }

        $real = realpath($abs);
        $base = realpath(self::directorioBase($baseDir));

        return $real !== false && $base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $abs : null;
    }

    /** Borra el archivo físico del comprobante si existe (ignora rutas inválidas o ya inexistentes). */
    public static function eliminar(?string $rutaRelativa, ?string $baseDir = null): void
    {
        $abs = self::rutaAbsoluta($rutaRelativa, $baseDir);
        if ($abs !== null && is_file($abs)) {
            @unlink($abs);
        }
    }
}
