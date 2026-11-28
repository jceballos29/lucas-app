#!/bin/bash
#
# Crea el administrador de DESARROLLO (admin@lucas.local, clave pública) solo si APP_ENV=local (RNF-23, hallazgo C1 de
# docs/AUDITORIA.md). Sin esa variable, o con cualquier otro valor, NO hace nada: así un despliegue de producción con volumen
# vacío no nace con una cuenta de administrador cuya clave está publicada en el repositorio.
#
# La variable la define únicamente docker-compose.yml (desarrollo) en el servicio db; docker-compose.prod.yml no la pasa.
# Se ejecuta con el volumen vacío, después de 01-schema.sql, 02-seed.sql y 03-users.sh. Para ejecutarlo a mano en desarrollo:
#
#   docker compose exec -e APP_ENV=local db bash /docker-entrypoint-initdb.d/04-dev-admin.sh

set -eu

if [ "${APP_ENV:-}" != "local" ]; then
    echo "04-dev-admin.sh: APP_ENV='${APP_ENV:-}' (no es local): no se crea el administrador de desarrollo. Crea el tuyo con scripts/create-admin.php."
    exit 0
fi

: "${MYSQL_ROOT_PASSWORD:?falta MYSQL_ROOT_PASSWORD}"
: "${MYSQL_DATABASE:?falta MYSQL_DATABASE}"

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 "$MYSQL_DATABASE" < /docker-entrypoint-initdb.d/dev/admin.sql

echo "04-dev-admin.sh: administrador de desarrollo admin@lucas.local creado (clave pública: solo para tu máquina)."
