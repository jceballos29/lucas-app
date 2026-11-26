<?php

declare(strict_types=1);

/**
 * Envía por correo el resumen financiero mensual a todos los usuarios activos que lo activaron en su perfil.
 * Sin argumentos resume el MES ANTERIOR (pensado para un cron el día 1); acepta un mes AAAA-MM. No repite envíos.
 *
 *     docker compose exec -T php php scripts/enviar-resumen-mensual.php [AAAA-MM]
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Container;
use App\Mail\MailException;
use App\Support\Mes;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$arg = $argv[1] ?? null;

if ($arg === null) {
    $anterior = Mes::anterior((int) date('n'), (int) date('Y'));
    $mes = $anterior['mes'];
    $anio = $anterior['anio'];
} elseif (preg_match('/^(\d{4})-(\d{2})$/', $arg, $m) === 1 && Mes::valido((int) $m[2], (int) $m[1])) {
    $mes = (int) $m[2];
    $anio = (int) $m[1];
} else {
    fwrite(STDERR, "Mes no válido: $arg (usa AAAA-MM)\n");
    exit(1);
}

$container = Container::shared();

try {
    $resumen = $container->resumenMensual();
} catch (MailException $e) {
    fwrite(STDERR, 'No se puede enviar: ' . $e->getMessage() . "\n");
    exit(1);
}

$enviados = 0;
$omitidos = 0;

foreach ($container->usuarios()->listWithResumenMensual() as $usuario) {
    $resumen->enviar($usuario, $mes, $anio) ? $enviados++ : $omitidos++;
}

fwrite(STDOUT, sprintf("Resumen mensual %04d-%02d: %d enviados, %d omitidos (ya enviados o con error).\n", $anio, $mes, $enviados, $omitidos));
