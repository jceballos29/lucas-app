<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * Qué tablas hay y cómo se borran los datos de un usuario o de un espacio compartido (R2 de docs/AUDITORIA.md). Antes esta lista estaba
 * copiada en tres sitios (`UsuarioRepository` ×2 y `EspacioRepository`) y podían divergir; ahora es una sola.
 *
 * El borrado va en un ORDEN explícito: `transacciones`, `pagos_deuda` y `aportes_meta` referencian `cuentas` sin cascada, así que no se
 * puede confiar en el orden de los `ON DELETE CASCADE`. Los métodos solo deben llamarse dentro de una transacción.
 *
 * Al añadir una tabla nueva: ponla en TODAS (y en `APP_TABLES` de database/03-users.sh) y, si tiene `id_usuario` o `id_espacio`, en
 * TABLAS_FINANCIERAS (con las dos columnas) o en POR_CASCADA (con FK `ON DELETE CASCADE`). Un test de integración lo comprueba contra la
 * base de datos real: olvidar una falla ahí y no con datos huérfanos en producción.
 */
final class Esquema
{
    /** Todas las tablas de la aplicación (database/01-schema.sql). */
    public const TODAS = [
        'usuarios', 'espacios', 'espacio_miembros', 'espacio_invitaciones', 'cuentas', 'categorias', 'transacciones', 'cuotas_tarjeta',
        'presupuestos', 'metas_ahorro', 'aportes_meta', 'deudas', 'pagos_deuda', 'transferencias', 'tasas_cambio', 'movimientos_recurrentes',
        'intentos_acceso', 'restablecimientos', 'sesiones_usuario', 'notificaciones_enviadas', 'verificaciones_correo',
        'codigos_recuperacion', 'cola_correo',
    ];

    /**
     * Datos financieros de un usuario O de un espacio (llevan `id_usuario` e `id_espacio`), en orden seguro de borrado:
     * los que dependen de `cuentas` primero y `cuentas`/`categorias` al final.
     */
    public const TABLAS_FINANCIERAS = [
        'movimientos_recurrentes', 'tasas_cambio', 'transferencias', 'transacciones', 'presupuestos', 'deudas', 'metas_ahorro', 'cuentas', 'categorias',
    ];

    /** Se borran solas por `ON DELETE CASCADE` desde `usuarios` o `espacios` (sin lista explícita). */
    public const POR_CASCADA = ['notificaciones_enviadas', 'verificaciones_correo', 'codigos_recuperacion', 'cuotas_tarjeta'];

    /** Las que se borran explícitamente además de TABLAS_FINANCIERAS. */
    public const EXPLICITAS_EXTRA = ['pagos_deuda', 'aportes_meta', 'espacio_invitaciones', 'espacio_miembros', 'espacios', 'sesiones_usuario', 'restablecimientos', 'usuarios'];

    /** Borra todo lo de un espacio compartido y el propio espacio. */
    public static function borrarEspacio(PDO $pdo, int $espacioId): void
    {
        $sentencias = [
            ['DELETE FROM pagos_deuda WHERE id_deuda IN (SELECT id_deuda FROM deudas WHERE id_espacio = :a) OR id_cuenta IN (SELECT id_cuenta FROM cuentas WHERE id_espacio = :b)', ['a', 'b']],
            ['DELETE FROM aportes_meta WHERE id_meta IN (SELECT id_meta FROM metas_ahorro WHERE id_espacio = :a) OR id_cuenta IN (SELECT id_cuenta FROM cuentas WHERE id_espacio = :b)', ['a', 'b']],
        ];
        foreach (self::TABLAS_FINANCIERAS as $tabla) {
            $sentencias[] = ["DELETE FROM $tabla WHERE id_espacio = :a", ['a']];
        }
        $sentencias[] = ['DELETE FROM espacio_invitaciones WHERE id_espacio = :a', ['a']];
        $sentencias[] = ['DELETE FROM espacio_miembros WHERE id_espacio = :a', ['a']];
        $sentencias[] = ['DELETE FROM espacios WHERE id_espacio = :a', ['a']];

        self::ejecutar($pdo, $sentencias, $espacioId);
    }

    /** Borra los datos PERSONALES de un usuario (los que no están en un espacio) y al propio usuario. */
    public static function borrarPersonales(PDO $pdo, int $userId): void
    {
        $propio = 'id_usuario = :a AND id_espacio IS NULL';
        $sentencias = [
            ["DELETE FROM pagos_deuda WHERE id_deuda IN (SELECT id_deuda FROM deudas WHERE $propio) OR id_cuenta IN (SELECT id_cuenta FROM cuentas WHERE id_usuario = :b AND id_espacio IS NULL)", ['a', 'b']],
            ["DELETE FROM aportes_meta WHERE id_meta IN (SELECT id_meta FROM metas_ahorro WHERE $propio) OR id_cuenta IN (SELECT id_cuenta FROM cuentas WHERE id_usuario = :b AND id_espacio IS NULL)", ['a', 'b']],
        ];
        foreach (self::TABLAS_FINANCIERAS as $tabla) {
            $sentencias[] = ["DELETE FROM $tabla WHERE $propio", ['a']];
        }
        // Las invitaciones pendientes a SU correo (de espacios ajenos) también son un dato personal suyo (RNF-20, B8).
        $sentencias[] = ['DELETE FROM espacio_invitaciones WHERE correo = (SELECT correo FROM usuarios WHERE id_usuario = :a)', ['a']];
        foreach (['espacio_miembros', 'sesiones_usuario', 'restablecimientos'] as $tabla) {
            $sentencias[] = ["DELETE FROM $tabla WHERE id_usuario = :a", ['a']];
        }
        $sentencias[] = ['DELETE FROM usuarios WHERE id_usuario = :a', ['a']];

        self::ejecutar($pdo, $sentencias, $userId);
    }

    /** @param list<array{0: string, 1: list<string>}> $sentencias SQL y nombres de parámetro (todos con el mismo valor). */
    private static function ejecutar(PDO $pdo, array $sentencias, int $valor): void
    {
        foreach ($sentencias as [$sql, $nombres]) {
            $pdo->prepare($sql)->execute(array_fill_keys($nombres, $valor));
        }
    }
}
