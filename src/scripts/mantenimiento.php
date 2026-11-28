<?php

declare(strict_types=1);

/**
 * Limpieza diaria de lo que caduca (B9 de docs/AUDITORIA.md): enlaces de restablecimiento y de verificación vencidos, intentos de acceso
 * viejos, sesiones caducadas y el historial de la cola de correo. Antes solo se limpiaba "de pasada" y con probabilidad. El servicio `correo`
 * de Docker Compose lo ejecuta una vez al día; también se puede lanzar a mano:
 *
 *     docker compose exec -T php php scripts/mantenimiento.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Auth\LimitadorIntentos;
use App\Container;
use App\Logging\Logger;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

try {
    $c = Container::shared();

    $c->restablecimientos()->purge();
    $c->verificacionesCorreo()->purge();
    $c->intentos()->purgeOlderThan(LimitadorIntentos::VENTANA_SEGUNDOS * 2);
    $sesiones = $c->sesiones()->purgarTodasCaducadas();
    $cola = (new App\Repository\ColaCorreoRepository($c->pdo()))->purgar();
} catch (RuntimeException $e) {
    // N4: si la base de datos no responde, registra una sola línea limpia en vez de una traza fatal.
    Logger::exception('mantenimiento', $e);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Mantenimiento: $sesiones sesiones caducadas y $cola correos antiguos de la cola borrados; enlaces e intentos viejos purgados.\n";
