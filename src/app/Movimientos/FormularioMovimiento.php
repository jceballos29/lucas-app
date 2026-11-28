<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Domain\Movimiento;
use App\Support\AlmacenAdjuntos;
use App\Support\Dinero;
use App\Validation\MovimientoValidator;

/**
 * Lee y valida el formulario de movimiento (R1 de docs/AUDITORIA.md): el alta, la edición y el movimiento dividido en varias categorías.
 * No guarda nada ni toca la base de datos (eso es {@see GuardarMovimiento}); solo devuelve un {@see EnvioMovimiento}.
 */
final class FormularioMovimiento
{
    /** Filas de división que ofrece el formulario. */
    public const FILAS_DIVISION = 4;

    /**
     * Valores iniciales: los del movimiento que se edita, o los de un gasto nuevo de hoy.
     *
     * @return array<string, string>
     */
    public static function inicial(?Movimiento $movimiento): array
    {
        if ($movimiento === null) {
            return ['tipo' => 'gasto', 'monto' => '', 'fecha' => date('Y-m-d'), 'id_cuenta' => '', 'id_categoria' => '', 'descripcion' => '', 'etiqueta' => '', 'num_cuotas' => '1', 'con_intereses' => '1'];
        }

        return [
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
    }

    /** @return list<array{id_categoria: string, monto: string, descripcion: string}> */
    public static function filasDivisionVacias(): array
    {
        return array_fill(0, self::FILAS_DIVISION, ['id_categoria' => '', 'monto' => '', 'descripcion' => '']);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $archivo Entrada `comprobante` de `$_FILES`.
     */
    public static function leer(array $post, ?array $archivo, bool $esEdicion): EnvioMovimiento
    {
        $subida = AlmacenAdjuntos::validarSubida($archivo);
        [$splitOld, $usaSplit] = self::leerFilasDivision($post);

        $validador = new MovimientoValidator();

        // Dividir solo se ofrece al crear: al editar siempre es un movimiento normal.
        if (!$esEdicion && ($usaSplit || ($post['dividir'] ?? '') === '1')) {
            $res = $validador->validateSplit($post);
            $errors = self::conErrorDeSubida($res['errors'], $subida);
            $old = [
                'tipo' => $res['base']['tipo'],
                'monto' => self::texto($post, 'monto'),
                'fecha' => self::texto($post, 'fecha'),
                'id_cuenta' => (string) ($res['base']['id_cuenta'] ?: ''),
                'id_categoria' => '',
                'descripcion' => $res['base']['descripcion'] ?? '',
                'etiqueta' => self::texto($post, 'etiqueta'),
                'num_cuotas' => (string) ($res['base']['num_cuotas'] ?? 1),
                'con_intereses' => ($res['base']['con_intereses'] ?? true) ? '1' : '0',
            ];

            return new EnvioMovimiento(true, $res['base'], $res['partes'], $errors, $old, $splitOld, $subida);
        }

        $res = $validador->validate($post);
        $errors = self::conErrorDeSubida($res['errors'], $subida);
        $old = [
            'tipo' => $res['data']['tipo'],
            'monto' => self::texto($post, 'monto'),
            'fecha' => self::texto($post, 'fecha'),
            'id_cuenta' => (string) ($res['data']['id_cuenta'] ?: ''),
            'id_categoria' => (string) ($res['data']['id_categoria'] ?: ''),
            'descripcion' => $res['data']['descripcion'] ?? '',
            'etiqueta' => self::texto($post, 'etiqueta'),
            'num_cuotas' => (string) ($res['data']['num_cuotas'] ?? 1),
            'con_intereses' => ($res['data']['con_intereses'] ?? true) ? '1' : '0',
        ];

        return new EnvioMovimiento(false, $res['data'], [], $errors, $old, $splitOld, $subida);
    }

    /**
     * Las filas de división tal como llegaron (para repoblar el formulario) y si el usuario escribió algo en alguna.
     *
     * @param array<string, mixed> $post
     * @return array{0: list<array{id_categoria: string, monto: string, descripcion: string}>, 1: bool}
     */
    private static function leerFilasDivision(array $post): array
    {
        $cats = is_array($post['split_categoria'] ?? null) ? $post['split_categoria'] : [];
        $montos = is_array($post['split_monto'] ?? null) ? $post['split_monto'] : [];
        $descs = is_array($post['split_descripcion'] ?? null) ? $post['split_descripcion'] : [];

        $filas = [];
        $usa = false;
        for ($i = 0; $i < self::FILAS_DIVISION; $i++) {
            $c = is_string($cats[$i] ?? null) ? trim($cats[$i]) : '';
            $m = is_string($montos[$i] ?? null) ? trim($montos[$i]) : '';
            $d = is_string($descs[$i] ?? null) ? trim($descs[$i]) : '';
            $filas[] = ['id_categoria' => $c, 'monto' => $m, 'descripcion' => $d];
            if ($c !== '' || $m !== '') {
                $usa = true;
            }
        }

        return [$filas, $usa];
    }

    /**
     * @param array<string, string> $errors
     * @param array{ok: bool, error: string|null} $subida
     * @return array<string, string>
     */
    private static function conErrorDeSubida(array $errors, array $subida): array
    {
        if (!$subida['ok'] && $subida['error'] !== null) {
            $errors['comprobante'] = $subida['error'];
        }

        return $errors;
    }

    /** @param array<string, mixed> $post */
    private static function texto(array $post, string $campo): string
    {
        return is_string($post[$campo] ?? null) ? trim($post[$campo]) : '';
    }
}
