#!/bin/sh
# Arranque del contenedor php: si falta vendor/ (el autoload de Composer, que no se sube a git) lo genera antes de
# lanzar PHP-FPM. No hay dependencias de terceros: `composer install` solo escribe vendor/autoload.php y el mapa
# de clases, y no necesita red. Luego sigue con el entrypoint normal de la imagen.
set -e

if [ -f /var/www/html/composer.json ] && [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "entrypoint: falta vendor/, ejecutando composer install..."
    (
        cd /var/www/html
        COMPOSER_HOME=/tmp/composer COMPOSER_CACHE_DIR=/tmp/composer/cache \
            composer install --no-interaction --no-progress --no-scripts --no-plugins --quiet
    ) || echo "entrypoint: composer install falló; ejecuta: docker compose exec php composer install" >&2
fi

exec docker-php-entrypoint "$@"

