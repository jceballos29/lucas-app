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
    sql "DELETE FROM categorias WHERE id_usuario IS NULL AND nombre LIKE 'Smoke-Cat%'" >/dev/null 2>&1
    sql "DELETE FROM usuarios WHERE correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    rm -rf "$TMP"
}
sql() { docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 lucas -N -e "$0" 2>/dev/null' "$1"; }

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

echo; echo "== La seed y el juego de caracteres"
check "las tildes de la seed se guardaron bien (utf8mb4, no latin1)" "1" "$(sql "SELECT COUNT(*) FROM categorias WHERE id_usuario IS NULL AND nombre IN ('Alimentación','Educación')" | awk '{print ($1==2)}')"
check "no hay texto con doble codificación en la BD" "0" "$(sql "SELECT COUNT(*) FROM categorias WHERE HEX(nombre) LIKE '%C383%'")"

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

echo; echo "== Cuentas (RF-05 a RF-07, RNF-04)"
JC="$(new_jar)"; UC="smoke_${RUN}_e@correo.com"; register "$JC" "$UC" "$P"
JD="$(new_jar)"; UD="smoke_${RUN}_f@correo.com"; register "$JD" "$UD" "$P"
UCID="$(uid "$UC")"; UDID="$(uid "$UD")"
request "$JC" GET /cuentas.php;      check "el listado de cuentas responde 200" "200" "$CODE"
contains "sin cuentas invita a crear la primera" "Aún no tienes cuentas" "$TMP/body"
request "$J7" GET /cuentas.php;      check "sin sesión, las cuentas exigen login" "302 /login.php" "$CODE $LOC"
request "$J7" GET /cuenta-form.php;  check "y el formulario también" "302 /login.php" "$CODE $LOC"
request "$JC" GET /cuenta-form.php;  check "el formulario de cuenta nueva responde 200" "200" "$CODE"

post_form "$JC" /cuenta-form.php /cuenta-form.php "nombre=Efectivo" "tipo=efectivo" "saldo_inicial=1.500.000,50"
check "crear una cuenta redirige al listado" "302 /cuentas.php" "$CODE $LOC"
check "el saldo inicial se guardó exacto" "1500000.50" "$(sql "SELECT saldo_inicial FROM cuentas WHERE id_usuario=$UCID AND nombre='Efectivo'")"
CID="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UCID AND nombre='Efectivo'")"
request "$JC" GET /cuentas.php
contains "el listado muestra la cuenta" "Efectivo" "$TMP/body"
contains "con su saldo formateado" '$1.500.000,50' "$TMP/body"
contains "y avisa que se creó" "Se creó la cuenta" "$TMP/body"
post_form "$JC" /cuenta-form.php /cuenta-form.php "nombre=Visa" "tipo=tarjeta_credito" "saldo_inicial=-300000"
request "$JC" GET /cuentas.php
contains "el saldo total suma las cuentas (1.200.000,50)" 'Saldo total $1.200.000,50' "$TMP/body"
sql "INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES ($UCID, $CID, (SELECT MIN(id_categoria) FROM categorias WHERE tipo='ingreso'), 'ingreso', 250000.25, CURDATE())" >/dev/null
request "$JC" GET /cuentas.php
contains "un ingreso sube el saldo calculado" '$1.750.000,75' "$TMP/body"

post_form "$JC" /cuenta-form.php /cuenta-form.php "nombre=Efectivo" "tipo=efectivo" "saldo_inicial=0"
check "un nombre repetido se rechaza" "200" "$CODE"; contains "con un aviso claro" "Ya tienes una cuenta con ese nombre" "$TMP/body"
post_form "$JC" /cuenta-form.php /cuenta-form.php "nombre=Caja" "tipo=ahorro" "saldo_inicial=-5"
contains "un saldo negativo en una cuenta de ahorro se rechaza" "no puede ser negativo" "$TMP/body"
post_form "$JC" /cuenta-form.php /cuenta-form.php "nombre=Caja" "tipo=cripto" "saldo_inicial=0"
contains "un tipo inválido se rechaza" "Elige el tipo" "$TMP/body"
post_form "$JC" /cuenta-form.php /cuenta-form.php "nombre=Caja" "tipo=efectivo" "saldo_inicial=mucho"
contains "un monto inválido se rechaza" "monto válido" "$TMP/body"
check "nada de lo rechazado se guardó" "2" "$(sql "SELECT COUNT(*) FROM cuentas WHERE id_usuario=$UCID")"
post_form "$JC" /cuenta-form.php /cuenta-form.php "nombre=<b>Negrita</b>" "tipo=efectivo" "saldo_inicial=0"
request "$JC" GET /cuentas.php
contains "un nombre con HTML se escapa" "&lt;b&gt;Negrita&lt;/b&gt;" "$TMP/body"
absent "y no aparece como etiqueta" "<b>Negrita" "$TMP/body"

request "$JC" GET "/cuenta-form.php?id=$CID"
contains "editar muestra los datos de la cuenta" 'value="Efectivo"' "$TMP/body"
contains "y su saldo inicial" 'value="1500000,50"' "$TMP/body"
post_form "$JC" /cuenta-form.php "/cuenta-form.php?id=$CID" "id=$CID" "nombre=Caja fuerte" "tipo=efectivo" "saldo_inicial=2.000.000"
check "guardar los cambios redirige al listado" "302 /cuentas.php" "$CODE $LOC"
check "el cambio quedó en la BD" "Caja fuerte|2000000.00" "$(sql "SELECT CONCAT(nombre,'|',saldo_inicial) FROM cuentas WHERE id_cuenta=$CID")"

request "$JD" GET "/cuenta-form.php?id=$CID";  check "otro usuario no puede abrir mi cuenta (404, RNF-04)" "404" "$CODE"
post_form "$JD" /cuenta-form.php /cuenta-form.php "id=$CID" "nombre=Robada" "tipo=efectivo" "saldo_inicial=0"
check "ni editarla" "404" "$CODE"
check "y el nombre no cambió" "Caja fuerte" "$(sql "SELECT nombre FROM cuentas WHERE id_cuenta=$CID")"
post_form "$JD" /cuentas.php /cuentas.php "id=$CID" "accion=desactivar"
check "ni desactivarla" "404" "$CODE"
check "sigue activa" "1" "$(sql "SELECT activa FROM cuentas WHERE id_cuenta=$CID")"
request "$JD" GET /cuentas.php;  absent "mis cuentas no aparecen en el listado de otro usuario" "Caja fuerte" "$TMP/body"
request "$JC" GET "/cuenta-form.php?id=abc";  check "un id no numérico es 404" "404" "$CODE"
request "$JC" GET "/cuenta-form.php?id=99999999";  check "un id inexistente es 404" "404" "$CODE"

post_form "$JC" /cuentas.php /cuentas.php "id=$CID" "accion=desactivar"
check "desactivar redirige al listado" "302 /cuentas.php" "$CODE $LOC"
check "la cuenta quedó inactiva y conserva sus movimientos" "0|1" "$(sql "SELECT CONCAT(activa,'|',(SELECT COUNT(*) FROM transacciones WHERE id_cuenta=$CID)) FROM cuentas WHERE id_cuenta=$CID")"
request "$JC" GET /cuentas.php
contains "la cuenta aparece como inactiva" "Inactiva" "$TMP/body"
contains "y deja de contar en el saldo total" 'Saldo total −$300.000' "$TMP/body"
post_form "$JC" /cuentas.php /cuentas.php "id=$CID" "accion=activar"
check "se puede reactivar" "1" "$(sql "SELECT activa FROM cuentas WHERE id_cuenta=$CID")"
post_form "$JC" /cuentas.php /cuentas.php "id=$CID" "accion=borrar"
check "una acción desconocida es 400" "400" "$CODE"
request "$JC" POST /cuentas.php --data-urlencode "id=$CID" --data-urlencode "accion=desactivar"
check "sin token CSRF se rechaza" "403" "$CODE"

echo; echo "== Categorías (RF-08, RF-09)"
request "$JC" GET /categorias.php;   check "el listado de categorías responde 200" "200" "$CODE"
contains "muestra las predeterminadas" "Alimentación" "$TMP/body"
contains "marcadas como tales" "Predeterminada" "$TMP/body"
request "$J7" GET /categorias.php;   check "sin sesión exige login" "302 /login.php" "$CODE $LOC"
post_form "$JC" /categorias.php /categorias.php "accion=crear" "nombre=Mascotas" "tipo=gasto" "color=#a1b2c3"
check "crear una categoría redirige al listado" "302 /categorias.php" "$CODE $LOC"
check "queda como propia, con el color en mayúsculas" "$UCID|gasto|#A1B2C3" "$(sql "SELECT CONCAT(id_usuario,'|',tipo,'|',color) FROM categorias WHERE nombre='Mascotas' AND id_usuario=$UCID")"
KID="$(sql "SELECT id_categoria FROM categorias WHERE nombre='Mascotas' AND id_usuario=$UCID")"
request "$JC" GET /categorias.php
contains "aparece como propia" "Propia" "$TMP/body"
contains "con su color para el JS" 'data-color="#A1B2C3"' "$TMP/body"
request "$JD" GET /categorias.php;   absent "otro usuario no ve mis categorías" 'data-color="#A1B2C3"' "$TMP/body"
post_form "$JC" /categorias.php /categorias.php "accion=crear" "nombre=salud" "tipo=gasto" "color=#000000"
contains "no se puede repetir una predeterminada" "Ya existe una categoría con ese nombre" "$TMP/body"
post_form "$JC" /categorias.php /categorias.php "accion=crear" "nombre=Salud" "tipo=ingreso" "color=#000000"
check "el mismo nombre en el otro tipo sí se permite" "302 /categorias.php" "$CODE $LOC"
post_form "$JC" /categorias.php /categorias.php "accion=crear" "nombre=X" "tipo=gasto"
contains "un nombre demasiado corto se rechaza" "Escribe un nombre" "$TMP/body"
post_form "$JC" /categorias.php /categorias.php "accion=crear" "nombre=Rara" "tipo=gasto" "color=rojo"
contains "un color inválido se rechaza" "#RRGGBB" "$TMP/body"
post_form "$JC" /categorias.php /categorias.php "accion=crear" "nombre=<i>Cursiva</i>" "tipo=gasto" "color=#112233"
request "$JC" GET /categorias.php
contains "un nombre con HTML se escapa" "&lt;i&gt;Cursiva&lt;/i&gt;" "$TMP/body"

request "$JC" GET "/categorias.php?editar=$KID"
contains "editar muestra el formulario con sus datos" 'value="Mascotas"' "$TMP/body"
post_form "$JC" /categorias.php "/categorias.php?editar=$KID" "accion=guardar" "id=$KID" "nombre=Mascotas y más" "tipo=ingreso" "color=#112233"
check "guardar redirige al listado" "302 /categorias.php" "$CODE $LOC"
check "se cambió nombre y color, pero NO el tipo" "Mascotas y más|gasto|#112233" "$(sql "SELECT CONCAT(nombre,'|',tipo,'|',color) FROM categorias WHERE id_categoria=$KID")"

DEFID="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND nombre='Salud'")"
request "$JC" GET "/categorias.php?editar=$DEFID";  check "un usuario no puede editar una predeterminada (403)" "403" "$CODE"
post_form "$JC" /categorias.php /categorias.php "accion=guardar" "id=$DEFID" "nombre=Hackeada" "tipo=gasto" "color=#000000"
check "ni guardarla" "403" "$CODE"
post_form "$JC" /categorias.php /categorias.php "accion=eliminar" "id=$DEFID"
check "ni eliminarla" "403" "$CODE"
check "la predeterminada sigue intacta" "Salud" "$(sql "SELECT nombre FROM categorias WHERE id_categoria=$DEFID")"
request "$JD" GET "/categorias.php?editar=$KID";  check "otro usuario no puede abrir mi categoría (404)" "404" "$CODE"
post_form "$JD" /categorias.php /categorias.php "accion=eliminar" "id=$KID"
check "ni eliminarla" "404" "$CODE"
check "sigue existiendo" "1" "$(sql "SELECT COUNT(*) FROM categorias WHERE id_categoria=$KID")"
post_form "$JC" /categorias.php /categorias.php "accion=raro" "id=$KID"
check "una acción desconocida es 400" "400" "$CODE"

sql "INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES ($UCID, $CID, $KID, 'gasto', 5000, CURDATE())" >/dev/null
post_form "$JC" /categorias.php /categorias.php "accion=eliminar" "id=$KID"
check "eliminar una categoría con movimientos redirige" "302 /categorias.php" "$CODE $LOC"
request "$JC" GET /categorias.php;   contains "y explica por qué no se pudo (D4)" "No se puede eliminar" "$TMP/body"
check "la categoría sigue existiendo" "1" "$(sql "SELECT COUNT(*) FROM categorias WHERE id_categoria=$KID")"
sql "DELETE FROM transacciones WHERE id_categoria=$KID" >/dev/null
post_form "$JC" /categorias.php /categorias.php "accion=eliminar" "id=$KID"
request "$JC" GET /categorias.php;   contains "sin uso se elimina y lo avisa" "Se eliminó la categoría" "$TMP/body"
check "ya no está en la BD" "0" "$(sql "SELECT COUNT(*) FROM categorias WHERE id_categoria=$KID")"

echo; echo "== Categorías predeterminadas del administrador (RF-34, RNF-21)"
post_form "$JC" /categorias.php /categorias.php "accion=crear" "nombre=Smoke-Cat-Privada-$RUN" "tipo=gasto" "color=#334455"
request "$JA" GET /admin/categorias.php;  check "el admin ve la gestión de predeterminadas" "200" "$CODE"
contains "con las predeterminadas" "Alimentación" "$TMP/body"
absent "pero no las categorías propias de un usuario (RNF-21)" "Smoke-Cat-Privada-$RUN" "$TMP/body"
request "$JC" GET /admin/categorias.php;  check "un usuario recibe 403 en admin/categorias" "403" "$CODE"
post_form "$JC" /admin/categorias.php /categorias.php "accion=crear" "nombre=Smoke-Cat-Intruso" "tipo=gasto" "color=#000000"
check "un usuario no puede crear predeterminadas por la puerta de admin (403)" "403" "$CODE"
check "y no se creó" "0" "$(sql "SELECT COUNT(*) FROM categorias WHERE nombre='Smoke-Cat-Intruso'")"
request "$J7" GET /admin/categorias.php;  check "sin sesión exige login" "302 /login.php" "$CODE $LOC"

post_form "$JA" /admin/categorias.php /admin/categorias.php "accion=crear" "nombre=Smoke-Cat-$RUN" "tipo=ingreso" "color=#778899"
check "el admin crea una predeterminada" "302 /admin/categorias.php" "$CODE $LOC"
check "queda sin dueño" "NULL" "$(sql "SELECT IFNULL(id_usuario,'NULL') FROM categorias WHERE nombre='Smoke-Cat-$RUN'")"
GID="$(sql "SELECT id_categoria FROM categorias WHERE nombre='Smoke-Cat-$RUN'")"
request "$JD" GET /categorias.php
contains "todos los usuarios la ven" "Smoke-Cat-$RUN" "$TMP/body"
post_form "$JA" /admin/categorias.php "/admin/categorias.php?editar=$GID" "accion=guardar" "id=$GID" "nombre=Smoke-Cat-$RUN-b" "tipo=ingreso" "color=#778899"
check "el admin la edita" "Smoke-Cat-$RUN-b" "$(sql "SELECT nombre FROM categorias WHERE id_categoria=$GID")"
post_form "$JA" /admin/categorias.php /admin/categorias.php "accion=guardar" "id=$KID" "nombre=x" "tipo=gasto" "color=#000000"
check "el admin no puede tocar categorías propias de un usuario (404)" "404" "$CODE"
OWN="$(sql "SELECT id_categoria FROM categorias WHERE nombre='Smoke-Cat-Privada-$RUN'")"
post_form "$JA" /admin/categorias.php /admin/categorias.php "accion=eliminar" "id=$OWN"
check "ni eliminarlas (404)" "404" "$CODE"
check "la categoría del usuario sigue ahí" "1" "$(sql "SELECT COUNT(*) FROM categorias WHERE id_categoria=$OWN")"
sql "INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES ($UDID, (SELECT 0), $GID, 'ingreso', 1, CURDATE())" >/dev/null 2>&1
DCID="$(sql "INSERT INTO cuentas (id_usuario, nombre, tipo) VALUES ($UDID, 'Smoke cuenta D', 'efectivo'); SELECT LAST_INSERT_ID()")"
sql "INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES ($UDID, $DCID, $GID, 'ingreso', 1, CURDATE())" >/dev/null
post_form "$JA" /admin/categorias.php /admin/categorias.php "accion=eliminar" "id=$GID"
request "$JA" GET /admin/categorias.php
contains "el admin no puede borrar una predeterminada en uso" "No se puede eliminar" "$TMP/body"
check "sigue existiendo" "1" "$(sql "SELECT COUNT(*) FROM categorias WHERE id_categoria=$GID")"
absent "y el aviso no revela de quién son los movimientos" "$UD" "$TMP/body"
sql "DELETE FROM transacciones WHERE id_categoria=$GID" >/dev/null
post_form "$JA" /admin/categorias.php /admin/categorias.php "accion=eliminar" "id=$GID"
check "sin uso el admin la elimina" "0" "$(sql "SELECT COUNT(*) FROM categorias WHERE id_categoria=$GID")"
request "$JD" POST /admin/categorias.php --data-urlencode "accion=crear" --data-urlencode "nombre=Smoke-Cat-X" --data-urlencode "tipo=gasto"
check "un POST de admin sin CSRF se rechaza (403)" "403" "$CODE"

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
