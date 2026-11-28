<?php

declare(strict_types=1);

function environment(string $name): string
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        fwrite(STDERR, "Falta la variable de entorno $name" . PHP_EOL);
        exit(1);
    }

    return $value;
}

// Sin dbname a propósito: el script lista las bases que el usuario puede ver, no entra en ninguna.
$dsn = sprintf(
    'mysql:host=%s;port=%s;charset=utf8mb4',
    environment('DB_HOST'),
    environment('DB_PORT'),
);

try {
    $pdo = new PDO($dsn, environment('DB_USERNAME'), environment('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo 'Bases de datos disponibles:' . PHP_EOL;
    foreach ($pdo->query('SHOW DATABASES') as $fila) {
        echo '- ' . $fila['Database'] . PHP_EOL;
    }
} catch (PDOException $e) {
    fwrite(STDERR, 'Error de conexión: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
