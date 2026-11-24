<?php

declare(strict_types=1);

/**
 * Aplica las migraciones pendientes de database/migrations/*.sql, en orden, y las anota en la tabla `migraciones`.
 * Cada migración debe ser idempotente (CREATE TABLE IF NOT EXISTS...): una base NUEVA ya trae todo en 01-schema.sql.
 *
 *     bin/as-migrator php scripts/migrate.php
 *
 * Necesita el usuario de migraciones (la aplicación no puede cambiar el esquema). Después de una migración que añada una
 * tabla, ejecuta 03-users.sh para dar permisos a la aplicación sobre ella (ver el aviso que imprime este script).
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Container;
use App\Database\SqlScript;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pdo = Container::shared()->pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS migraciones (nombre VARCHAR(120) PRIMARY KEY, aplicada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');

$hechas = $pdo->query('SELECT nombre FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
$archivos = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];
sort($archivos);
$aplicadas = 0;

foreach ($archivos as $archivo) {
    $nombre = basename($archivo);

    if (in_array($nombre, $hechas, true)) {
        continue;
    }

    foreach (SqlScript::statements((string) file_get_contents($archivo)) as $sentencia) {
        $pdo->exec($sentencia);
    }

    $pdo->prepare('INSERT INTO migraciones (nombre) VALUES (?)')->execute([$nombre]);
    fwrite(STDOUT, "Aplicada: $nombre\n");
    $aplicadas++;
}

fwrite(STDOUT, $aplicadas === 0 ? "No hay migraciones pendientes.\n" : "Listo ($aplicadas). Si añadieron tablas, ejecuta: docker compose exec db bash /docker-entrypoint-initdb.d/03-users.sh\n");
