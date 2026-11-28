<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resultado de validar un comprobante subido (R3 de docs/AUDITORIA.md). Tres casos, con un constructor cada uno:
 * {@see self::vacio()} (no se adjuntó nada: es válido), {@see self::rechazado()} (con el motivo para el usuario) y {@see self::valido()}
 * (con la ruta temporal, el MIME real, la extensión canónica y el nombre original ya limpio). Inmutable.
 */
final class ResultadoSubida
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $vacio,
        public readonly ?string $error,
        public readonly ?string $tmp,
        public readonly ?string $mime,
        public readonly ?string $ext,
        public readonly ?string $nombre,
    ) {
    }

    /** No se adjuntó ningún archivo: no es un error. */
    public static function vacio(): self
    {
        return new self(true, true, null, null, null, null, null);
    }

    public static function rechazado(string $motivo): self
    {
        return new self(false, false, $motivo, null, null, null, null);
    }

    public static function valido(string $tmp, string $mime, string $ext, string $nombre): self
    {
        return new self(true, false, null, $tmp, $mime, $ext, $nombre);
    }

    /** ¿Hay un archivo válido para guardar? */
    public function hayArchivo(): bool
    {
        return $this->ok && !$this->vacio && $this->tmp !== null && $this->mime !== null;
    }
}
