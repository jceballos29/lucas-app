<?php

declare(strict_types=1);

/**
 * Arnés mínimo y común de las pruebas (unit.php e integration.php): sin dependencias, sin PHPUnit.
 * Cuando el proyecto crezca (M8), estos mismos casos se trasladan tal cual a PHPUnit.
 */

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function section(string $title): void
{
    echo PHP_EOL . "== $title" . PHP_EOL;
}

function same(string $description, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        $GLOBALS['passed']++;
        echo "  ok    $description" . PHP_EOL;
        return;
    }

    $GLOBALS['failed']++;
    echo "  FALLO $description" . PHP_EOL;
    echo '        esperado: ' . var_export($expected, true) . PHP_EOL;
    echo '        obtenido: ' . var_export($actual, true) . PHP_EOL;
}

/** Imprime el resultado y sale con 0 si todo pasó o con 1 si algo falló. */
function finish(): never
{
    echo PHP_EOL . "Resultado: {$GLOBALS['passed']} correctas, {$GLOBALS['failed']} fallidas" . PHP_EOL;
    exit($GLOBALS['failed'] === 0 ? 0 : 1);
}
