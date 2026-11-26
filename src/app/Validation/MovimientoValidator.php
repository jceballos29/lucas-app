<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\Dinero;
use DateTimeImmutable;

/**
 * Reglas del formulario de movimiento (RF-10, RNF-16). Puro: que la cuenta y la categoría existan, sean del usuario y
 * encajen con el tipo lo decide {@see \App\Movimientos\ReglasMovimiento} dentro de la transacción (D8).
 */
final class MovimientoValidator
{
    public const DESCRIPCION_MAX = 150;
    public const ETIQUETA_MAX = 40;
    public const CUOTAS_MIN = 1;
    public const CUOTAS_MAX = 36;
    public const ANIO_MIN = 2000;
    public const ANIO_MAX = 2100;

    /**
     * @param array<string, mixed> $input Normalmente $_POST (tipo, monto, fecha, id_cuenta, id_categoria, descripcion, etiqueta, num_cuotas).
     * @return array{data: array{tipo: string, monto: string, fecha: string, id_cuenta: int, id_categoria: int, descripcion: string|null, etiqueta: string|null, num_cuotas: int}, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $tipo = self::text($input['tipo'] ?? '');
        $montoTexto = trim(self::text($input['monto'] ?? ''));
        $fecha = trim(self::text($input['fecha'] ?? ''));
        $descripcion = trim(preg_replace('/\s+/u', ' ', self::text($input['descripcion'] ?? '')) ?? '');
        $etiquetaRaw = trim(self::text($input['etiqueta'] ?? ''));
        $cuotasRaw = trim(self::text($input['num_cuotas'] ?? ''));
        $errors = [];

        if (!in_array($tipo, ['ingreso', 'gasto'], true)) {
            $errors['tipo'] = 'Elige si es un ingreso o un gasto.';
        }

        $monto = Dinero::parse($montoTexto);
        if ($monto === null) {
            $errors['monto'] = 'Escribe un monto válido, por ejemplo 18.000 o 1.250.000,50.';
        } elseif ($monto === '0.00' || str_starts_with($monto, '-')) {
            $errors['monto'] = 'El monto debe ser mayor que cero.';
        }

        if (!self::validDate($fecha)) {
            $errors['fecha'] = 'Elige una fecha válida.';
        }

        $idCuenta = self::id($input['id_cuenta'] ?? '');
        if ($idCuenta === 0) {
            $errors['id_cuenta'] = 'Elige la cuenta.';
        }

        $idCategoria = self::id($input['id_categoria'] ?? '');
        if ($idCategoria === 0) {
            $errors['id_categoria'] = 'Elige la categoría.';
        }

        if (mb_strlen($descripcion) > self::DESCRIPCION_MAX) {
            $errors['descripcion'] = 'La descripción admite hasta ' . self::DESCRIPCION_MAX . ' caracteres.';
        }

        $etiqueta = null;
        if ($etiquetaRaw !== '') {
            $etiqueta = self::normalizarEtiqueta($etiquetaRaw);
            if ($etiqueta === null) {
                $errors['etiqueta'] = 'La etiqueta solo admite letras, números y guiones (hasta ' . self::ETIQUETA_MAX . ' caracteres, ej. vacaciones-2026).';
            }
        }

        $numCuotas = 1;
        if ($cuotasRaw !== '') {
            if (!ctype_digit($cuotasRaw) || (int) $cuotasRaw < self::CUOTAS_MIN || (int) $cuotasRaw > self::CUOTAS_MAX) {
                $errors['num_cuotas'] = 'El número de cuotas debe estar entre ' . self::CUOTAS_MIN . ' y ' . self::CUOTAS_MAX . '.';
            } else {
                $numCuotas = (int) $cuotasRaw;
            }
        }
        if ($tipo === 'ingreso') {
            $numCuotas = 1;
        }

        return [
            'data' => [
                'tipo' => $tipo,
                'monto' => $monto ?? '0.00',
                'fecha' => $fecha,
                'id_cuenta' => $idCuenta,
                'id_categoria' => $idCategoria,
                'descripcion' => $descripcion === '' ? null : $descripcion,
                'etiqueta' => $etiqueta,
                'num_cuotas' => $numCuotas,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Valida una división de movimiento en varias categorías (Split Transactions · Fase 14).
     *
     * @param array<string, mixed> $input $_POST con tipo, monto (opcional si suma partes), fecha, id_cuenta, descripcion, etiqueta, split_categoria[], split_monto[], split_descripcion[].
     * @return array{
     *   base: array{tipo: string, monto_total: string, fecha: string, id_cuenta: int, descripcion: string|null, etiqueta: string|null},
     *   partes: list<array{id_categoria: int, monto: string, descripcion: string|null}>,
     *   errors: array<string, string>
     * }
     */
    public function validateSplit(array $input): array
    {
        $tipo = self::text($input['tipo'] ?? '');
        $montoTotalTexto = trim(self::text($input['monto'] ?? ''));
        $fecha = trim(self::text($input['fecha'] ?? ''));
        $descripcionBase = trim(preg_replace('/\s+/u', ' ', self::text($input['descripcion'] ?? '')) ?? '');
        $etiquetaRaw = trim(self::text($input['etiqueta'] ?? ''));
        $errors = [];

        if (!in_array($tipo, ['ingreso', 'gasto'], true)) {
            $errors['tipo'] = 'Elige si es un ingreso o un gasto.';
        }

        if (!self::validDate($fecha)) {
            $errors['fecha'] = 'Elige una fecha válida.';
        }

        $idCuenta = self::id($input['id_cuenta'] ?? '');
        if ($idCuenta === 0) {
            $errors['id_cuenta'] = 'Elige la cuenta.';
        }

        if (mb_strlen($descripcionBase) > self::DESCRIPCION_MAX) {
            $errors['descripcion'] = 'La descripción admite hasta ' . self::DESCRIPCION_MAX . ' caracteres.';
        }

        $etiqueta = null;
        if ($etiquetaRaw !== '') {
            $etiqueta = self::normalizarEtiqueta($etiquetaRaw);
            if ($etiqueta === null) {
                $errors['etiqueta'] = 'La etiqueta solo admite letras, números y guiones (hasta ' . self::ETIQUETA_MAX . ' caracteres, ej. vacaciones-2026).';
            }
        }

        $cuotasRaw = trim(self::text($input['num_cuotas'] ?? ''));
        $numCuotas = 1;
        if ($cuotasRaw !== '') {
            if (!ctype_digit($cuotasRaw) || (int) $cuotasRaw < self::CUOTAS_MIN || (int) $cuotasRaw > self::CUOTAS_MAX) {
                $errors['num_cuotas'] = 'El número de cuotas debe estar entre ' . self::CUOTAS_MIN . ' y ' . self::CUOTAS_MAX . '.';
            } else {
                $numCuotas = (int) $cuotasRaw;
            }
        }
        if ($tipo === 'ingreso') {
            $numCuotas = 1;
        }

        $catsRaw = is_array($input['split_categoria'] ?? null) ? $input['split_categoria'] : [];
        $montosRaw = is_array($input['split_monto'] ?? null) ? $input['split_monto'] : [];
        $descsRaw = is_array($input['split_descripcion'] ?? null) ? $input['split_descripcion'] : [];

        $partes = [];
        $montosPartes = [];
        $maxFilas = min(4, max(count($catsRaw), count($montosRaw)));

        for ($i = 0; $i < $maxFilas; $i++) {
            $catId = self::id($catsRaw[$i] ?? '');
            $mTexto = trim(self::text($montosRaw[$i] ?? ''));
            $dTexto = trim(preg_replace('/\s+/u', ' ', self::text($descsRaw[$i] ?? '')) ?? '');

            if ($catId === 0 && $mTexto === '' && $dTexto === '') {
                continue;
            }

            if ($catId === 0) {
                $errors['split'] = 'Elige la categoría en cada parte de la división.';
                break;
            }

            $m = Dinero::parse($mTexto);
            if ($m === null || $m === '0.00' || str_starts_with($m, '-')) {
                $errors['split'] = 'Escribe un monto mayor que cero en cada parte de la división.';
                break;
            }

            $descFinal = $dTexto !== ''
                ? $dTexto
                : ($descripcionBase !== '' ? $descripcionBase : null);

            if ($descFinal !== null && mb_strlen($descFinal) > self::DESCRIPCION_MAX) {
                $descFinal = mb_substr($descFinal, 0, self::DESCRIPCION_MAX);
            }

            $partes[] = [
                'id_categoria' => $catId,
                'monto' => $m,
                'descripcion' => $descFinal,
            ];
            $montosPartes[] = $m;
        }

        if (!isset($errors['split']) && count($partes) < 2) {
            $errors['split'] = 'Para dividir un movimiento indica al menos 2 categorías con su monto.';
        }

        $sumaPartes = $montosPartes !== [] ? Dinero::sumar($montosPartes) : '0.00';

        if ($montoTotalTexto !== '') {
            $montoTotal = Dinero::parse($montoTotalTexto);
            if ($montoTotal === null || $montoTotal === '0.00' || str_starts_with($montoTotal, '-')) {
                $errors['monto'] = 'Escribe un monto total válido mayor que cero.';
            } elseif (!isset($errors['split']) && $montoTotal !== $sumaPartes) {
                $errors['split'] = 'La suma de las partes (' . Dinero::formatear($sumaPartes) . ') debe coincidir con el monto total (' . Dinero::formatear($montoTotal) . ').';
            }
        } else {
            $montoTotal = $sumaPartes;
        }

        return [
            'base' => [
                'tipo' => $tipo,
                'monto_total' => $montoTotal ?? '0.00',
                'fecha' => $fecha,
                'id_cuenta' => $idCuenta,
                'descripcion' => $descripcionBase === '' ? null : $descripcionBase,
                'etiqueta' => $etiqueta,
                'num_cuotas' => $numCuotas,
            ],
            'partes' => $partes,
            'errors' => $errors,
        ];
    }

    /**
     * Normaliza una etiqueta transversal (`#vacaciones 2026` → `vacaciones-2026`).
     * Devuelve null si tras limpiar contiene caracteres no permitidos o supera ETIQUETA_MAX.
     */
    public static function normalizarEtiqueta(string $raw): ?string
    {
        $limpia = trim($raw);
        $limpia = ltrim($limpia, "# \t");
        $limpia = mb_strtolower($limpia);
        $limpia = preg_replace('/\s+/u', '-', $limpia) ?? '';
        $limpia = trim($limpia, '-_');

        if ($limpia === '' || mb_strlen($limpia) > self::ETIQUETA_MAX) {
            return null;
        }

        if (preg_match('/^[a-z0-9áéíóúñü_-]+$/u', $limpia) !== 1) {
            return null;
        }

        return $limpia;
    }

    /** Una fecha `AAAA-MM-DD` real (no el 31 de febrero) dentro de un rango razonable. */
    public static function validDate(string $fecha): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m) !== 1) {
            return false;
        }

        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return $y >= self::ANIO_MIN && $y <= self::ANIO_MAX && checkdate($mo, $d, $y)
            && DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) !== false;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** Un id positivo; cualquier otra cosa (vacío, texto, arreglo, «0», «-3») es 0. */
    private static function id(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : 0;
    }
}
