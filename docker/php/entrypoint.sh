#!/bin/sh
# Arranque del contenedor php: si falta vendor/ (el autoload de Composer, que no se sube a git) lo genera antes de
# lanzar PHP-FPM. No hay dependencias de terceros: `composer install` solo escribe vendor/autoload.php y el mapa
# de clases, y no necesita red. Luego sigue con el entrypoint normal de la imagen.
set -e

if [ -f /var/www/html/composer.json ] && [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "entrypoint: falta vendor/, ejecutando composer install..."
    (cd /var/www/html && composer install --no-interaction --no-progress --quiet) || echo "entrypoint: composer install falló; la aplicación no arrancará hasta que se ejecute a mano" >&2
fi

exec docker-php-entrypoint "$@"
