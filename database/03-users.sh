#!/bin/bash
#
# Crea los usuarios de la base de datos con el MÍNIMO PRIVILEGIO (Mejora 7). Se ejecuta solo cuando el volumen
# de MySQL está vacío (después de 01-schema.sql y 02-seed.sql: los permisos por tabla exigen que las tablas ya
# existan), y también se puede volver a ejecutar a mano, es idempotente:
#
#   docker compose exec db bash /docker-entrypoint-initdb.d/03-users.sh
#
# Por qué es un .sh y no un .sql: un script SQL no puede leer variables de entorno (las claves viven en .env y
# no deben escribirse en un archivo del repositorio). La imagen de MySQL ejecuta los .sh de
# docker-entrypoint-initdb.d con las variables del contenedor a la vista; lo que se ve aquí viene de
# docker-compose.yml (servicio db). La imagen también crearía un usuario con MYSQL_USER, pero le daría ALL
# sobre la base: por eso el compose ya no lo usa y los usuarios se crean aquí.
#
# Dos usuarios, con propósitos distintos:
#
#   DB_USERNAME            Usuario de la APLICACIÓN (lo usa PHP en cada petición). Solo SELECT, INSERT, UPDATE y
#                          DELETE sobre las tablas de Lucas (APP_TABLES). DELETE hace falta porque Lucas borra
#                          de verdad movimientos, categorías, aportes y la cuenta del usuario (RF-13, RF-09, RNF-20).
#                          NO puede: DROP/ALTER/TRUNCATE/CREATE, tablas temporales, ni tocar otras bases ni los
#                          usuarios de MySQL. Al añadir una tabla nueva, agrégala a APP_TABLES.
#
#   DB_MIGRATION_USERNAME  Usuario de MIGRACIONES y tareas manuales (migraciones, scripts, pruebas). Todos los
#                          privilegios sobre la base de la aplicación y sobre las bases temporales de pruebas
#                          (<base>_test%), y nada más (ni GRANT OPTION, ni otras bases).
#                          La aplicación NO conoce su clave: no se le pasa al contenedor php (ver bin/as-migrator).
#
# Si DB_MIGRATION_PASSWORD no está definida no se crea el segundo usuario (y se avisa).

set -eu

: "${MYSQL_ROOT_PASSWORD:?falta MYSQL_ROOT_PASSWORD}"
: "${MYSQL_DATABASE:?falta MYSQL_DATABASE}"
: "${DB_USERNAME:?falta DB_USERNAME}"
: "${DB_PASSWORD:?falta DB_PASSWORD}"
MIGRATION_USER="${DB_MIGRATION_USERNAME:-lucas_migrator}"

# Tablas de la aplicación (database/01-schema.sql).
APP_TABLES="usuarios cuentas categorias transacciones presupuestos metas_ahorro aportes_meta intentos_acceso restablecimientos"
MIGRATION_PASSWORD="${DB_MIGRATION_PASSWORD:-}"

# Los nombres van dentro de SQL (no se pueden enviar como parámetros): solo letras, números y guion bajo.
for name in "$MYSQL_DATABASE" "$DB_USERNAME" "$MIGRATION_USER"; do
    case "$name" in
        '' | *[!A-Za-z0-9_]*) echo "03-users.sh: nombre no válido «$name» (solo letras, números y _)" >&2; exit 1 ;;
    esac
done

if [ "$DB_USERNAME" = "$MIGRATION_USER" ]; then
    echo "03-users.sh: DB_USERNAME y DB_MIGRATION_USERNAME no pueden ser el mismo usuario" >&2
    exit 1
fi
if [ "$DB_USERNAME" = root ] || [ "$MIGRATION_USER" = root ]; then
    echo "03-users.sh: la aplicación y las migraciones no deben usar root" >&2
    exit 1
fi

# Una clave dentro de comillas simples de SQL: se escapan la barra invertida y la comilla.
sql_string() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g"; }
APP_PW="$(sql_string "$DB_PASSWORD")"
MIG_PW="$(sql_string "$MIGRATION_PASSWORD")"

{
    cat <<SQL
CREATE USER IF NOT EXISTS '$DB_USERNAME'@'%' IDENTIFIED BY '$APP_PW';
ALTER USER '$DB_USERNAME'@'%' IDENTIFIED BY '$APP_PW';
-- Se parte de cero (también sirve para un usuario que ya existía con ALL): se revoca todo y se concede lo mínimo.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$DB_USERNAME'@'%';
SQL
    for table in $APP_TABLES; do
        echo "GRANT SELECT, INSERT, UPDATE, DELETE ON \`$MYSQL_DATABASE\`.\`$table\` TO '$DB_USERNAME'@'%';"
    done
    if [ -n "$MIGRATION_PASSWORD" ]; then
        cat <<SQL
CREATE USER IF NOT EXISTS '$MIGRATION_USER'@'%' IDENTIFIED BY '$MIG_PW';
ALTER USER '$MIGRATION_USER'@'%' IDENTIFIED BY '$MIG_PW';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$MIGRATION_USER'@'%';
GRANT ALL PRIVILEGES ON \`$MYSQL_DATABASE\`.* TO '$MIGRATION_USER'@'%';
-- Bases temporales de las pruebas de integración (tests/integration.php): solo las que empiezan por «<base>_test».
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\_test%\`.* TO '$MIGRATION_USER'@'%';
SQL
    else
        echo "03-users.sh: aviso: DB_MIGRATION_PASSWORD vacía; no se crea el usuario de migraciones" >&2
    fi
    echo "FLUSH PRIVILEGES;"
} | mysql -uroot -p"$MYSQL_ROOT_PASSWORD"

echo "03-users.sh: usuario de la aplicación «$DB_USERNAME» con permisos mínimos${MIGRATION_PASSWORD:+ y usuario de migraciones «$MIGRATION_USER»}."
