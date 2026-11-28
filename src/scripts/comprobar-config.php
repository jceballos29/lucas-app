<?php

declare(strict_types=1);

/**
 * Revisa un archivo `.env` de PRODUCCIÓN antes de arrancar (B10 de docs/AUDITORIA.md): claves de desarrollo o débiles, APP_ORIGIN sin
 * HTTPS, SMTP sin cifrar con credenciales... Nunca imprime una clave. Sale con 1 si hay algún problema.
 *
 *     bin/comprobar-produccion            # lo normal: lee .env y lo revisa dentro del contenedor php
 *
 * (A mano: php scripts/comprobar-config.php /ruta/a/.env)
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Security\ConfiguracionProduccion;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$archivo = $argv[1] ?? '';
if ($archivo === '' || !is_readable($archivo)) {
    fwrite(STDERR, "Uso: php scripts/comprobar-config.php /ruta/a/.env\n");
    exit(2);
}

// .env no es un script de shell: se lee línea a línea (KEY=VALOR, sin ejecutar nada), se quita \r y las comillas que lo envuelvan.
$env = [];
foreach (file($archivo, FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
    $linea = trim($linea);
    if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
        continue;
    }
    [$clave, $valor] = explode('=', $linea, 2);
    $env[trim($clave)] = trim($valor, " \t\"'");
}

// Lo que el compose de producción rellena solo si falta; aquí se revisa lo que de verdad quedará en uso.
foreach (['APP_ORIGIN', 'APP_KEY', 'DB_PASSWORD', 'DB_MIGRATION_PASSWORD', 'DB_ROOT_PASSWORD', 'APP_DOMAIN', 'MAIL_HOST', 'MAIL_FROM'] as $obligatoria) {
    if (!isset($env[$obligatoria]) || $env[$obligatoria] === '') {
        $faltan[] = $obligatoria;
    }
}
$problemas = array_map(static fn (string $v): string => "Falta $v en el .env.", $faltan ?? []);
$problemas = [...$problemas, ...ConfiguracionProduccion::problemas($env)];

if ($problemas === []) {
    echo "✓ La configuración de producción no arrastra valores de desarrollo ni claves débiles.\n";
    exit(0);
}

foreach ($problemas as $p) {
    echo "✗ $p\n";
}
echo "\nCorrige el .env y vuelve a ejecutarlo. Ayuda: docs/DESPLIEGUE.md, sección «Configuración».\n";
exit(1);
