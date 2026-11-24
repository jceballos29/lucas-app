<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Http\Response;
use App\Logging\Logger;
use App\View\View;
use PDOException;
use RuntimeException;

/** Diagnóstico del stack (status.php): solo en desarrollo. */
final class StatusController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function show(): void
    {
        // Enseña versiones exactas de PHP y de MySQL, información que un atacante usaría para buscar
        // vulnerabilidades, así que fuera de APP_ENV=local responde como si no existiera.
        if (getenv('APP_ENV') !== 'local') {
            Response::plain(404, "No encontrado.\n");
        }

        $database = $this->checkDatabase();

        if (!$database['ok']) {
            http_response_code(503);
        }

        View::page('status', [
            'requiredExtensions' => ['mbstring', 'intl', 'pdo_mysql', 'zip', 'Zend OPcache'],
            'database' => $database,
        ], 'Estado del stack', null);
    }

    /**
     * Comprueba la conexión a la base de datos con la misma conexión que usa la aplicación.
     *
     * @return array{ok: bool, message: string}
     */
    private function checkDatabase(): array
    {
        try {
            $version = (string) $this->container->pdo()->query('SELECT VERSION()')->fetchColumn();

            return ['ok' => true, 'message' => "Conectado a MySQL $version"];
        } catch (RuntimeException | PDOException $error) {
            // No se muestra el detalle técnico en pantalla: se registra.
            Logger::exception('connection', $error);

            return ['ok' => false, 'message' => 'No se pudo conectar a la base de datos'];
        }
    }
}
