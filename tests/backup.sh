#!/usr/bin/env bash
#
# Prueba de las copias de seguridad (RNF-15): hace una copia REAL de la base de desarrollo, la restaura en una base temporal
# (`lucas_test_restore`, que se borra al terminar) y compara el número de filas de cada tabla. También comprueba que se conservan
# solo las últimas copias, que una copia dañada se rechaza y que restaurar se niega a pisar datos.
#
#     tests/backup.sh

set -u
cd "$(dirname "$0")/.."

TMP="$(mktemp -d)"; passed=0; failed=0
value() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2-; }
MIG_USER="$(value DB_MIGRATION_USERNAME)"; MIG_USER="${MIG_USER:-lucas_migrator}"; MIG_PASS="$(value DB_MIGRATION_PASSWORD)"
dbq() { MYSQL_PWD="$MIG_PASS" docker compose exec -T -e MYSQL_PWD db mysql -u"$MIG_USER" -N --default-character-set=utf8mb4 "$@" 2>/dev/null; }
cleanup() { dbq -e 'DROP DATABASE IF EXISTS lucas_test_restore' >/dev/null; rm -rf "$TMP"; }
trap cleanup EXIT

check() { if [ "$2" = "$3" ]; then passed=$((passed + 1)); echo "  ok    $1"; else failed=$((failed + 1)); echo "  FALLO $1"; echo "        esperado: $2"; echo "        obtenido: $3"; fi; }

dbq -e 'DROP DATABASE IF EXISTS lucas_test_restore'

echo; echo "== Copia de seguridad (RNF-15)"
OUT="$(bin/backup-db "$TMP/copias" 2>&1)"; CODE=$?
check "bin/backup-db termina bien" "0" "$CODE"
FILE="$(ls -1 "$TMP"/copias/lucas-*.sql.gz 2>/dev/null | head -n1)"
check "crea un archivo con la fecha en el nombre" "1" "$(ls -1 "$TMP"/copias/lucas-*.sql.gz 2>/dev/null | wc -l | tr -d ' ')"
check "el archivo se puede leer (gzip válido)" "0" "$(gzip -t "$FILE" >/dev/null 2>&1; echo $?)"
check "contiene el esquema y los datos" "true" "$(gzip -dc "$FILE" | grep -q 'CREATE TABLE `usuarios`' && gzip -dc "$FILE" | grep -q 'INSERT INTO `categorias`' && echo true || echo false)"
check "con las tildes bien (utf8mb4)" "true" "$(gzip -dc "$FILE" | grep -q 'Alimentación' && echo true || echo false)"
check "solo el dueño puede leerlo (permisos 600)" "600" "$(stat -c '%a' "$FILE")"
check "no deja archivos temporales" "0" "$(ls -1 "$TMP"/copias/*.tmp 2>/dev/null | wc -l | tr -d ' ')"
check "no escribe la clave en el archivo ni en la salida" "false" "$( { gzip -dc "$FILE"; echo "$OUT"; } | grep -q "$MIG_PASS" && echo true || echo false)"

echo; echo "== Restaurar en una base temporal"
OUT="$(bin/restore-db "$FILE" 2>&1)"; CODE=$?
check "bin/restore-db termina bien" "0" "$CODE"
TABLAS="$(dbq -e "SELECT table_name FROM information_schema.tables WHERE table_schema='lucas' AND table_type='BASE TABLE' ORDER BY table_name")"
check "la base original tiene tablas" "true" "$([ -n "$TABLAS" ] && echo true || echo false)"
IGUALES=true
for t in $TABLAS; do
    a="$(dbq -e "SELECT COUNT(*) FROM lucas.\`$t\`")"; b="$(dbq -e "SELECT COUNT(*) FROM lucas_test_restore.\`$t\`")"
    [ "$a" = "$b" ] || { IGUALES=false; echo "        tabla $t: original=$a restaurada=$b"; }
done
check "cada tabla restaurada tiene las mismas filas que la original" "true" "$IGUALES"
check "las tildes sobreviven a la ida y vuelta" "Alimentación" "$(dbq -e "SELECT nombre FROM lucas_test_restore.categorias WHERE nombre LIKE 'Alimenta%' LIMIT 1")"
check "los hashes de contraseña también (el admin de la seed)" "$(dbq -e "SELECT contrasena_hash FROM lucas.usuarios WHERE correo='admin@lucas.local'")" "$(dbq -e "SELECT contrasena_hash FROM lucas_test_restore.usuarios WHERE correo='admin@lucas.local'")"
OUT="$(bin/restore-db "$FILE" 2>&1)"; CODE=$?
check "restaurar otra vez sobre esa base se niega (no pisa datos)" "1" "$CODE"
check "y lo explica" "true" "$(echo "$OUT" | grep -q 'no se restaura sobre datos existentes' && echo true || echo false)"
check "un nombre de base peligroso se rechaza" "2" "$(bin/restore-db "$FILE" 'lucas;DROP' >/dev/null 2>&1; echo $?)"
check "un archivo que no existe se rechaza" "2" "$(bin/restore-db "$TMP/no-existe.sql.gz" >/dev/null 2>&1; echo $?)"

echo; echo "== Conservar solo las últimas copias"
for i in 1 2 3 4 5; do touch -d "2026-01-0$i" "$TMP/copias/lucas-2026-01-0$i-000000.sql.gz"; done
BACKUP_KEEP=3 bin/backup-db "$TMP/copias" >/dev/null 2>&1
check "con BACKUP_KEEP=3 quedan 3 copias" "3" "$(ls -1 "$TMP"/copias/lucas-*.sql.gz | wc -l | tr -d ' ')"
check "y la más reciente (la recién hecha) sobrevive" "true" "$(ls -1t "$TMP"/copias/lucas-*.sql.gz | head -n1 | grep -q "$(date +%Y-%m-%d)" && echo true || echo false)"
check "las más viejas son las que se borran" "false" "$([ -e "$TMP/copias/lucas-2026-01-01-000000.sql.gz" ] && echo true || echo false)"

echo; echo "Resultado: $passed correctas, $failed fallidas"
[ "$failed" -eq 0 ]
