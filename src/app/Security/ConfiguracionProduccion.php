<?php

declare(strict_types=1);

namespace App\Security;

use App\Mail\SmtpMailer;

/**
 * Comprueba que la configuración de un despliegue NO arrastra valores de desarrollo ni claves débiles (B10 de docs/AUDITORIA.md).
 * `.env.example` y `docker-compose.yml` traen claves públicas de desarrollo (`lucas_app_dev_2026`…); el compose de producción exige que
 * las variables existan, pero no que sean otras. Es puro (recibe el entorno como un array) para probarlo sin tocar nada.
 *
 * Se usa en dos sitios: `salud.php` (la aplicación se declara no disponible fuera de `local` si su clave de base de datos o `APP_KEY` son
 * las de desarrollo, igual que con el administrador de la seed, C1) y `scripts/comprobar-config.php` / `bin/comprobar-produccion`, que
 * revisan TODO el `.env` (también las claves de root y de migraciones, que la aplicación no ve) antes del primer arranque.
 */
final class ConfiguracionProduccion
{
    /** Claves públicas que traen el repositorio y los compose de desarrollo. */
    public const CLAVES_DE_DESARROLLO = [
        'lucas_app_dev_2026',
        'lucas_migrator_dev_2026',
        'lucas_root_dev_2026',
        'lucas-clave-de-desarrollo-no-usar-en-produccion',
    ];

    /** Largo mínimo de una clave de base de datos (el de APP_KEY lo fija CifradoSecreto: 32). */
    public const LARGO_MINIMO = 12;

    /** Palabras que delatan una clave de ejemplo (como palabra suelta: «x_dev_1» sí, «developer» o un azar con «dev» dentro, no). */
    private const PALABRAS_DE_EJEMPLO = '/(?<![a-z])(dev|test|demo|example|ejemplo|changeme|cambiame|cambiar|password|contrasena|contraseña|secret|admin)(?![a-z])/i';

    /** @return string|null Por qué la clave no sirve para producción, o null si está bien. */
    public static function claveDebil(string $valor, int $minimo = self::LARGO_MINIMO): ?string
    {
        if ($valor === '') {
            return 'está vacía';
        }

        if (in_array($valor, self::CLAVES_DE_DESARROLLO, true)) {
            return 'es la clave pública de desarrollo (viene en el repositorio)';
        }

        if (mb_strlen($valor) < $minimo) {
            return "tiene menos de $minimo caracteres";
        }

        if (preg_match(self::PALABRAS_DE_EJEMPLO, $valor) === 1) {
            return 'parece una clave de ejemplo (lleva «dev», «test», «password»…)';
        }

        if (count(array_unique(mb_str_split($valor))) < 6) {
            return 'repite muy pocos caracteres';
        }

        return null;
    }

    /**
     * Problemas de un entorno de producción. Solo revisa las variables que estén en `$env` (la aplicación solo ve DB_PASSWORD y APP_KEY;
     * el script de comprobación pasa el `.env` completo).
     *
     * @param array<string, string> $env
     * @return list<string> Mensajes para el operador (sin incluir nunca el valor de una clave).
     */
    public static function problemas(array $env): array
    {
        $problemas = [];

        foreach (['DB_PASSWORD' => 'la clave de la base de datos de la aplicación', 'DB_MIGRATION_PASSWORD' => 'la clave del usuario de migraciones', 'DB_ROOT_PASSWORD' => 'la clave de root de MySQL'] as $nombre => $que) {
            if (isset($env[$nombre]) && ($motivo = self::claveDebil($env[$nombre])) !== null) {
                $problemas[] = "$nombre ($que) $motivo.";
            }
        }

        if (isset($env['APP_KEY']) && ($motivo = self::claveDebil($env['APP_KEY'], 32)) !== null) {
            $problemas[] = "APP_KEY (cifra los secretos del 2FA) $motivo. Genera una con: openssl rand -base64 32";
        }

        $claves = array_filter(array_intersect_key($env, array_flip(['DB_PASSWORD', 'DB_MIGRATION_PASSWORD', 'DB_ROOT_PASSWORD'])), static fn (string $v): bool => $v !== '');
        if (count($claves) > count(array_unique($claves))) {
            $problemas[] = 'Dos de las claves de base de datos (aplicación, migraciones, root) son iguales: cada rol debe tener la suya.';
        }

        if (isset($env['APP_ORIGIN'])) {
            $origen = strtolower($env['APP_ORIGIN']);
            if (!str_starts_with($origen, 'https://')) {
                $problemas[] = 'APP_ORIGIN no empieza por https://: la cookie de sesión no sería «Secure» y se aceptarían formularios por HTTP.';
            } elseif (preg_match('#^https://(localhost|127\.|0\.0\.0\.0|\[::1\])#', $origen) === 1) {
                $problemas[] = 'APP_ORIGIN apunta a localhost: pon el dominio público.';
            }
        }

        if (isset($env['MAIL_ENCRYPTION']) && ($motivo = SmtpMailer::problemaDeConfiguracion(strtolower($env['MAIL_ENCRYPTION'] ?: 'none'), $env['MAIL_USER'] ?? '', $env['MAIL_PASSWORD'] ?? '', 'production')) !== null) {
            $problemas[] = $motivo;
        }

        return $problemas;
    }
}
