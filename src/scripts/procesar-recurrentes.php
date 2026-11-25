<?php

declare(strict_types=1);

/**
 * Procesa por línea de comandos (cron diario) todos los movimientos recurrentes automáticos cuya `proxima_fecha` ya llegó.
 *
 *     docker compose exec -T php php scripts/procesar-recurrentes.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Container;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$hoy = $argv[1] ?? date('Y-m-d');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hoy) !== 1) {
    fwrite(STDERR, "Fecha no válida: $hoy (usa YYYY-MM-DD)\n");
    exit(1);
}

$container = Container::shared();
$usuarios = $container->recurrentes()->usersWithDueAuto($hoy);
$totalAplicados = 0;
$totalRetenidos = 0;

foreach ($usuarios as $userId) {
    $res = $container->gestionRecurrentes()->procesarVencidos($userId, $hoy);
    $totalAplicados += $res['aplicados'];
    $totalRetenidos += $res['retenidosPorSaldo'];
}

foreach ($container->recurrentes()->espaciosWithDueAuto($hoy) as $item) {
    $res = $container->gestionRecurrentes()->procesarVencidos($item['id_usuario'], $hoy, $item['id_espacio']);
    $totalAplicados += $res['aplicados'];
    $totalRetenidos += $res['retenidosPorSaldo'];
}

fwrite(STDOUT, "Recurrentes procesados ($hoy): $totalAplicados aplicados, $totalRetenidos retenidos por saldo/cuenta.\n");
