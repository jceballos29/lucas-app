#!/usr/bin/env bash
#
# Prueba de la imagen PHP de PRODUCCIÓN (B1 de docs/AUDITORIA.md): la construye desde docker/php/Dockerfile.prod y comprueba que el código
# va dentro y de solo lectura, que no trae herramientas de instalación ni Xdebug, que lo único escribible es storage/ y /tmp, que OPcache no
# revalida y que el autoload y las migraciones están. No necesita base de datos ni red (más allá de construir la imagen).
#
#     tests/imagen-produccion.sh        (la primera vez tarda unos minutos; luego la caché de Docker la deja en segundos)

set -u
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/.."

IMG="lucas-php-prod-test"
TMP="$(mktemp -d)"; passed=0; failed=0
cleanup() { rm -rf "$TMP"; docker image rm -f "$IMG" >/dev/null 2>&1; }
trap cleanup EXIT
check() { if [ "$2" = "$3" ]; then passed=$((passed + 1)); echo "  ok    $1"; else failed=$((failed + 1)); echo "  FALLO $1"; echo "        esperado: $2"; echo "        obtenido: $3"; fi; }
# Ejecuta algo en la imagen como lo hará producción: sistema de archivos de solo lectura, /tmp en memoria, sin capacidades extra.
en_imagen() { docker run --rm --read-only --tmpfs /tmp:mode=1777 --cap-drop ALL --security-opt no-new-privileges:true -e PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/usr/local/etc/php/env/production "$@"; }

echo; echo "== Imagen de producción (B1)"
mkdir -p "$TMP/storage"
BUILD="$(docker build -q -f docker/php/Dockerfile.prod --build-arg HOST_UID="$(id -u)" --build-arg HOST_GID="$(id -g)" -t "$IMG" . 2>&1)"; CODE=$?
check "la imagen se construye" "0" "$CODE"
[ "$CODE" = "0" ] || { echo "$BUILD" | tail -15; echo; echo "Resultado: $passed correctas, $failed fallidas"; exit 1; }

check "corre como www-data, no como root" "www-data" "$(en_imagen --entrypoint id "$IMG" -un)"
check "no trae git, composer, unzip ni el instalador de extensiones" "" "$(en_imagen --entrypoint sh "$IMG" -c 'command -v git composer unzip install-php-extensions' 2>/dev/null)"
check "no tiene Xdebug" "0" "$(en_imagen --entrypoint php "$IMG" -m | grep -ci xdebug)"
check "tiene las extensiones que la aplicación necesita (pdo_mysql, intl, mbstring, sodium, opcache)" "5" "$(en_imagen --entrypoint php "$IMG" -m | sort -u | grep -c -x -E 'pdo_mysql|intl|mbstring|sodium|Zend OPcache')"
check "PHP 8.4" "8.4" "$(en_imagen --entrypoint php "$IMG" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"

echo; echo "== El código es de solo lectura; solo storage/ y /tmp se pueden escribir"
check "no se puede crear un archivo dentro de la aplicación (app/)" "no" "$(en_imagen --entrypoint sh "$IMG" -c 'touch /var/www/html/app/x.php 2>/dev/null && echo si || echo no')"
check "no se puede crear una página en public/ (una web shell)" "no" "$(en_imagen --entrypoint sh "$IMG" -c 'touch /var/www/html/public/shell.php 2>/dev/null && echo si || echo no')"
check "no se puede modificar un archivo existente (bootstrap.php)" "no" "$(en_imagen --entrypoint sh "$IMG" -c '(echo "<?php //" >> /var/www/html/bootstrap.php) 2>/dev/null && echo si || echo no')"
check "ni siquiera como el dueño de los archivos: son de root" "root" "$(en_imagen --entrypoint stat "$IMG" -c %U /var/www/html/bootstrap.php)"
check "ningún archivo del código tiene permiso de escritura para otros ni para el grupo" "0" "$(en_imagen --entrypoint sh "$IMG" -c 'find /var/www/html /var/www/database -path /var/www/html/storage -prune -o -perm /022 -print | wc -l' | tr -d ' ')"
check "el sistema de archivos del contenedor es de solo lectura (no se puede escribir en /etc)" "no" "$(en_imagen --entrypoint sh "$IMG" -c 'touch /etc/x 2>/dev/null && echo si || echo no')"
check "sí se puede escribir en storage/ cuando está montado (los comprobantes)" "si" "$(en_imagen -v "$TMP/storage:/var/www/html/storage" --entrypoint sh "$IMG" -c 'mkdir -p /var/www/html/storage/adjuntos/2026/11 && echo x > /var/www/html/storage/adjuntos/2026/11/a && echo si || echo no')"
check "y ese archivo queda en el volumen del host" "x" "$(cat "$TMP/storage/adjuntos/2026/11/a" 2>/dev/null)"
check "y en /tmp (las sesiones de PHP)" "si" "$(en_imagen --entrypoint sh "$IMG" -c 'echo x > /tmp/sess && echo si || echo no')"

echo; echo "== Contenido de la imagen"
check "OPcache no revalida archivos (validate_timestamps = 0)" "0" "$(en_imagen --entrypoint php "$IMG" -r 'echo ini_get("opcache.validate_timestamps");')"
check "no se muestran errores al visitante (display_errors apagado)" "" "$(en_imagen --entrypoint php "$IMG" -r 'echo ini_get("display_errors");')"
check "el autoload optimizado funciona (carga una clase de la aplicación)" "si" "$(en_imagen --entrypoint php "$IMG" -r 'require "/var/www/html/vendor/autoload.php"; echo class_exists("App\Security\ConfiguracionProduccion") ? "si" : "no";')"
check "el autoload es «classmap authoritative» (solo clases del mapa, no busca en disco)" "si" "$(en_imagen --entrypoint sh "$IMG" -c 'grep -q "setClassMapAuthoritative(true)" /var/www/html/vendor/composer/autoload_real.php && echo si || echo no')"
check "las migraciones están donde las busca scripts/migrate.php" "si" "$(en_imagen --entrypoint sh "$IMG" -c 'ls /var/www/database/migrations/*.sql >/dev/null 2>&1 && ls /var/www/database/migrations | grep -q 016-indices && echo si || echo no')"
check "no lleva pruebas, .env, documentación ni la base de datos del repositorio" "0" "$(en_imagen --entrypoint sh "$IMG" -c 'ls -d /var/www/html/tests /var/www/html/.env /var/www/html/vendor/bin/phpstan /var/www/docs /var/www/database/01-schema.sql 2>/dev/null | wc -l' | tr -d ' ')"
check "no lleva herramientas de desarrollo (PHPStan, PHP CS Fixer)" "0" "$(en_imagen --entrypoint sh "$IMG" -c 'ls -d /var/www/html/vendor/phpstan /var/www/html/vendor/friendsofphp 2>/dev/null | wc -l' | tr -d ' ')"
check "la configuración de PHP-FPM es válida" "0" "$(en_imagen -e PHP_FPM_MAX_CHILDREN=10 -e PHP_FPM_START_SERVERS=2 -e PHP_FPM_MIN_SPARE=1 -e PHP_FPM_MAX_SPARE=3 --entrypoint php-fpm "$IMG" -t >/dev/null 2>&1; echo $?)"

echo; echo "Resultado: $passed correctas, $failed fallidas"
[ "$failed" -eq 0 ]
