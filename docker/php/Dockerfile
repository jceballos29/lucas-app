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

# -o (--non-unique): en macOS el grupo primario (id -g) suele ser 20 (staff), que ya existe en Debian (dialout).
RUN usermod -u ${HOST_UID} -o www-data && groupmod -g ${HOST_GID} -o www-data

# Tamaño del pool de PHP-FPM (zz-pool.conf). Estos son los valores por defecto; se cambian con variables de entorno.
ENV PHP_FPM_MAX_CHILDREN=40 \
    PHP_FPM_START_SERVERS=10 \
    PHP_FPM_MIN_SPARE=5 \
    PHP_FPM_MAX_SPARE=15
COPY zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

COPY php.ini /usr/local/etc/php/conf.d/99-app.ini
# Ajustes de desarrollo: solo se cargan si APP_ENV=local (ver PHP_INI_SCAN_DIR en docker-compose.yml)
COPY php.local.ini /usr/local/etc/php/env/local/99-dev.ini
COPY xdebug.ini /usr/local/etc/php/env/local/99-xdebug.ini

COPY entrypoint.sh /usr/local/bin/app-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/app-entrypoint.sh && chmod 755 /usr/local/bin/app-entrypoint.sh

WORKDIR /var/www/html

USER www-data

# Se invoca con /bin/sh explícito: así el contenedor arranca en Windows incluso si el archivo perdió el bit de
# ejecución o tenía finales de línea CRLF antes de reconstruir.
ENTRYPOINT ["/bin/sh", "/usr/local/bin/app-entrypoint.sh"]
CMD ["php-fpm"]
