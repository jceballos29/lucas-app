#!/usr/bin/env bash
#
# Pruebas de humo de punta a punta con curl contra la aplicación en marcha (docker compose up):
# flujos de registro/login/logout, CSRF y origen, permisos por rol, 403/404 y limitación de intentos.
#
#     tests/smoke.sh
#
# Usa la BD de desarrollo: crea usuarios `smoke_*@correo.com` (correos al azar) y los borra al terminar; vacía `intentos_acceso`
# al empezar y al terminar para no chocar con la limitación. No toca los demás datos.

set -u
cd "$(dirname "$0")/.."

env_value() { [ -f .env ] && grep -E "^$1=" .env | tail -n1 | cut -d= -f2- || true; }
PORT="$(env_value APP_PORT)"; PORT="${PORT:-8080}"
ORIGIN="$(env_value APP_ORIGIN)"; ORIGIN="${ORIGIN:-http://localhost:$PORT}"
BASE="http://localhost:$PORT"
ADMIN_EMAIL="admin@lucas.local"; ADMIN_PASS='Admin#2026dev'
RUN="$RANDOM$RANDOM"

TMP="$(mktemp -d)"; trap 'cleanup' EXIT
passed=0; failed=0

cleanup() {
    sql "DELETE FROM intentos_acceso" >/dev/null 2>&1
    sql "DELETE FROM usuarios WHERE correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    rm -rf "$TMP"
}
sql() { docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" lucas -N -e "$0" 2>/dev/null' "$1"; }

check() { # descripción esperado obtenido
    if [ "$2" = "$3" ]; then passed=$((passed + 1)); echo "  ok    $1"
    else failed=$((failed + 1)); echo "  FALLO $1"; echo "        esperado: $2"; echo "        obtenido: $3"; fi
}
contains() { # descripción texto archivo
    if grep -qF -- "$2" "$3"; then passed=$((passed + 1)); echo "  ok    $1"
    else failed=$((failed + 1)); echo "  FALLO $1 (no aparece «$2»)"; fi
}

# request JAR MÉTODO RUTA [datos...]  ->  CODE, LOC (cabecera Location) y $TMP/body
request() {
    local jar="$1" method="$2" path="$3"; shift 3
    local args=(-s -o "$TMP/body" -D "$TMP/hdr" -w '%{http_code}' -b "$jar" -c "$jar" -X "$method")
    [ "$method" = POST ] && args+=(-H "Origin: ${ORIGIN_SENT:-$ORIGIN}" "$@")
    CODE="$(curl "${args[@]}" "$BASE$path")"
    LOC="$(grep -i '^location:' "$TMP/hdr" | tr -d '\r' | cut -d' ' -f2- | head -n1)"
}
token() { # JAR RUTA -> token CSRF de esa página
    request "$1" GET "$2"
    grep -o 'name="csrf_token" value="[a-f0-9]*"' "$TMP/body" | head -n1 | sed 's/.*value="//; s/"$//'
}
post_form() { # JAR RUTA PÁGINA_DEL_TOKEN campo=valor...
    local jar="$1" path="$2" page="$3"; shift 3
    local tok; tok="$(token "$jar" "$page")"
    local data=(--data-urlencode "csrf_token=$tok")
    for pair in "$@"; do data+=(--data-urlencode "$pair"); done
    request "$jar" POST "$path" "${data[@]}"
}
new_jar() { mktemp -p "$TMP"; }

register() { # JAR correo clave [extra]
    post_form "$1" /registro.php /registro.php "nombre=Prueba Smoke" "correo=$2" "clave=$3" "clave2=$3" "${@:4}"
}
login() { post_form "$1" /login.php /login.php "correo=$2" "clave=$3"; }

curl -s -o /dev/null "$BASE/login.php" || { echo "No responde $BASE: ¿está en marcha docker compose up?"; exit 2; }
sql "DELETE FROM intentos_acceso" >/dev/null

echo; echo "== Sin sesión"
J="$(new_jar)"
request "$J" GET /;                 check "/ redirige a login" "302 /login.php" "$CODE $LOC"
request "$J" GET /panel.php;        check "el panel exige sesión" "302 /login.php" "$CODE $LOC"
request "$J" GET /movimientos.php;  check "las páginas de maqueta exigen sesión" "302 /login.php" "$CODE $LOC"
request "$J" GET /admin/index.php;  check "el dashboard de admin exige sesión" "302 /login.php" "$CODE $LOC"
request "$J" GET /admin/usuarios.php; check "la lista de usuarios exige sesión" "302 /login.php" "$CODE $LOC"
request "$J" GET /login.php;        check "login.php responde 200" "200" "$CODE"
request "$J" GET /registro.php;     check "registro.php responde 200" "200" "$CODE"
request "$J" GET /logout.php;       check "logout por GET no está permitido" "405" "$CODE"

echo; echo "== CSRF y origen"
J="$(new_jar)"
request "$J" POST /login.php --data-urlencode "correo=$ADMIN_EMAIL" --data-urlencode "clave=$ADMIN_PASS"
check "POST sin token CSRF es rechazado" "403" "$CODE"
ORIGIN_SENT="http://evil.example" post_form "$J" /login.php /login.php "correo=$ADMIN_EMAIL" "clave=$ADMIN_PASS"
check "POST desde otro origen es rechazado" "403" "$CODE"

echo; echo "== Registro"
U1="smoke_${RUN}_a@correo.com"; P='clave-segura-1'
J1="$(new_jar)"
register "$J1" "$U1" "$P" "rol=admin"
check "registrarse lleva directo al panel (aunque el formulario mande rol=admin)" "302 /panel.php" "$CODE $LOC"
check "el rol guardado es «usuario» (RF-25)" "usuario" "$(sql "SELECT rol FROM usuarios WHERE correo='$U1'")"
request "$J1" GET /panel.php;        check "el usuario ve su panel" "200" "$CODE"
request "$J1" GET /admin/index.php;  check "un usuario recibe 403 en el dashboard de admin (RF-27)" "403" "$CODE"
request "$J1" GET /admin/usuarios.php; check "un usuario recibe 403 en la lista de usuarios" "403" "$CODE"
request "$J1" GET /login.php;        check "con sesión, login.php redirige a su panel" "302 /panel.php" "$CODE $LOC"
request "$J1" GET /;                 check "con sesión, / redirige a su panel" "302 /panel.php" "$CODE $LOC"

J2="$(new_jar)"
register "$J2" "$U1" "$P"
check "un correo repetido se rechaza" "200" "$CODE"; contains "con un aviso claro" "ya está registrado" "$TMP/body"
register "$J2" "smoke_${RUN}_b@correo.com" "clave-segura-1" "clave2=otra-distinta-9"
check "contraseñas distintas se rechazan" "200" "$CODE"; contains "con un aviso claro" "no coinciden" "$TMP/body"
register "$J2" "no-es-un-correo" "clave-segura-1"
contains "un correo inválido se rechaza" "correo válido" "$TMP/body"
check "nada de lo rechazado se guardó" "0" "$(sql "SELECT COUNT(*) FROM usuarios WHERE correo LIKE 'smoke_${RUN}_b%' OR correo='no-es-un-correo'")"

echo; echo "== Cerrar sesión"
post_form "$J1" /logout.php /panel.php
check "logout redirige a login" "302 /login.php" "$CODE $LOC"
request "$J1" GET /panel.php;        check "tras salir, el panel exige sesión otra vez" "302 /login.php" "$CODE $LOC"

echo; echo "== Iniciar sesión"
J3="$(new_jar)"
login "$J3" "$U1" "mala-clave-1"
check "clave incorrecta no entra" "200" "$CODE"; contains "mensaje genérico" "Correo o contraseña incorrectos." "$TMP/body"
login "$J3" "nadie_${RUN}@correo.com" "mala-clave-1"
contains "un correo inexistente da el MISMO mensaje" "Correo o contraseña incorrectos." "$TMP/body"
login "$J3" "$U1" "$P"
check "credenciales correctas entran al panel" "302 /panel.php" "$CODE $LOC"
JA="$(new_jar)"
login "$JA" "$ADMIN_EMAIL" "$ADMIN_PASS"
check "el admin entra al dashboard de administración" "302 /admin/index.php" "$CODE $LOC"
request "$JA" GET /admin/index.php;  check "el admin ve el dashboard" "200" "$CODE"
request "$JA" GET /admin/usuarios.php; check "el admin ve la lista de usuarios" "200" "$CODE"
request "$JA" GET /;                 check "con sesión de admin, / lleva al dashboard" "302 /admin/index.php" "$CODE $LOC"

echo; echo "== Cuenta desactivada y cambio de rol (se aplican en la siguiente petición)"
J4="$(new_jar)"; U4="smoke_${RUN}_c@correo.com"
register "$J4" "$U4" "$P"
request "$J4" GET /panel.php;        check "el usuario nuevo entra" "200" "$CODE"
sql "UPDATE usuarios SET rol='admin' WHERE correo='$U4'" >/dev/null
request "$J4" GET /admin/index.php;  check "un ascenso a admin vale sin volver a entrar" "200" "$CODE"
sql "UPDATE usuarios SET rol='usuario' WHERE correo='$U4'" >/dev/null
request "$J4" GET /admin/index.php;  check "una degradación también" "403" "$CODE"
sql "UPDATE usuarios SET activo=0 WHERE correo='$U4'" >/dev/null
request "$J4" GET /panel.php;        check "un usuario desactivado pierde la sesión en su siguiente petición" "302 /login.php?msg=revoked" "$CODE $LOC"
request "$J4" GET /panel.php;        check "y sigue sin sesión" "302 /login.php" "$CODE $LOC"
J5="$(new_jar)"
login "$J5" "$U4" "$P"
check "un usuario desactivado no puede iniciar sesión" "200" "$CODE"; contains "con el mismo mensaje genérico" "Correo o contraseña incorrectos." "$TMP/body"

echo; echo "== Limitación de intentos (RNF-24)"
J6="$(new_jar)"; U6="smoke_${RUN}_d@correo.com"
for i in 1 2 3 4 5; do login "$J6" "$U6" "mala-clave-$i"; done
check "5 fallos seguidos siguen respondiendo 200" "200" "$CODE"
login "$J6" "$U6" "mala-clave-6"
check "el 6.º intento se bloquea con 429" "429" "$CODE"; contains "con un aviso claro" "Demasiados intentos" "$TMP/body"
check "y manda Retry-After" "Retry-After: 900" "$(grep -i '^retry-after' "$TMP/hdr" | tr -d '\r')"
sql "DELETE FROM intentos_acceso" >/dev/null

echo; echo "Resultado: $passed correctas, $failed fallidas"
[ "$failed" -eq 0 ]
