# Versión de parche fijada: una reconstrucción no cambia de PHP sin avisar. Para actualizar, sube el número
# a propósito (y vuelve a pasar las pruebas). Nota: Pdo\Mysql, que usa App\Database\Connection, existe desde PHP 8.4.
FROM php:8.4.27-fpm

ARG HOST_UID=1000
ARG HOST_GID=1000

COPY --from=ghcr.io/mlocati/php-extension-installer:2.13.1 /usr/bin/install-php-extensions /usr/local/bin/

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql intl zip opcache xdebug

RUN usermod -u ${HOST_UID} www-data && groupmod -g ${HOST_GID} www-data

COPY php.ini /usr/local/etc/php/conf.d/99-app.ini
# Ajustes de desarrollo: solo se cargan si APP_ENV=local (ver PHP_INI_SCAN_DIR en docker-compose.yml)
COPY php.local.ini /usr/local/etc/php/env/local/99-dev.ini
COPY xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini

COPY entrypoint.sh /usr/local/bin/app-entrypoint.sh

WORKDIR /var/www/html

USER www-data

# Genera vendor/ (autoload de Composer) si falta, y sigue con el arranque normal de la imagen (php-fpm).
ENTRYPOINT ["app-entrypoint.sh"]
CMD ["php-fpm"]
