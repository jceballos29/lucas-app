<?php

declare(strict_types=1);

/**
 * Quita el segundo factor (2FA) de una cuenta desde la consola (M7): para quien perdió el teléfono Y los códigos de recuperación.
 * Hay que tener acceso al servidor; es la recuperación de último recurso, así que verifica la identidad de la persona por otro medio antes.
 *
 *     bin/as-migrator php scripts/desactivar-2fa.php correo@ejemplo.com
 *
 * Además cierra las sesiones abiertas de esa cuenta. Si es un administrador, tendrá que volver a activarlo antes de usar la administración.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Container;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$correo = mb_strtolower(trim($argv[1] ?? ''));

if ($correo === '') {
    fwrite(STDERR, "Uso: bin/as-migrator php scripts/desactivar-2fa.php correo@ejemplo.com\n");
    exit(2);
}

$container = Container::shared();
$usuario = $container->usuarios()->findByEmail($correo);

if ($usuario === null) {
    fwrite(STDERR, "No existe ninguna cuenta con ese correo.\n");
    exit(1);
}

if (!$usuario->dosPasos) {
    echo "Esa cuenta no tiene la verificación en dos pasos activada.\n";
    exit(0);
}

(new App\Repository\DosPasosRepository($container->pdo()))->desactivar($usuario->id);
$container->pdo()->prepare('DELETE FROM sesiones_usuario WHERE id_usuario = :u')->execute(['u' => $usuario->id]);

echo "Listo: se quitó la verificación en dos pasos de {$usuario->correo} y se cerraron sus sesiones.\n";
