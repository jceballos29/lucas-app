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
    # Orden explícito: transacciones y aportes referencian cuentas sin cascada (ver D5 en docs/PLAN.md).
    sql "DELETE t FROM transacciones t JOIN usuarios u ON u.id_usuario = t.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE FROM usuarios WHERE correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    rm -rf "$TMP"
}
sql() { docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" lucas -N -e "$0" 2>/dev/null' "$1"; }

check() { # descripción esperado obtenido
    if [ "$2" = "$3" ]; then passed=$((passed + 1)); echo "  ok    $1"
    else failed=$((failed + 1)); echo "  FALLO $1"; echo "        esperado: $2"; echo "        obtenido: $3"; fi
}
absent() { # descripción texto archivo
    if grep -qF -- "$2" "$3"; then failed=$((failed + 1)); echo "  FALLO $1 (aparece «$2»)"
    else passed=$((passed + 1)); echo "  ok    $1"; fi
}
uid() { sql "SELECT id_usuario FROM usuarios WHERE correo='$1'"; }
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

echo; echo "== Administración: dashboard y lista de usuarios (RF-28 a RF-30, RNF-21)"
UX="smoke_${RUN}_a@correo.com"; UXID="$(uid "$UX")"
sql "INSERT INTO cuentas (id_usuario, nombre, tipo) VALUES ($UXID, 'Cuenta SECRETA-$RUN', 'efectivo')" >/dev/null
sql "INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha, descripcion) SELECT $UXID, id_cuenta, (SELECT MIN(id_categoria) FROM categorias), 'gasto', 98765.43, CURDATE(), 'SECRETO-$RUN' FROM cuentas WHERE id_usuario=$UXID LIMIT 1" >/dev/null
request "$JA" GET /admin/index.php
check "el dashboard responde 200" "200" "$CODE"
contains "muestra el total de usuarios de la BD" "<strong class=\"stat__value\">$(sql "SELECT COUNT(*) FROM usuarios")</strong>" "$TMP/body"
contains "muestra el total de movimientos de la BD" "<strong class=\"stat__value\">$(sql "SELECT COUNT(*) FROM transacciones")</strong>" "$TMP/body"
contains "dibuja la gráfica de registros por mes (12 columnas)" "$(printf 'bars__col%.0s' 1)" "$TMP/body"
check "la gráfica tiene 12 meses" "12" "$(grep -o 'class="bars__col' "$TMP/body" | wc -l | tr -d ' ')"
absent "el dashboard no expone la descripción de un movimiento (RNF-21)" "SECRETO-$RUN" "$TMP/body"
absent "ni su monto" "98765" "$TMP/body"
absent "ni su formato con separador" "98.765" "$TMP/body"
absent "ni el nombre de una cuenta" "SECRETA-$RUN" "$TMP/body"
request "$JA" GET /admin/usuarios.php
absent "la lista de usuarios tampoco expone movimientos" "SECRETO-$RUN" "$TMP/body"
absent "ni cuentas" "SECRETA-$RUN" "$TMP/body"
absent "ni hashes de contraseña" '$2y$' "$TMP/body"

request "$JA" GET "/admin/usuarios.php?q=smoke_${RUN}_a"
contains "la búsqueda por correo encuentra al usuario" "$UX" "$TMP/body"
absent "y no muestra a los demás" "smoke_${RUN}_c@correo.com" "$TMP/body"
request "$JA" GET "/admin/usuarios.php?q=zzz-nada-$RUN"
contains "una búsqueda sin resultados lo dice" "No hay usuarios que coincidan" "$TMP/body"
request "$JA" GET "/admin/usuarios.php?q[]=x&pagina[]=2"
check "parámetros con forma de arreglo no rompen la página" "200" "$CODE"

sql "INSERT INTO usuarios (nombre, correo, contrasena_hash) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i < 25) SELECT CONCAT('Smoke ', i), CONCAT('smoke_${RUN}_p', i, '@correo.com'), 'x' FROM n" >/dev/null
request "$JA" GET "/admin/usuarios.php?q=smoke_${RUN}_p"
contains "la primera página muestra 20 de 25 (RF-30)" "20 de 25 usuarios" "$TMP/body"
contains "hay enlace a la página 2" 'aria-label="Página 2"' "$TMP/body"
contains "los enlaces de página conservan la búsqueda" "pagina=2&amp;q=smoke_${RUN}_p" "$TMP/body"
request "$JA" GET "/admin/usuarios.php?q=smoke_${RUN}_p&pagina=2"
contains "la página 2 muestra los 5 restantes" "5 de 25 usuarios" "$TMP/body"
request "$JA" GET "/admin/usuarios.php?q=smoke_${RUN}_p&pagina=999"
contains "una página fuera de rango se ajusta a la última" "5 de 25 usuarios" "$TMP/body"

sql "INSERT INTO usuarios (nombre, correo, contrasena_hash) VALUES ('<script>alert(1)</script>', 'smoke_${RUN}_xss@correo.com', 'x')" >/dev/null
request "$JA" GET "/admin/usuarios.php?q=smoke_${RUN}_xss"
contains "un nombre con HTML se escapa" "&lt;script&gt;alert(1)&lt;/script&gt;" "$TMP/body"
absent "y no aparece como etiqueta" "<script>alert(1)" "$TMP/body"

echo; echo "== Administración: activar, desactivar y cambiar rol (RF-31 a RF-33, RNF-22)"
JU="$(new_jar)"; login "$JU" "$UX" "$P"
check "el usuario de prueba entra" "302 /panel.php" "$CODE $LOC"
post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=$UXID" "accion=desactivar"
check "desactivar redirige a la lista" "302 /admin/usuarios.php" "$CODE $LOC"
check "el usuario quedó inactivo en la BD" "0" "$(sql "SELECT activo FROM usuarios WHERE id_usuario=$UXID")"
request "$JA" GET /admin/usuarios.php
contains "el administrador ve la confirmación (una sola vez)" "Se desactivó la cuenta de Prueba Smoke" "$TMP/body"
request "$JA" GET /admin/usuarios.php
absent "y el aviso no se repite al recargar" "Se desactivó la cuenta" "$TMP/body"
request "$JU" GET /panel.php
check "el usuario desactivado pierde la sesión en su siguiente petición" "302 /login.php?msg=revoked" "$CODE $LOC"
check "sus datos se conservan" "1" "$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UXID")"
post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=$UXID" "accion=activar"
check "activar lo deja activo" "1" "$(sql "SELECT activo FROM usuarios WHERE id_usuario=$UXID")"
login "$JU" "$UX" "$P"
check "y puede entrar de nuevo" "302 /panel.php" "$CODE $LOC"

request "$JU" GET /admin/index.php;   check "antes de ser admin, recibe 403" "403" "$CODE"
post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=$UXID" "accion=hacer_admin"
check "hacer admin cambia el rol" "admin" "$(sql "SELECT rol FROM usuarios WHERE id_usuario=$UXID")"
request "$JU" GET /admin/index.php;   check "y entra al dashboard sin volver a iniciar sesión" "200" "$CODE"
post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=$UXID" "accion=quitar_admin"
check "quitar admin devuelve el rol «usuario»" "usuario" "$(sql "SELECT rol FROM usuarios WHERE id_usuario=$UXID")"
request "$JU" GET /admin/index.php;   check "y vuelve a recibir 403" "403" "$CODE"

ADMIN_ID="$(uid "$ADMIN_EMAIL")"
post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=$ADMIN_ID" "accion=desactivar"
check "un admin no puede desactivarse a sí mismo" "1" "$(sql "SELECT activo FROM usuarios WHERE id_usuario=$ADMIN_ID")"
request "$JA" GET /admin/usuarios.php
contains "con un aviso claro" "No puedes desactivar tu propia cuenta" "$TMP/body"
post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=$ADMIN_ID" "accion=quitar_admin"
check "ni quitarse el rol" "admin" "$(sql "SELECT rol FROM usuarios WHERE id_usuario=$ADMIN_ID")"
check "sigue habiendo al menos un administrador activo" "1" "$(sql "SELECT COUNT(*) >= 1 FROM usuarios WHERE rol='admin' AND activo=1")"

post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=99999999" "accion=desactivar"
check "un id inexistente no rompe nada" "302 /admin/usuarios.php" "$CODE $LOC"
request "$JA" GET /admin/usuarios.php; contains "y avisa" "ya no existe" "$TMP/body"
post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=$UXID" "accion=borrar"
check "una acción desconocida es 400" "400" "$CODE"
post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=abc" "accion=desactivar"
check "un id no numérico es 400" "400" "$CODE"
post_form "$JA" /admin/usuarios.php "/admin/usuarios.php?q=smoke_${RUN}_p&pagina=2" "id=$UXID" "accion=activar" "q=smoke_${RUN}_p" "pagina=2"
check "tras una acción vuelve a la misma búsqueda y página" "302 /admin/usuarios.php?q=smoke_${RUN}_p&pagina=2" "$CODE $LOC"

request "$JA" POST /admin/usuarios.php --data-urlencode "id=$UXID" --data-urlencode "accion=desactivar"
check "un POST de admin sin token CSRF es rechazado" "403" "$CODE"
check "y no cambió nada" "1" "$(sql "SELECT activo FROM usuarios WHERE id_usuario=$UXID")"
ORIGIN_SENT="http://evil.example" post_form "$JA" /admin/usuarios.php /admin/usuarios.php "id=$UXID" "accion=desactivar"
check "un POST de admin desde otro origen es rechazado" "403" "$CODE"
post_form "$JU" /admin/usuarios.php /panel.php "id=$ADMIN_ID" "accion=desactivar"
check "un usuario normal no puede enviar acciones de administración (403)" "403" "$CODE"
check "y el admin sigue activo" "1" "$(sql "SELECT activo FROM usuarios WHERE id_usuario=$ADMIN_ID")"
request "$JU" GET /admin/usuarios.php; check "un usuario normal no ve la lista de usuarios" "403" "$CODE"
J7="$(new_jar)"; request "$J7" POST /admin/usuarios.php --data-urlencode "id=$UXID" --data-urlencode "accion=desactivar"
check "sin sesión, un POST de administración no llega ni a validarse" "302 /login.php" "$CODE $LOC"

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
