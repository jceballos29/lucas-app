<?php

declare(strict_types=1);

/**
 * Busca comprobantes huérfanos: archivos de storage/adjuntos que ninguna fila de `transacciones` referencia (por ejemplo, tras un
 * borrado interrumpido). Por defecto SOLO LISTA; con --borrar los elimina. Solo mira archivos de más de 24 horas (--horas=N para
 * cambiarlo) para no tocar una subida en curso. También avisa de filas que apuntan a un archivo que ya no existe.
 *
 *     docker compose exec -T php php scripts/huerfanos-adjuntos.php            # informe
 *     docker compose exec -T php php scripts/huerfanos-adjuntos.php --borrar   # borra los huérfanos
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Container;
use App\Support\AlmacenAdjuntos;
use App\Support\HuerfanosAdjuntos;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$borrar = in_array('--borrar', $argv, true);
$horas = 24;

foreach ($argv as $arg) {
    if (preg_match('/^--horas=(\d{1,5})$/', $arg, $m) === 1) {
        $horas = (int) $m[1];
    }
}

$enBd = Container::shared()->movimientos()->todasLasRutasAdjuntos();
$huerfanos = HuerfanosAdjuntos::huerfanos($enBd, $horas * 3600);
$faltantes = HuerfanosAdjuntos::faltantes($enBd);

echo 'Comprobantes en la base de datos: ' . count($enBd) . ', en disco: ' . count(HuerfanosAdjuntos::enDisco()) . PHP_EOL;
echo 'Huérfanos (en disco, sin fila, de más de ' . $horas . ' h): ' . count($huerfanos) . PHP_EOL;

foreach ($huerfanos as $ruta) {
    if ($borrar) {
        AlmacenAdjuntos::eliminar($ruta);
        echo "  borrado  $ruta" . PHP_EOL;
    } else {
        echo "  huérfano $ruta" . PHP_EOL;
    }
}

if ($huerfanos !== [] && !$borrar) {
    echo 'Para borrarlos: php scripts/huerfanos-adjuntos.php --borrar' . PHP_EOL;
}

if ($faltantes !== []) {
    echo 'Filas con un archivo que ya no existe: ' . count($faltantes) . ' (revisa si se perdió un volumen o una copia):' . PHP_EOL;
    foreach (array_slice($faltantes, 0, 20) as $ruta) {
        echo "  falta    $ruta" . PHP_EOL;
    }
}
