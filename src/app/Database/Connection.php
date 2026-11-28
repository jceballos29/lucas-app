<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Environment;
use App\Exceptions\DatabaseConnectionException;
use PDO;
use Pdo\Mysql;
use PDOException;

/**
 * Construye la conexión PDO a partir de las variables de entorno. Es lo único que sabe CÓMO se conecta la
 * aplicación; quien la necesita la recibe ya hecha (inyección de dependencias: ver UserRepository).
 */
final class Connection
{
    /**
     * @throws DatabaseConnectionException Si no se puede conectar.
     * @throws \RuntimeException Si falta una variable de entorno.
     */
    public static function create(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Environment::get('DB_HOST'),
            Environment::get('DB_PORT'),
            Environment::get('DB_DATABASE'),
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // rowCount() de un UPDATE cuenta las filas que coinciden con el WHERE, no solo las que cambian
            // de valor. Sin esto, guardar sin modificar nada daría 0, igual que un id que no existe.
            // Pdo\Mysql existe desde PHP 8.4; PDO::MYSQL_ATTR_FOUND_ROWS está obsoleta desde PHP 8.5.
            Mysql::ATTR_FOUND_ROWS => true,
            // Máximo de segundos para conectar. Sin esto, una BD que no responde (colgada, no parada) hace
            // esperar 30 s a cada petición y agota los procesos de PHP-FPM; con esto falla pronto con un 503.
            PDO::ATTR_TIMEOUT => 5,
            // La sesión de MySQL trabaja siempre en UTC, sea cual sea la configuración del servidor: las
            // fechas se guardan en UTC y se convierten a la zona de la aplicación al mostrarlas
            // (format_utc_datetime() en app/support.php).
            Mysql::ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ];

        try {
            return new PDO($dsn, Environment::get('DB_USERNAME'), Environment::get('DB_PASSWORD'), $options);
        } catch (PDOException $e) {
            throw new DatabaseConnectionException('Error al conectar a la base de datos', (int) $e->getCode(), $e);
        }
    }
}
