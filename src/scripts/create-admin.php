<?php

declare(strict_types=1);

/**
 * Crea un administrador desde la consola (RNF-23): para cualquier entorno que no sea tu máquina, donde no debe usarse
 * el admin de la seed. Pide la contraseña por la terminal (no queda en el historial ni en la lista de procesos).
 *
 *     bin/as-migrator php scripts/create-admin.php correo@ejemplo.com ["Nombre completo"]
 *
 * El rol de administrador solo se puede fijar aquí (o por la seed de desarrollo), nunca desde un formulario (RF-25).
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Container;
use App\Domain\Usuario;
use App\Validation\RegistroValidator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$correo = $argv[1] ?? '';
$nombre = $argv[2] ?? 'Administrador';

if ($correo === '') {
    fwrite(STDERR, "Uso: bin/as-migrator php scripts/create-admin.php correo@ejemplo.com [\"Nombre\"]\n");
    exit(2);
}

$interactive = function_exists('posix_isatty') && posix_isatty(STDIN);

if ($interactive) {
    fwrite(STDOUT, 'Contraseña (mínimo ' . RegistroValidator::CLAVE_MIN . ' caracteres, no se muestra): ');
    shell_exec('stty -echo');
    $clave = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    fwrite(STDOUT, PHP_EOL);
} else {
    $clave = rtrim((string) fgets(STDIN), "\r\n"); // Para automatizar: la contraseña llega por la entrada estándar.
}

$result = (new RegistroValidator())->validate(['nombre' => $nombre, 'correo' => $correo, 'clave' => $clave, 'clave2' => $clave]);

if ($result['errors'] !== []) {
    foreach ($result['errors'] as $message) {
        fwrite(STDERR, "- $message\n");
    }
    exit(1);
}

['nombre' => $nombre, 'correo' => $correo] = $result['data'];
$id = Container::shared()->usuarios()->create($nombre, $correo, password_hash($clave, PASSWORD_DEFAULT), Usuario::ROL_ADMIN, true);

if ($id === null) {
    fwrite(STDERR, "Ya existe un usuario con el correo $correo.\n");
    exit(1);
}

fwrite(STDOUT, "Administrador creado: $correo (id $id).\n");
