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
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/.."

env_value() { [ -f .env ] && grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | tr -d '\r' || true; }
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
    sql "DELETE r FROM restablecimientos r JOIN usuarios u ON u.id_usuario = r.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE pd FROM pagos_deuda pd JOIN usuarios u ON u.id_usuario = pd.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE d FROM deudas d JOIN usuarios u ON u.id_usuario = d.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE a FROM aportes_meta a JOIN metas_ahorro m ON m.id_meta = a.id_meta JOIN usuarios u ON u.id_usuario = m.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE mr FROM movimientos_recurrentes mr JOIN usuarios u ON u.id_usuario = mr.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE tr FROM transferencias tr JOIN usuarios u ON u.id_usuario = tr.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE p FROM presupuestos p JOIN usuarios u ON u.id_usuario = p.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE t FROM transacciones t JOIN usuarios u ON u.id_usuario = t.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE c FROM cuentas c JOIN usuarios u ON u.id_usuario = c.id_usuario WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
    sql "DELETE e FROM espacios e JOIN usuarios u ON u.id_usuario = e.id_creador WHERE u.correo LIKE 'smoke\\_%@correo.com'" >/dev/null 2>&1
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
new_jar() { mktemp "$TMP/jar.XXXXXX"; }
sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1
    elif command -v shasum >/dev/null 2>&1; then shasum -a 256 | cut -d' ' -f1
    else openssl dgst -sha256 | awk '{print $NF}'; fi
}
php_eval() { docker compose exec -T php php -r "$1"; }

register() { # JAR correo clave [extra]
    post_form "$1" /registro.php /registro.php "nombre=Prueba Smoke" "correo=$2" "clave=$3" "clave2=$3" "${@:4}"
}
login() { post_form "$1" /login.php /login.php "correo=$2" "clave=$3"; }

MAILPIT="http://127.0.0.1:$(env_value MAILPIT_PORT)"; [ "$MAILPIT" = "http://127.0.0.1:" ] && MAILPIT="http://127.0.0.1:8025"
mail_clear() { curl -s -X DELETE "$MAILPIT/api/v1/messages" >/dev/null; }
mail_ids() { curl -s -G "$MAILPIT/api/v1/search" --data-urlencode "query=to:$1" | grep -o '"ID":"[^"]*"' | cut -d'"' -f4; }
mail_count() { mail_ids "$1" | wc -l | tr -d ' '; }
wait_mail() { local i; for i in $(seq 1 40); do [ "$(mail_count "$1")" -ge "${2:-1}" ] && return 0; sleep 0.25; done; return 1; }
mail_token() { local id; id="$(mail_ids "$1" | head -n1)"; [ -n "$id" ] && curl -s "$MAILPIT/api/v1/message/$id" | grep -o 'restablecer\.php?token=[a-f0-9]\{64\}' | head -n1 | cut -d= -f2; }
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
contains "envía cabecera Cross-Origin-Opener-Policy" "Cross-Origin-Opener-Policy: same-origin" "$TMP/hdr"
request "$J" GET /login.php/x.php;  check "una ruta .php con PATH_INFO falso es 404 (no ejecuta login.php)" "404" "$CODE"
request "$J" GET /no-existe.php;    check "un .php inexistente es 404" "404" "$CODE"
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
contains "y su saldo inicial" 'value="1.500.000,50"' "$TMP/body"
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

echo; echo "== Movimientos (RF-10 a RF-14, D8, RNF-04)"
JM="$(new_jar)"; UM="smoke_${RUN}_m@correo.com"; register "$JM" "$UM" "$P"
JN="$(new_jar)"; UN="smoke_${RUN}_n@correo.com"; register "$JN" "$UN" "$P"
UMID="$(uid "$UM")"; UNID="$(uid "$UN")"
KG="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND nombre='Alimentación'")"
KI="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND nombre='Salario'")"
request "$J7" GET /movimientos.php;       check "sin sesión, los movimientos exigen login" "302 /login.php" "$CODE $LOC"
request "$J7" GET /movimiento-form.php;   check "y el formulario también" "302 /login.php" "$CODE $LOC"
request "$JM" GET /movimientos.php;       check "el listado responde 200" "200" "$CODE"
contains "sin cuentas invita a crear una" "primero crea una cuenta" "$TMP/body"
request "$JM" GET /movimiento-form.php;   contains "el formulario sin cuentas lo explica" "al menos una cuenta activa" "$TMP/body"

post_form "$JM" /cuenta-form.php /cuenta-form.php "nombre=Efectivo" "tipo=efectivo" "saldo_inicial=100.000"
CM="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UMID")"
post_form "$JN" /cuenta-form.php /cuenta-form.php "nombre=Cuenta de N" "tipo=efectivo" "saldo_inicial=500"
CN="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UNID")"
post_form "$JN" /categorias.php /categorias.php "accion=crear" "nombre=Solo de N" "tipo=gasto" "color=#123456"
KN="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario=$UNID")"
request "$JM" GET /movimientos.php;       contains "con cuenta y sin movimientos, invita a registrar el primero" "Aún no has registrado movimientos" "$TMP/body"
request "$JM" GET /movimiento-form.php;   check "el formulario responde 200" "200" "$CODE"
HOY_APP="$(php_eval "echo date('Y-m-d');")"
contains "la fecha por defecto es hoy" "value=\"$HOY_APP\"" "$TMP/body"
contains "las categorías llevan su tipo para el filtro del JS" 'data-tipo="gasto"' "$TMP/body"
contains "y la cuenta del usuario está en la lista" ">Efectivo · " "$TMP/body"
absent "pero no la de otro usuario" "Cuenta de N" "$TMP/body"
absent "ni sus categorías propias" "Solo de N" "$TMP/body"

post_form "$JM" /movimiento-form.php /movimiento-form.php "tipo=gasto" "monto=18.000,50" "fecha=2026-11-03" "id_cuenta=$CM" "id_categoria=$KG" "descripcion=Almuerzo"
check "registrar un gasto redirige al listado" "302 /movimientos.php" "$CODE $LOC"
check "se guardó con el monto exacto, tipo y dueño correctos" "gasto|18000.50|$UMID|Almuerzo" "$(sql "SELECT CONCAT(tipo,'|',monto,'|',id_usuario,'|',descripcion) FROM transacciones WHERE id_usuario=$UMID")"
MID="$(sql "SELECT id_transaccion FROM transacciones WHERE id_usuario=$UMID")"
request "$JM" GET /movimientos.php
contains "el listado muestra el movimiento" "Almuerzo" "$TMP/body"
contains "con la fecha en formato local" "03/11/2026" "$TMP/body"
contains "y el gasto con su signo" '−$18.000,50' "$TMP/body"
contains "y avisa del registro" 'Se registró el gasto de $18.000,50 en «Efectivo»' "$TMP/body"
request "$JM" GET /cuentas.php;           contains "el saldo de la cuenta bajó solo (RF-14)" '$81.999,50' "$TMP/body"
post_form "$JM" /movimiento-form.php /movimiento-form.php "tipo=ingreso" "monto=50000" "fecha=2026-11-04" "id_cuenta=$CM" "id_categoria=$KI" "descripcion="
request "$JM" GET /cuentas.php;           contains "un ingreso sube el saldo" '$131.999,50' "$TMP/body"
request "$JM" GET /movimientos.php;       contains "un movimiento sin descripción lo dice" "Sin descripción" "$TMP/body"
contains "y el ingreso se muestra con +" '+$50.000' "$TMP/body"

echo; echo "== Movimientos: validaciones"
NMOV="$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UMID")"
m() { post_form "$JM" /movimiento-form.php /movimiento-form.php "tipo=gasto" "monto=1000" "fecha=2026-11-03" "id_cuenta=$CM" "id_categoria=$KG" "$@"; }
m "monto=0";                  contains "un monto cero se rechaza" "mayor que cero" "$TMP/body"
m "monto=-500";               contains "un monto negativo se rechaza" "mayor que cero" "$TMP/body"
m "monto=mucho";              contains "un monto que no es número se rechaza" "monto válido" "$TMP/body"
m "monto=10,123";             contains "con 3 decimales se rechaza" "monto válido" "$TMP/body"
m "monto=10.000.000.000";     contains "un monto que no cabe se rechaza" "monto válido" "$TMP/body"
m "fecha=2026-02-31";         contains "una fecha imposible se rechaza" "fecha válida" "$TMP/body"
m "fecha=";                   contains "una fecha vacía se rechaza" "fecha válida" "$TMP/body"
m "tipo=transferencia";       contains "un tipo desconocido se rechaza" "ingreso o un gasto" "$TMP/body"
m "id_cuenta=";               contains "sin cuenta se rechaza" "Elige la cuenta" "$TMP/body"
m "id_categoria=";            contains "sin categoría se rechaza" "Elige la categoría" "$TMP/body"
m "id_categoria=$KI";         contains "un gasto con categoría de ingreso se rechaza (D8)" "no es de gastos" "$TMP/body"
check "y el formulario conserva lo escrito" "200" "$CODE"
contains "con el monto que se escribió" 'value="1000"' "$TMP/body"
m "monto=500.000";            contains "un gasto que supera el saldo de una cuenta normal se rechaza" "no alcanza para registrar este movimiento" "$TMP/body"
m "tipo=ingreso";             contains "un ingreso con categoría de gasto se rechaza (D8)" "no es de ingresos" "$TMP/body"
m "descripcion=$(printf 'a%.0s' $(seq 1 151))"; contains "una descripción de 151 caracteres se rechaza" "hasta 150 caracteres" "$TMP/body"
check "nada de lo rechazado se guardó" "$NMOV" "$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UMID")"
m "descripcion=<script>alert(1)</script>"
check "una descripción con HTML se guarda tal cual" "302 /movimientos.php" "$CODE $LOC"
request "$JM" GET /movimientos.php
contains "y se escapa al mostrarla" "&lt;script&gt;alert(1)&lt;/script&gt;" "$TMP/body"
absent "sin dejar la etiqueta" "<script>alert(1)" "$TMP/body"
sql "DELETE FROM transacciones WHERE descripcion LIKE '<script>%'" >/dev/null

echo; echo "== Movimientos: lo ajeno no existe (RNF-04)"
NMOV="$(sql "SELECT COUNT(*) FROM transacciones")"
post_form "$JM" /movimiento-form.php /movimiento-form.php "tipo=gasto" "monto=1000" "fecha=2026-11-03" "id_cuenta=$CN" "id_categoria=$KG"
check "registrar en la cuenta de otro usuario es 404" "404" "$CODE"
post_form "$JM" /movimiento-form.php /movimiento-form.php "tipo=gasto" "monto=1000" "fecha=2026-11-03" "id_cuenta=$CM" "id_categoria=$KN"
check "usar la categoría propia de otro usuario es 404" "404" "$CODE"
post_form "$JM" /movimiento-form.php /movimiento-form.php "tipo=gasto" "monto=1000" "fecha=2026-11-03" "id_cuenta=99999999" "id_categoria=$KG"
check "una cuenta inexistente es 404" "404" "$CODE"
post_form "$JM" /movimiento-form.php /movimiento-form.php "tipo=gasto" "monto=1000" "fecha=2026-11-03" "id_cuenta=$CM" "id_categoria=99999999"
check "una categoría inexistente es 404" "404" "$CODE"
check "nada se guardó" "$NMOV" "$(sql "SELECT COUNT(*) FROM transacciones")"
request "$JN" GET "/movimiento-form.php?id=$MID";  check "abrir el movimiento de otro usuario es 404" "404" "$CODE"
post_form "$JN" /movimiento-form.php /movimiento-form.php "id=$MID" "tipo=gasto" "monto=1" "fecha=2026-11-03" "id_cuenta=$CN" "id_categoria=$KG"
check "editar el movimiento de otro usuario es 404" "404" "$CODE"
post_form "$JN" /movimiento-eliminar.php /movimientos.php "id=$MID"
check "eliminar el movimiento de otro usuario es 404" "404" "$CODE"
check "y el movimiento sigue intacto" "18000.50" "$(sql "SELECT monto FROM transacciones WHERE id_transaccion=$MID")"
request "$JN" GET /movimientos.php;  absent "otro usuario no ve mis movimientos en su listado" "Almuerzo" "$TMP/body"
request "$JM" GET "/movimiento-form.php?id=abc";       check "un id no numérico es 404" "404" "$CODE"
request "$JM" GET "/movimiento-form.php?id=99999999";  check "un id inexistente es 404" "404" "$CODE"

echo; echo "== Movimientos: editar y eliminar"
request "$JM" GET "/movimiento-form.php?id=$MID"
contains "editar muestra el monto" 'value="18.000,50"' "$TMP/body"
contains "la descripción" 'value="Almuerzo"' "$TMP/body"
post_form "$JM" /movimiento-form.php "/movimiento-form.php?id=$MID" "id=$MID" "tipo=gasto" "monto=20.000" "fecha=2026-11-03" "id_cuenta=$CM" "id_categoria=$KG" "descripcion=Almuerzo y postre"
check "guardar los cambios redirige al listado" "302 /movimientos.php" "$CODE $LOC"
check "el cambio quedó en la BD" "20000.00|Almuerzo y postre" "$(sql "SELECT CONCAT(monto,'|',descripcion) FROM transacciones WHERE id_transaccion=$MID")"
request "$JM" GET /cuentas.php;  contains "el saldo se recalculó (RF-14)" '$130.000' "$TMP/body"
post_form "$JM" /movimiento-form.php "/movimiento-form.php?id=$MID" "id=$MID" "tipo=gasto" "monto=20.000" "fecha=2026-11-03" "id_cuenta=$CM" "id_categoria=$KI"
contains "editar con una categoría de otro tipo se rechaza" "no es de gastos" "$TMP/body"
check "y no cambió" "$KG" "$(sql "SELECT id_categoria FROM transacciones WHERE id_transaccion=$MID")"

request "$JM" GET /movimiento-eliminar.php;  check "eliminar por GET no está permitido" "405" "$CODE"
request "$JM" POST /movimiento-eliminar.php --data-urlencode "id=$MID"
check "eliminar sin token CSRF se rechaza" "403" "$CODE"
ORIGIN_SENT="http://evil.example" post_form "$JM" /movimiento-eliminar.php /movimientos.php "id=$MID"
check "eliminar desde otro origen se rechaza" "403" "$CODE"
check "el movimiento sigue ahí" "1" "$(sql "SELECT COUNT(*) FROM transacciones WHERE id_transaccion=$MID")"
post_form "$JM" /movimiento-eliminar.php /movimientos.php "id=abc"
check "un id no numérico es 400" "400" "$CODE"
post_form "$JM" /movimiento-eliminar.php /movimientos.php "id=99999999"
check "un id inexistente es 404" "404" "$CODE"
request "$J7" POST /movimiento-eliminar.php --data-urlencode "id=$MID"
check "sin sesión, ni llega a validarse" "302 /login.php" "$CODE $LOC"
post_form "$JM" /movimiento-eliminar.php /movimientos.php "id=$MID"
check "eliminar redirige al listado" "302 /movimientos.php" "$CODE $LOC"
check "el movimiento ya no existe" "0" "$(sql "SELECT COUNT(*) FROM transacciones WHERE id_transaccion=$MID")"
request "$JM" GET /movimientos.php;  contains "avisa que se eliminó" "Se eliminó el movimiento" "$TMP/body"
request "$JM" GET /cuentas.php;      contains "y el saldo vuelve a lo anterior (RF-14)" '$150.000' "$TMP/body"

echo; echo "== Movimientos: cuenta inactiva (D8)"
post_form "$JM" /cuentas.php /cuentas.php "id=$CM" "accion=desactivar"
request "$JM" GET /movimiento-form.php;  contains "sin cuentas activas el formulario lo dice" "al menos una cuenta activa" "$TMP/body"
NMOV="$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UMID")"
post_form "$JM" /movimiento-form.php /movimientos.php "tipo=gasto" "monto=1000" "fecha=2026-11-03" "id_cuenta=$CM" "id_categoria=$KG"
check "un POST directo a una cuenta inactiva se rechaza" "200" "$CODE"
contains "con un aviso claro" "está inactiva" "$TMP/body"
check "y no se guardó" "$NMOV" "$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UMID")"
post_form "$JM" /cuentas.php /cuentas.php "id=$CM" "accion=activar"
request "$JM" GET /movimiento-form.php;  contains "reactivada, vuelve a estar disponible" ">Efectivo · " "$TMP/body"

echo; echo "== Movimientos: filtros y paginación (RF-11, RF-12, RNF-09)"
sql "INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha, descripcion) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i < 45) SELECT $UMID, $CM, $KG, 'gasto', 10.00, DATE_ADD('2026-09-01', INTERVAL i DAY), CONCAT('Masivo ', i) FROM n" >/dev/null
TOTAL="$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UMID")"
request "$JM" GET /movimientos.php
contains "el resumen cuenta todos los movimientos" "$TOTAL movimientos" "$TMP/body"
check "la primera página trae 20 filas" "20" "$(grep -o 'data-label="Fecha"' "$TMP/body" | wc -l | tr -d ' ')"
contains "hay enlace a la página 3" 'aria-label="Página 3"' "$TMP/body"
absent "pero no a una cuarta" 'aria-label="Página 4"' "$TMP/body"
contains "el resumen suma ingresos y gastos de todo, no de la página" "Ingresos" "$TMP/body"
request "$JM" GET "/movimientos.php?pagina=3"
check "la tercera página trae el resto" "$((TOTAL - 40))" "$(grep -o 'data-label="Fecha"' "$TMP/body" | wc -l | tr -d ' ')"
request "$JM" GET "/movimientos.php?pagina=999"
check "una página fuera de rango se ajusta a la última" "$((TOTAL - 40))" "$(grep -o 'data-label="Fecha"' "$TMP/body" | wc -l | tr -d ' ')"
request "$JM" GET "/movimientos.php?pagina=abc"
check "una página no numérica es la primera" "20" "$(grep -o 'data-label="Fecha"' "$TMP/body" | wc -l | tr -d ' ')"
request "$JM" GET "/movimientos.php?pagina[]=2&tipo[]=gasto&cuenta[]=1"
check "parámetros con forma de arreglo no rompen la página" "200" "$CODE"

request "$JM" GET "/movimientos.php?tipo=ingreso"
contains "filtrar por tipo ingreso" "1 movimiento" "$TMP/body"
contains "solo muestra el ingreso" '+$50.000' "$TMP/body"
absent "y no los gastos" "Masivo" "$TMP/body"
request "$JM" GET "/movimientos.php?desde=2026-10-01&hasta=2026-10-15"
contains "filtrar por rango de fechas (los 15 de octubre)" "15 movimientos" "$TMP/body"
request "$JM" GET "/movimientos.php?desde=2026-09-02&hasta=2026-09-06"
contains "el rango incluye el primer y el último día" "5 movimientos" "$TMP/body"
request "$JM" GET "/movimientos.php?cuenta=$CM&categoria=$KI"
contains "filtrar por cuenta y categoría a la vez" "1 movimiento" "$TMP/body"
request "$JM" GET "/movimientos.php?cuenta=$CN"
contains "filtrar por la cuenta de otro usuario no trae nada" "No hay movimientos con esos filtros" "$TMP/body"
contains "y ofrece quitar los filtros" "Quitar filtros" "$TMP/body"
request "$JM" GET "/movimientos.php?desde=basura&hasta=2026-99-99&tipo=otro&cuenta=1;DROP"
check "filtros inválidos se ignoran sin romper" "200" "$CODE"
contains "y se muestra todo" "$TOTAL movimientos" "$TMP/body"
request "$JM" GET "/movimientos.php?tipo=gasto"
contains "los enlaces de página conservan el filtro" "tipo=gasto&amp;pagina=2" "$TMP/body"
contains "el selector de filtro recuerda lo elegido" '<option value="gasto" selected>' "$TMP/body"
request "$JM" GET "/movimientos.php?desde=2026-09-10&hasta=2026-09-20"
contains "el formulario de filtros recuerda las fechas" 'value="2026-09-10"' "$TMP/body"

request "$JM" GET "/movimientos.php?tipo=gasto&pagina=2"
LAST_ID="$(sql "SELECT id_transaccion FROM transacciones WHERE id_usuario=$UMID AND descripcion='Masivo 1'")"
post_form "$JM" /movimiento-eliminar.php "/movimientos.php?tipo=gasto&pagina=2" "id=$LAST_ID" "volver=tipo=gasto&pagina=2"
check "eliminar desde la página 2 vuelve a la misma página y filtro" "302 /movimientos.php?tipo=gasto&pagina=2" "$CODE $LOC"
post_form "$JM" /movimiento-eliminar.php "/movimientos.php" "id=$(sql "SELECT id_transaccion FROM transacciones WHERE id_usuario=$UMID AND descripcion='Masivo 2'")" "volver=desde=basura&pagina=9&evil=http://x.com"
check "«volver» solo admite parámetros conocidos y limpios" "302 /movimientos.php?pagina=9" "$CODE $LOC"

echo; echo "== Presupuestos (RF-15 a RF-17)"
# Los registros de las secciones anteriores ya acercan la IP al límite de RNF-24 (20 en 15 min): se parte de cero.
sql "DELETE FROM intentos_acceso" >/dev/null
JP="$(new_jar)"; UP="smoke_${RUN}_presA@correo.com"; register "$JP" "$UP" "$P"
JQ="$(new_jar)"; UQ="smoke_${RUN}_presB@correo.com"; register "$JQ" "$UQ" "$P"
UPID="$(uid "$UP")"; UQID="$(uid "$UQ")"
post_form "$JP" /cuenta-form.php /cuenta-form.php "nombre=Efectivo" "tipo=efectivo" "saldo_inicial=5.000.000"
post_form "$JQ" /cuenta-form.php /cuenta-form.php "nombre=Cuenta de Q" "tipo=efectivo" "saldo_inicial=1.000.000"
CP="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UPID")"; CQ="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UQID")"
post_form "$JQ" /categorias.php /categorias.php "accion=crear" "nombre=Solo de Q" "tipo=gasto" "color=#123456"
KQ="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario=$UQID")"
KT="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND nombre='Transporte'")"
request "$J7" GET /presupuestos.php;      check "sin sesión, los presupuestos exigen login" "302 /login.php" "$CODE $LOC"
request "$J7" GET /presupuesto-form.php;  check "y el formulario también" "302 /login.php" "$CODE $LOC"
request "$JP" GET "/presupuestos.php?mes=11&anio=2026";  check "el listado responde 200" "200" "$CODE"
contains "sin presupuestos invita a crear uno" "No tienes presupuestos para Noviembre 2026" "$TMP/body"
contains "con la navegación de meses" "mes=10&amp;anio=2026" "$TMP/body"
contains "hacia atrás y adelante" "mes=12&amp;anio=2026" "$TMP/body"
request "$JP" GET "/presupuestos.php?mes=13&anio=abc";  check "un mes inválido cae al mes actual sin romper" "200" "$CODE"
request "$JP" GET "/presupuestos.php?mes[]=1&anio[]=2";  check "parámetros con forma de arreglo no rompen" "200" "$CODE"

request "$JP" GET "/presupuesto-form.php?mes=11&anio=2026"
check "el formulario responde 200" "200" "$CODE"
contains "ofrece las categorías de gastos" ">Alimentación</option>" "$TMP/body"
absent "no las de ingresos" ">Salario</option>" "$TMP/body"
absent "ni las categorías propias de otro usuario" "Solo de Q" "$TMP/body"
contains "con el mes pedido preseleccionado" '<option value="11" selected>' "$TMP/body"

post_form "$JP" /presupuesto-form.php /presupuesto-form.php "id_categoria=$KG" "mes=11" "anio=2026" "monto_limite=400.000"
check "crear un presupuesto redirige al mes" "302 /presupuestos.php?mes=11&anio=2026" "$CODE $LOC"
check "se guardó con el límite exacto" "400000.00|11|2026" "$(sql "SELECT CONCAT(monto_limite,'|',mes,'|',anio) FROM presupuestos WHERE id_usuario=$UPID AND id_categoria=$KG")"
PID="$(sql "SELECT id_presupuesto FROM presupuestos WHERE id_usuario=$UPID AND id_categoria=$KG")"
request "$JP" GET "/presupuestos.php?mes=11&anio=2026"
contains "avisa que se creó" "Se creó el presupuesto de «Alimentación»" "$TMP/body"
contains "el listado muestra la categoría" "Alimentación" "$TMP/body"
contains "en orden y sin gastar" "En orden" "$TMP/body"
contains "con lo gastado y el límite" '$0 de $400.000' "$TMP/body"
contains "con la barra de progreso accesible" 'role="progressbar" aria-valuenow="0"' "$TMP/body"
post_form "$JP" /presupuesto-form.php /presupuesto-form.php "id_categoria=$KG" "mes=11" "anio=2026" "monto_limite=1"
check "un presupuesto repetido (categoría y mes) se rechaza" "200" "$CODE"; contains "con un aviso claro" "Ya tienes un presupuesto para" "$TMP/body"
post_form "$JP" /presupuesto-form.php /presupuesto-form.php "id_categoria=$KI" "mes=11" "anio=2026" "monto_limite=1.000"
contains "una categoría de ingresos se rechaza" "solo para categorías de gastos" "$TMP/body"
post_form "$JP" /presupuesto-form.php /presupuesto-form.php "id_categoria=$KT" "mes=13" "anio=2026" "monto_limite=1.000"
contains "un mes inválido se rechaza" "mes y un año válidos" "$TMP/body"
post_form "$JP" /presupuesto-form.php /presupuesto-form.php "id_categoria=$KT" "mes=11" "anio=2026" "monto_limite=0"
contains "un límite cero se rechaza" "mayor que cero" "$TMP/body"
post_form "$JP" /presupuesto-form.php /presupuesto-form.php "id_categoria=$KT" "mes=11" "anio=2026" "monto_limite=mucho"
contains "un límite inválido se rechaza" "monto válido" "$TMP/body"
post_form "$JP" /presupuesto-form.php /presupuesto-form.php "id_categoria=$KQ" "mes=11" "anio=2026" "monto_limite=1.000"
check "usar la categoría propia de otro usuario es 404" "404" "$CODE"
post_form "$JP" /presupuesto-form.php /presupuesto-form.php "id_categoria=99999999" "mes=11" "anio=2026" "monto_limite=1.000"
check "una categoría inexistente es 404" "404" "$CODE"
check "nada de lo rechazado se guardó" "1" "$(sql "SELECT COUNT(*) FROM presupuestos WHERE id_usuario=$UPID")"

echo; echo "== Presupuestos: avisos al 80 % y al 100 % (RF-17)"
g() { post_form "$JP" /movimiento-form.php /movimiento-form.php "tipo=gasto" "monto=$1" "fecha=${3:-2026-11-10}" "id_cuenta=$CP" "id_categoria=${2:-$KG}" "descripcion=Gasto de prueba"; }
g "300.000"; request "$JP" GET /movimientos.php
contains "un gasto al 75 % se guarda sin aviso" "Se registró el gasto de \$300.000" "$TMP/body"
absent "no hay aviso" "Aviso:" "$TMP/body"
g "19.999,99"; request "$JP" GET /movimientos.php
absent "319.999,99 (79,99 %) todavía no avisa" "Aviso:" "$TMP/body"
g "0,01"; request "$JP" GET /movimientos.php
contains "320.000 (exactamente el 80 %) avisa" "Aviso: llevas el 80 % del presupuesto de «Alimentación»" "$TMP/body"
contains "y el aviso es de tipo advertencia" "notice notice--warn" "$TMP/body"
g "79.999,99"; request "$JP" GET /movimientos.php
contains "al 99,99 % sigue avisando" "Aviso: llevas el 99 %" "$TMP/body"
g "0,01"; request "$JP" GET /movimientos.php
contains "exactamente el límite: avisa que se llegó" "llegaste al límite del presupuesto de «Alimentación»" "$TMP/body"
g "1"; request "$JP" GET /movimientos.php
contains "un peso más: avisa que se superó" "superaste el presupuesto de «Alimentación»" "$TMP/body"
g "1.000" "$KT"; request "$JP" GET /movimientos.php
absent "un gasto en una categoría sin presupuesto no avisa" "Aviso:" "$TMP/body"
g "1.000" "$KG" "2026-12-05"; request "$JP" GET /movimientos.php
absent "un gasto en otro mes no avisa por el presupuesto de noviembre" "Aviso:" "$TMP/body"
post_form "$JP" /movimiento-form.php /movimiento-form.php "tipo=ingreso" "monto=1.000" "fecha=2026-11-10" "id_cuenta=$CP" "id_categoria=$KI" "descripcion="
request "$JP" GET /movimientos.php;  absent "un ingreso nunca avisa" "Aviso:" "$TMP/body"
request "$JP" GET "/presupuestos.php?mes=11&anio=2026"
contains "el listado muestra el presupuesto excedido" "Excedido" "$TMP/body"
contains "con el porcentaje" ">100 %<" "$TMP/body"
contains "y el aviso de que se superó, en la parte de arriba" "superaste el presupuesto de <b>Alimentación</b>" "$TMP/body"
contains "la barra dice 100 (no más) pero conserva el valor real" 'aria-valuenow="100"' "$TMP/body"
contains "el resumen suma lo presupuestado y lo gastado" "Presupuestado" "$TMP/body"
request "$JP" GET "/presupuestos.php?mes=12&anio=2026"
contains "el mes de diciembre está vacío (el gasto del 5 de dic no tiene presupuesto)" "No tienes presupuestos para Diciembre 2026" "$TMP/body"

echo; echo "== Presupuestos: editar, ajeno y eliminar"
request "$JP" GET "/presupuesto-form.php?id=$PID"
contains "editar muestra la categoría y el mes fijos" "Noviembre 2026" "$TMP/body"
contains "y el límite actual" 'value="400.000"' "$TMP/body"
post_form "$JP" /presupuesto-form.php "/presupuesto-form.php?id=$PID" "id=$PID" "monto_limite=1.000.000" "id_categoria=$KT" "mes=3" "anio=2030"
check "guardar redirige al mismo mes" "302 /presupuestos.php?mes=11&anio=2026" "$CODE $LOC"
check "se cambió el límite y NO la categoría ni el mes (aunque el formulario lo intente)" "1000000.00|$KG|11|2026" "$(sql "SELECT CONCAT(monto_limite,'|',id_categoria,'|',mes,'|',anio) FROM presupuestos WHERE id_presupuesto=$PID")"
request "$JP" GET "/presupuestos.php?mes=11&anio=2026"
contains "con el límite nuevo vuelve a estar en orden" "En orden" "$TMP/body"
request "$JQ" GET "/presupuesto-form.php?id=$PID";  check "abrir el presupuesto de otro usuario es 404" "404" "$CODE"
post_form "$JQ" /presupuesto-form.php /presupuesto-form.php "id=$PID" "monto_limite=1"
check "editarlo es 404" "404" "$CODE"
post_form "$JQ" /presupuestos.php /presupuestos.php "id=$PID" "accion=eliminar"
check "eliminarlo es 404" "404" "$CODE"
check "el presupuesto sigue intacto" "1000000.00" "$(sql "SELECT monto_limite FROM presupuestos WHERE id_presupuesto=$PID")"
request "$JQ" GET "/presupuestos.php?mes=11&anio=2026";  absent "otro usuario no ve mis presupuestos" "Alimentación" "$TMP/body"
request "$JP" GET "/presupuesto-form.php?id=abc";  check "un id no numérico es 404" "404" "$CODE"
request "$JP" POST /presupuestos.php --data-urlencode "id=$PID" --data-urlencode "accion=eliminar"
check "eliminar sin token CSRF se rechaza" "403" "$CODE"
post_form "$JP" /presupuestos.php /presupuestos.php "id=$PID" "accion=borrar"
check "una acción desconocida es 400" "400" "$CODE"
NMOV="$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UPID")"
post_form "$JP" "/presupuestos.php?mes=11&anio=2026" "/presupuestos.php?mes=11&anio=2026" "id=$PID" "accion=eliminar"
check "eliminar redirige al mismo mes" "302 /presupuestos.php?mes=11&anio=2026" "$CODE $LOC"
check "el presupuesto ya no existe" "0" "$(sql "SELECT COUNT(*) FROM presupuestos WHERE id_presupuesto=$PID")"
check "y los movimientos siguen ahí" "$NMOV" "$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UPID")"

echo; echo "== Metas de ahorro (RF-18 a RF-20)"
FUT="$(php_eval "echo date('Y-m-d', strtotime('+1 year'));")"
request "$J7" GET /metas.php;       check "sin sesión, las metas exigen login" "302 /login.php" "$CODE $LOC"
request "$J7" GET /meta-form.php;   check "el formulario también" "302 /login.php" "$CODE $LOC"
request "$J7" GET "/meta-aporte.php?id=1";  check "y los aportes" "302 /login.php" "$CODE $LOC"
request "$JP" GET /metas.php;       contains "sin metas invita a crear la primera" "Aún no tienes metas" "$TMP/body"
post_form "$JP" /meta-form.php /meta-form.php "nombre=Viaje a Cartagena" "monto_objetivo=300.000" "fecha_limite=$FUT"
check "crear una meta redirige al listado" "302 /metas.php" "$CODE $LOC"
check "se guardó con el objetivo exacto" "Viaje a Cartagena|300000.00|en_curso" "$(sql "SELECT CONCAT(nombre,'|',monto_objetivo,'|',estado) FROM metas_ahorro WHERE id_usuario=$UPID")"
MID1="$(sql "SELECT id_meta FROM metas_ahorro WHERE id_usuario=$UPID")"
request "$JP" GET /metas.php
contains "el listado muestra la meta" "Viaje a Cartagena" "$TMP/body"
contains "con lo que falta (RF-20)" 'Faltan $300.000' "$TMP/body"
contains "y el porcentaje" ">0 %<" "$TMP/body"
contains "y el botón de aportar" "Añadir aporte" "$TMP/body"
post_form "$JP" /meta-form.php /meta-form.php "nombre=Pasada" "monto_objetivo=1.000" "fecha_limite=2020-01-01"
contains "una fecha límite pasada se rechaza" "no puede ser anterior a hoy" "$TMP/body"
post_form "$JP" /meta-form.php /meta-form.php "nombre=X" "monto_objetivo=1.000"
contains "un nombre corto se rechaza" "Escribe un nombre" "$TMP/body"
post_form "$JP" /meta-form.php /meta-form.php "nombre=Sin objetivo" "monto_objetivo=0"
contains "un objetivo cero se rechaza" "mayor que cero" "$TMP/body"
post_form "$JP" /meta-form.php /meta-form.php "nombre=<b>Negrita</b>" "monto_objetivo=1.000"
request "$JP" GET /metas.php
contains "un nombre con HTML se escapa" "&lt;b&gt;Negrita&lt;/b&gt;" "$TMP/body"
absent "y no aparece como etiqueta" "<b>Negrita" "$TMP/body"
sql "DELETE FROM metas_ahorro WHERE nombre LIKE '<b>%'" >/dev/null
check "lo rechazado no se guardó" "1" "$(sql "SELECT COUNT(*) FROM metas_ahorro WHERE id_usuario=$UPID")"

echo; echo "== Metas: aportes (RF-19, D8)"
request "$JP" GET "/meta-aporte.php?id=$MID1"
check "el formulario de aporte responde 200" "200" "$CODE"
contains "muestra la cuenta con su saldo" "Efectivo · " "$TMP/body"
SALDO0="$(sql "SELECT 5000000 + COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=$CP AND tipo='ingreso'),0) - COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=$CP AND tipo='gasto'),0)")"
post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID1" "id=$MID1" "id_cuenta=$CP" "monto=100.000" "fecha=$(date +%F)"
check "aportar redirige al listado" "302 /metas.php" "$CODE $LOC"
check "el aporte se guardó exacto" "100000.00" "$(sql "SELECT monto FROM aportes_meta WHERE id_meta=$MID1")"
request "$JP" GET /metas.php
contains "avisa del aporte" 'Se aportaron $100.000 a «Viaje a Cartagena» desde «Efectivo»' "$TMP/body"
contains "la meta avanza al 33 % (RF-20)" ">33 %<" "$TMP/body"
contains "y faltan 200.000" 'Faltan $200.000' "$TMP/body"
request "$JP" GET "/meta-aporte.php?id=$MID1";  contains "el historial muestra el aporte" '+$100.000' "$TMP/body"
check "el saldo de la cuenta bajó 100.000 (los aportes cuentan en el saldo)" "$((${SALDO0%.*} - 100000))" "$(sql "SELECT c.saldo_inicial + COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta AND tipo='ingreso'),0) - COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta AND tipo='gasto'),0) - COALESCE((SELECT SUM(monto) FROM aportes_meta WHERE id_cuenta=c.id_cuenta),0) FROM cuentas c WHERE c.id_cuenta=$CP" | cut -d. -f1)"

SALDO_ACTUAL="$(sql "SELECT c.saldo_inicial + COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta AND tipo='ingreso'),0) - COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta AND tipo='gasto'),0) - COALESCE((SELECT SUM(monto) FROM aportes_meta WHERE id_cuenta=c.id_cuenta),0) FROM cuentas c WHERE c.id_cuenta=$CP")"
post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID1" "id=$MID1" "id_cuenta=$CP" "monto=$(( ${SALDO_ACTUAL%.*} + 1 ))" "fecha=$(date +%F)"
check "un aporte mayor que el saldo se rechaza" "200" "$CODE"; contains "con un aviso claro" "no alcanza para aportar" "$TMP/body"
check "y no se guardó (sigue 1 solo aporte)" "1" "$(sql "SELECT COUNT(*) FROM aportes_meta WHERE id_meta=$MID1")"
post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID1" "id=$MID1" "id_cuenta=$CP" "monto=0" "fecha=$(date +%F)"
contains "un aporte de cero se rechaza" "mayor que cero" "$TMP/body"
post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID1" "id=$MID1" "id_cuenta=" "monto=1000" "fecha=$(date +%F)"
contains "sin cuenta se rechaza" "Elige la cuenta" "$TMP/body"
post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID1" "id=$MID1" "id_cuenta=$CP" "monto=1000" "fecha=2026-02-31"
contains "una fecha imposible se rechaza" "fecha válida" "$TMP/body"
post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID1" "id=$MID1" "id_cuenta=$CQ" "monto=1000" "fecha=$(date +%F)"
check "aportar desde la cuenta de otro usuario es 404" "404" "$CODE"
post_form "$JP" /cuentas.php /cuentas.php "id=$CP" "accion=desactivar"
post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID1" "id=$MID1" "id_cuenta=$CP" "monto=1000" "fecha=$(date +%F)"
check "aportar desde una cuenta inactiva se rechaza" "200" "$CODE"; contains "con un aviso claro" "está inactiva" "$TMP/body"
request "$JP" GET "/meta-aporte.php?id=$MID1";  contains "y sin cuentas activas lo dice" "al menos una cuenta activa" "$TMP/body"
post_form "$JP" /cuentas.php /cuentas.php "id=$CP" "accion=activar"

post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID1" "id=$MID1" "id_cuenta=$CP" "monto=199.999,99" "fecha=$(date +%F)"
check "un centavo antes del objetivo: sigue en curso" "en_curso" "$(sql "SELECT estado FROM metas_ahorro WHERE id_meta=$MID1")"
post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID1" "id=$MID1" "id_cuenta=$CP" "monto=0,01" "fecha=$(date +%F)"
check "el aporte que completa el objetivo marca la meta como lograda" "lograda" "$(sql "SELECT estado FROM metas_ahorro WHERE id_meta=$MID1")"
request "$JP" GET /metas.php
contains "se felicita" "¡Lograste tu meta!" "$TMP/body"
contains "la meta aparece como lograda" "Lograda" "$TMP/body"
contains "con el objetivo alcanzado" "Objetivo alcanzado" "$TMP/body"
absent "y ya no ofrece aportar" "Añadir aporte" "$TMP/body"
request "$JP" GET "/meta-aporte.php?id=$MID1"
check "abrir los aportes de una meta lograda redirige" "302 /metas.php" "$CODE $LOC"
post_form "$JP" /meta-aporte.php /metas.php "id=$MID1" "id_cuenta=$CP" "monto=1000" "fecha=$(date +%F)"
check "aportar a una meta lograda no se permite" "302 /metas.php" "$CODE $LOC"
check "y no se guardó" "3" "$(sql "SELECT COUNT(*) FROM aportes_meta WHERE id_meta=$MID1")"
request "$JP" GET "/meta-form.php?id=$MID1";  check "una meta lograda no se edita" "302 /metas.php" "$CODE $LOC"

echo; echo "== Metas: editar, cancelar y eliminar"
post_form "$JP" /meta-form.php /meta-form.php "nombre=Computador" "monto_objetivo=3.000.000" "fecha_limite="
MID2="$(sql "SELECT id_meta FROM metas_ahorro WHERE id_usuario=$UPID AND nombre='Computador'")"
request "$JP" GET "/meta-form.php?id=$MID2"
contains "editar muestra el nombre" 'value="Computador"' "$TMP/body"
contains "y el objetivo" 'value="3.000.000"' "$TMP/body"
post_form "$JP" /meta-form.php "/meta-form.php?id=$MID2" "id=$MID2" "nombre=Portátil" "monto_objetivo=2.500.000" "fecha_limite=$FUT"
check "guardar redirige al listado" "302 /metas.php" "$CODE $LOC"
check "el cambio quedó en la BD" "Portátil|2500000.00|$FUT" "$(sql "SELECT CONCAT(nombre,'|',monto_objetivo,'|',fecha_limite) FROM metas_ahorro WHERE id_meta=$MID2")"
post_form "$JP" /meta-aporte.php "/meta-aporte.php?id=$MID2" "id=$MID2" "id_cuenta=$CP" "monto=50.000" "fecha=$(date +%F)"
SALDO_ANTES_CANCELAR="$(sql "SELECT c.saldo_inicial + COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta AND tipo='ingreso'),0) - COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta AND tipo='gasto'),0) - COALESCE((SELECT SUM(monto) FROM aportes_meta WHERE id_cuenta=c.id_cuenta),0) FROM cuentas c WHERE c.id_cuenta=$CP")"
post_form "$JP" /metas.php /metas.php "id=$MID2" "accion=eliminar"
request "$JP" GET /metas.php;  contains "no se puede eliminar una meta con aportes" "tiene aportes" "$TMP/body"
check "la meta sigue existiendo" "1" "$(sql "SELECT COUNT(*) FROM metas_ahorro WHERE id_meta=$MID2")"
post_form "$JP" /metas.php /metas.php "id=$MID2" "accion=cancelar"
check "cancelar redirige al listado" "302 /metas.php" "$CODE $LOC"
check "la meta queda cancelada y sin aportes" "cancelada|0" "$(sql "SELECT CONCAT(estado,'|',(SELECT COUNT(*) FROM aportes_meta WHERE id_meta=$MID2)) FROM metas_ahorro WHERE id_meta=$MID2")"
request "$JP" GET /metas.php
contains "avisa que el dinero volvió a sus cuentas" 'Los $50.000 aportados volvieron a sus cuentas' "$TMP/body"
contains "la meta se ve como cancelada" "Cancelada" "$TMP/body"
check "el saldo de la cuenta se recuperó (+50.000)" "$((${SALDO_ANTES_CANCELAR%.*} + 50000))" "$(sql "SELECT c.saldo_inicial + COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta AND tipo='ingreso'),0) - COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta AND tipo='gasto'),0) - COALESCE((SELECT SUM(monto) FROM aportes_meta WHERE id_cuenta=c.id_cuenta),0) FROM cuentas c WHERE c.id_cuenta=$CP" | cut -d. -f1)"
post_form "$JP" /metas.php /metas.php "id=$MID2" "accion=cancelar"
request "$JP" GET /metas.php;  contains "cancelar otra vez avisa" "ya no se puede cancelar" "$TMP/body"
request "$JP" GET "/meta-aporte.php?id=$MID2";  check "una meta cancelada no admite aportes" "302 /metas.php" "$CODE $LOC"
post_form "$JP" /metas.php /metas.php "id=$MID2" "accion=eliminar"
check "una meta cancelada (sin aportes) se elimina" "0" "$(sql "SELECT COUNT(*) FROM metas_ahorro WHERE id_meta=$MID2")"

echo; echo "== Metas: lo ajeno no existe y la seguridad de los POST (RNF-04)"
post_form "$JP" /meta-form.php /meta-form.php "nombre=Mi meta" "monto_objetivo=10.000"
MID3="$(sql "SELECT id_meta FROM metas_ahorro WHERE id_usuario=$UPID AND nombre='Mi meta'")"
request "$JQ" GET "/meta-aporte.php?id=$MID3";  check "abrir los aportes de la meta de otro usuario es 404" "404" "$CODE"
post_form "$JQ" /meta-aporte.php /metas.php "id=$MID3" "id_cuenta=$CQ" "monto=1000" "fecha=$(date +%F)"
check "aportar a la meta de otro usuario es 404" "404" "$CODE"
request "$JQ" GET "/meta-form.php?id=$MID3";  check "abrir la edición de la meta de otro usuario es 404" "404" "$CODE"
post_form "$JQ" /meta-form.php /meta-form.php "id=$MID3" "nombre=Robada" "monto_objetivo=1"
check "editarla es 404" "404" "$CODE"
post_form "$JQ" /metas.php /metas.php "id=$MID3" "accion=cancelar"
check "cancelarla es 404" "404" "$CODE"
post_form "$JQ" /metas.php /metas.php "id=$MID3" "accion=eliminar"
check "eliminarla es 404" "404" "$CODE"
check "la meta sigue intacta" "Mi meta|en_curso" "$(sql "SELECT CONCAT(nombre,'|',estado) FROM metas_ahorro WHERE id_meta=$MID3")"
request "$JQ" GET /metas.php;  absent "otro usuario no ve mis metas" "Mi meta" "$TMP/body"
request "$JP" GET "/meta-aporte.php?id=abc";  check "un id no numérico es 404" "404" "$CODE"
request "$JP" GET "/meta-form.php?id=abc";    check "en la edición también" "404" "$CODE"
request "$JP" GET "/meta-aporte.php";         check "sin id es 404" "404" "$CODE"
request "$JP" POST /metas.php --data-urlencode "id=$MID3" --data-urlencode "accion=cancelar"
check "cancelar sin token CSRF se rechaza" "403" "$CODE"
request "$JP" POST /meta-aporte.php --data-urlencode "id=$MID3" --data-urlencode "id_cuenta=$CP" --data-urlencode "monto=1000"
check "aportar sin token CSRF se rechaza" "403" "$CODE"
ORIGIN_SENT="http://evil.example" post_form "$JP" /metas.php /metas.php "id=$MID3" "accion=cancelar"
check "cancelar desde otro origen se rechaza" "403" "$CODE"
post_form "$JP" /metas.php /metas.php "id=$MID3" "accion=borrar"
check "una acción desconocida es 400" "400" "$CODE"
check "nada de eso cambió la meta" "en_curso" "$(sql "SELECT estado FROM metas_ahorro WHERE id_meta=$MID3")"

echo; echo "== Panel y reportes (RF-21, RF-22)"
sql "DELETE FROM intentos_acceso" >/dev/null
MESES_ES=(enero febrero marzo abril mayo junio julio agosto septiembre octubre noviembre diciembre)
read -r HOY MES_N ANIO_N MES_ACT MES_ANT MES_ANT2 ANT_N ANT_A ULT_DIA <<<"$(php_eval "
    \$h = new DateTimeImmutable('now');
    \$p1 = \$h->modify('first day of this month')->modify('-1 month');
    \$p2 = \$h->modify('first day of this month')->modify('-2 months');
    \$u = \$h->modify('last day of this month');
    echo implode(' ', [\$h->format('Y-m-d'), \$h->format('n'), \$h->format('Y'), \$h->format('Y-m'), \$p1->format('Y-m'), \$p2->format('Y-m'), \$p1->format('n'), \$p1->format('Y'), \$u->format('Y-m-d')]);
")"
MES_TXT="${MESES_ES[$((MES_N - 1))]}"
JR="$(new_jar)"; UR="smoke_${RUN}_repA@correo.com"; register "$JR" "$UR" "$P"
JT="$(new_jar)"; UT="smoke_${RUN}_repB@correo.com"; register "$JT" "$UT" "$P"
JV="$(new_jar)"; UV="smoke_${RUN}_repC@correo.com"; register "$JV" "$UV" "$P"
URID="$(uid "$UR")"; UTID="$(uid "$UT")"
cat_id() { sql "SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND nombre='$1'"; }
K_ALI="$(cat_id Alimentación)"; K_TRA="$(cat_id Transporte)"; K_ENT="$(cat_id Entretenimiento)"; K_SER="$(cat_id Servicios)"; K_SAL="$(cat_id Salud)"; K_VIV="$(cat_id Vivienda)"; K_SLR="$(cat_id Salario)"
post_form "$JR" /cuenta-form.php /cuenta-form.php "nombre=Efectivo" "tipo=efectivo" "saldo_inicial=1.000.000"
post_form "$JT" /cuenta-form.php /cuenta-form.php "nombre=Cuenta de T" "tipo=efectivo" "saldo_inicial=0"
CR="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$URID")"; CT="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UTID")"
mv() { sql "INSERT INTO transacciones (id_usuario,id_cuenta,id_categoria,tipo,monto,fecha,descripcion) VALUES ($1,$2,$3,'$4',$5,'$6',$7)" >/dev/null; }
mv "$URID" "$CR" "$K_SLR" ingreso 1800000 "$MES_ACT-01" "'Salario del mes'"
mv "$URID" "$CR" "$K_ALI" gasto 330000 "$MES_ACT-05" "'Mercado'"
mv "$URID" "$CR" "$K_TRA" gasto 80000 "$MES_ACT-06" "'Transporte del mes'"
mv "$URID" "$CR" "$K_ENT" gasto 150000 "$MES_ACT-07" "'Cine y salidas'"
mv "$URID" "$CR" "$K_SER" gasto 60000 "$MES_ACT-08" "'Internet'"
mv "$URID" "$CR" "$K_SAL" gasto 40000 "$MES_ACT-09" "'Medicinas'"
mv "$URID" "$CR" "$K_VIV" gasto 30000 "$MES_ACT-10" "'Arreglo'"
mv "$URID" "$CR" "$K_ALI" gasto 500000 "$MES_ANT-15" "'Mes pasado'"
mv "$URID" "$CR" "$K_ALI" gasto 700000 "$MES_ANT2-15" "'Hace dos meses'"
mv "$UTID" "$CT" "$K_ALI" gasto 77777 "$MES_ACT-05" "'SECRETO-OTRO'"
sql "INSERT INTO presupuestos (id_usuario,id_categoria,mes,anio,monto_limite) VALUES ($URID,$K_ALI,$MES_N,$ANIO_N,400000),($URID,$K_ENT,$MES_N,$ANIO_N,120000),($URID,$K_TRA,$MES_N,$ANIO_N,200000)" >/dev/null
mv "$URID" "$CR" "$K_ALI" gasto 1000 "$HOY" "'<script>alert(1)</script>'"

request "$J7" GET /panel.php;      check "sin sesión, el panel exige login" "302 /login.php" "$CODE $LOC"
request "$J7" GET /reportes.php;   check "y los reportes también" "302 /login.php" "$CODE $LOC"
request "$JR" GET /panel.php
check "el panel responde 200" "200" "$CODE"
contains "saluda por el primer nombre" "Hola, Prueba" "$TMP/body"
contains "con el mes en curso" "Resumen de $MES_TXT de $ANIO_N" "$TMP/body"
contains "saldo total = 1.000.000 + 1.800.000 − gastos de los tres meses (2.891.000 − 691.000 − 1.200.000)" '$909.000' "$TMP/body"
contains "repartido en 1 cuenta" "Repartido en 1 cuenta" "$TMP/body"
contains "ingresos del mes" '$1.800.000' "$TMP/body"
contains "gastos del mes (los 6 + el de 1.000)" '$691.000' "$TMP/body"
contains "el balance del mes con signo" '+$1.109.000' "$TMP/body"
check "el gráfico circular tiene 5 porciones (las 4 mayores y «Otros»)" "5" "$(grep -o 'class="donut__slice ' "$TMP/body" | wc -l | tr -d ' ')"
contains "con la leyenda «Otros»" "<span>Otros</span>" "$TMP/body"
contains "los últimos movimientos muestran los más recientes" "Arreglo" "$TMP/body"
absent "y no los más antiguos (solo 5)" "Hace dos meses" "$TMP/body"
contains "una descripción con HTML se escapa" "&lt;script&gt;alert(1)&lt;/script&gt;" "$TMP/body"
absent "y no aparece como etiqueta" "<script>alert(1)" "$TMP/body"
contains "avisa del presupuesto al 82 % (RF-17, en el panel)" "llevas el <b>82 %</b> del presupuesto de <b>Alimentación</b>" "$TMP/body"
contains "y del excedido" "superaste el presupuesto de <b>Entretenimiento</b>" "$TMP/body"
contains "los presupuestos del mes con su barra" 'role="progressbar" aria-valuenow="125"' "$TMP/body"
absent "no muestra lo de otro usuario" "SECRETO-OTRO" "$TMP/body"
check "cuenta como máximo 5 movimientos recientes" "5" "$(grep -o 'class="list-item"' "$TMP/body" | wc -l | tr -d ' ')"

request "$JV" GET /panel.php
contains "un usuario nuevo ve un panel vacío y amable" "Aún no tienes cuentas activas" "$TMP/body"
contains "sin gastos lo explica" "Todavía no registras gastos este mes" "$TMP/body"
contains "y sin movimientos invita a empezar" "Crear mi primera cuenta" "$TMP/body"
contains "sin presupuestos invita a crear uno" "No tienes presupuestos este mes" "$TMP/body"
absent "sin avisos" "Aviso:" "$TMP/body"

request "$JR" GET /reportes.php
check "los reportes responden 200" "200" "$CODE"
contains "muestran el gráfico circular por categoría" "donut__chart" "$TMP/body"
contains "con una tabla accesible de categorías" "Gastos por categoría de" "$TMP/body"
contains "con el total" '$691.000' "$TMP/body"
check "la evolución muestra 6 meses por defecto" "6" "$(grep -o 'class="bars__col' "$TMP/body" | wc -l | tr -d ' ')"
contains "con el promedio mensual" "Promedio mensual" "$TMP/body"
contains "y la tabla mes a mes" "Mes a mes" "$TMP/body"
contains "con enlace a 12 meses" "meses=12" "$TMP/body"
absent "sin datos de otros usuarios" "SECRETO-OTRO" "$TMP/body"
request "$JR" GET "/reportes.php?meses=12"
check "con 12 meses muestra 12 barras" "12" "$(grep -o 'class="bars__col' "$TMP/body" | wc -l | tr -d ' ')"
request "$JR" GET "/reportes.php?mes=$ANT_N&anio=$ANT_A"
contains "el mes anterior muestra sus gastos" '$500.000' "$TMP/body"
absent "y no los de este mes" '$691.000' "$TMP/body"
request "$JR" GET "/reportes.php?mes=1&anio=2000"
contains "un mes sin gastos lo dice" "No hay gastos en Enero 2000" "$TMP/body"
request "$JR" GET "/reportes.php?mes=13&anio=abc&meses=999"
check "parámetros inválidos no rompen" "200" "$CODE"
request "$JR" GET "/reportes.php?mes[]=1&anio[]=2&meses[]=3"
check "ni los de forma de arreglo" "200" "$CODE"
contains "el botón de exportar apunta al mes mostrado" "exportar.php?desde=" "$TMP/body"

echo; echo "== Exportar a CSV (RF-23)"
request "$J7" GET /exportar.php;  check "sin sesión, exportar redirige a login (no sale ningún dato)" "302 /login.php" "$CODE $LOC"
check "y el cuerpo no trae filas" "0" "$(grep -c 'Mercado' "$TMP/body" || true)"
request "$JR" GET /exportar.php
check "exportar responde 200" "200" "$CODE"
check "es un CSV en UTF-8" "Content-Type: text/csv; charset=UTF-8" "$(grep -i '^content-type' "$TMP/hdr" | tr -d '\r')"
check "se descarga como archivo con la fecha de hoy" "Content-Disposition: attachment; filename=\"lucas-movimientos-$HOY.csv\"" "$(grep -i '^content-disposition' "$TMP/hdr" | tr -d '\r')"
check "no se guarda en la caché" "Cache-Control: no-store" "$(grep -i '^cache-control' "$TMP/hdr" | tr -d '\r')"
check "empieza con el BOM de UTF-8 (para las tildes en Excel)" "efbbbf" "$(head -c 3 "$TMP/body" | od -An -tx1 | tr -d ' \n')"
check "la cabecera usa «;» como separador" "Fecha;Tipo;Categoría;Cuenta;Descripción;Monto" "$(tail -c +4 "$TMP/body" | head -n 1 | tr -d '\r')"
NMR="$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$URID")"
check "trae una fila por movimiento" "$((NMR + 1))" "$(wc -l < "$TMP/body" | tr -d ' ')"
contains "con las tildes bien" "Alimentación" "$TMP/body"
contains "los montos con coma decimal y sin separador de miles" ";330000,00" "$TMP/body"
contains "las fechas en formato ISO" "$MES_ACT-05;Gasto;Alimentación;Efectivo;Mercado;330000,00" "$TMP/body"
contains "un ingreso se marca como tal" ";Ingreso;Salario;Efectivo;Salario del mes;1800000,00" "$TMP/body"
absent "no exporta movimientos de otro usuario (RNF-04)" "SECRETO-OTRO" "$TMP/body"
check "el más reciente va primero" "$HOY" "$(sed -n 2p "$TMP/body" | cut -d';' -f1)"

request "$JR" GET "/exportar.php?tipo=ingreso"
check "con filtro de tipo solo exporta los ingresos" "2" "$(wc -l < "$TMP/body" | tr -d ' ')"
request "$JR" GET "/exportar.php?categoria=$K_ALI"
check "con filtro de categoría" "$(( $(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$URID AND id_categoria=$K_ALI") + 1 ))" "$(wc -l < "$TMP/body" | tr -d ' ')"
request "$JR" GET "/exportar.php?desde=$MES_ACT-01&hasta=$ULT_DIA"
check "con rango de fechas (solo este mes)" "$(( $(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$URID AND fecha >= '$MES_ACT-01' AND fecha <= '$ULT_DIA'") + 1 ))" "$(wc -l < "$TMP/body" | tr -d ' ')"
check "ese rango excluye los meses anteriores" "true" "$(awk -F';' 'NR > 1 { if ($1 < "'"$MES_ACT"'-01") bad = 1 } END { print (bad ? "false" : "true") }' "$TMP/body")"
request "$JR" GET "/exportar.php?desde=$MES_ACT-01&hasta=$MES_ACT-45"
check "una fecha imposible en «hasta» se ignora (no rompe ni filtra de más)" "200" "$CODE"
request "$JR" GET "/exportar.php?desde=basura&tipo=otro&cuenta=1;DROP&categoria[]=1"
check "filtros inválidos se ignoran: exporta todo" "$((NMR + 1))" "$(wc -l < "$TMP/body" | tr -d ' ')"
request "$JR" GET "/exportar.php?cuenta=$CT"
check "filtrar por la cuenta de otro usuario no trae nada (solo la cabecera)" "1" "$(wc -l < "$TMP/body" | tr -d ' ')"
request "$JR" GET "/movimientos.php?tipo=gasto"
contains "el listado de movimientos exporta con sus filtros" 'href="/exportar.php?tipo=gasto"' "$TMP/body"
request "$JV" GET /exportar.php
check "un usuario sin movimientos recibe solo la cabecera" "1" "$(wc -l < "$TMP/body" | tr -d ' ')"
request "$JA" GET /exportar.php
check "el administrador exporta solo lo suyo (nada de otros usuarios, RNF-21)" "1" "$(wc -l < "$TMP/body" | tr -d ' ')"

echo; echo "== Exportar: inyección de fórmulas y caracteres especiales"
mv "$URID" "$CR" "$K_ALI" gasto 1 "$HOY" "'=HYPERLINK(\"http://evil.example\",\"clic\")'"
mv "$URID" "$CR" "$K_ALI" gasto 2 "$HOY" "'+cmd|'' /C calc''!A0'"
mv "$URID" "$CR" "$K_ALI" gasto 3 "$HOY" "'@SUM(1+1)'"
mv "$URID" "$CR" "$K_ALI" gasto 4 "$HOY" "'-2+3'"
mv "$URID" "$CR" "$K_ALI" gasto 5 "$HOY" "'  =cmd con espacios'"
mv "$URID" "$CR" "$K_ALI" gasto 6 "$HOY" "'texto; con punto y coma y \"comillas\"'"
mv "$URID" "$CR" "$K_ALI" gasto 7 "$HOY" "'bus - metro'"
sql "INSERT INTO categorias (id_usuario, nombre, tipo, color) VALUES ($URID, '=cmd', 'gasto', '#000000')" >/dev/null
K_MALA="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario=$URID AND nombre='=cmd'")"
mv "$URID" "$CR" "$K_MALA" gasto 8 "$HOY" "NULL"
sql "INSERT INTO cuentas (id_usuario, nombre, tipo) VALUES ($URID, '@cuenta', 'efectivo')" >/dev/null
C_MALA="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$URID AND nombre='@cuenta'")"
mv "$URID" "$C_MALA" "$K_ALI" gasto 9 "$HOY" "NULL"
request "$JR" GET /exportar.php
contains "una fórmula =HYPERLINK queda como texto (con comilla simple)" "'=HYPERLINK(" "$TMP/body"
absent "y ninguna celda empieza con =HYPERLINK" ";=HYPERLINK" "$TMP/body"
absent "ni entrecomillada al principio de la celda" ';"=HYPERLINK' "$TMP/body"
contains "+cmd también" "'+cmd" "$TMP/body"
absent "sin celda que empiece con +cmd" ";+cmd" "$TMP/body"
contains "@SUM también" "'@SUM(1+1)" "$TMP/body"
absent "sin celda que empiece con @SUM" ";@SUM" "$TMP/body"
contains "un texto que empieza con - también" "'-2+3" "$TMP/body"
contains "con espacios antes del = también" "'  =cmd con espacios" "$TMP/body"
contains "la categoría que empieza con = también se neutraliza" ";'=cmd;" "$TMP/body"
contains "y el nombre de cuenta que empieza con @" ";'@cuenta;" "$TMP/body"
contains "un texto con «;» y comillas va entrecomillado y con comillas dobles" '"texto; con punto y coma y ""comillas"""' "$TMP/body"
contains "un guion en medio no se toca" ";bus - metro;" "$TMP/body"
check "ninguna celda de texto empieza con un carácter de fórmula" "0" "$(sed 1d "$TMP/body" | tr -d '\r' | awk -F';' '{ for (i = 3; i <= 5; i++) if ($i ~ /^[=+@-]/) bad++ } END { print bad + 0 }')"

echo; echo "== Exportar: un archivo grande, por lotes"
sql "SET SESSION cte_max_recursion_depth = 5000; INSERT INTO transacciones (id_usuario,id_cuenta,id_categoria,tipo,monto,fecha,descripcion) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i < 2300) SELECT $URID, $CR, $K_ALI, 'gasto', 1.00, DATE_SUB('$HOY', INTERVAL (i % 40) DAY), CONCAT('Lote ', i) FROM n" >/dev/null
NG="$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$URID")"
request "$JR" GET /exportar.php
check "2.300+ filas cruzan varios lotes de 1.000 sin perder ni repetir" "$((NG + 1))" "$(wc -l < "$TMP/body" | tr -d ' ')"
check "ninguna fila 'Lote N' se repite" "0" "$(grep -o 'Lote [0-9]*' "$TMP/body" | sort | uniq -d | wc -l | tr -d ' ')"
check "salieron las 2.300 filas 'Lote' (la inserción masiva funcionó)" "2300" "$(sql "SELECT COUNT(*) FROM transacciones WHERE id_usuario=$URID AND descripcion LIKE 'Lote %'")"
check "y todas están en el archivo" "2300" "$(grep -c 'Lote [0-9]' "$TMP/body")"
check "el orden es de la fecha más reciente a la más antigua" "0" "$(sed 1d "$TMP/body" | cut -d';' -f1 | awk 'NR > 1 && $0 > prev { bad++ } { prev = $0 } END { print bad + 0 }')"

echo; echo "== Recuperar la contraseña por correo (RF-04, RNF-24)"
curl -s -o /dev/null "$MAILPIT/api/v1/info" || { echo "No responde Mailpit en $MAILPIT (docker compose up -d)."; exit 2; }
sql "DELETE FROM intentos_acceso" >/dev/null; mail_clear
UC1="smoke_${RUN}_recA@correo.com"; PV='clave-vieja-12'; PN='clave-nueva-34'
JQ1="$(new_jar)"; register "$JQ1" "$UC1" "$PV"
JQ2="$(new_jar)"; login "$JQ2" "$UC1" "$PV"
check "la segunda sesión del mismo usuario está abierta" "302 /panel.php" "$CODE $LOC"
request "$J7" GET /recuperar.php;  check "la página de recuperar responde 200" "200" "$CODE"
J8="$(new_jar)"
post_form "$J8" /recuperar.php /recuperar.php "correo=$UC1"
check "pedir el enlace responde 200" "200" "$CODE"
AVISO_REAL="$(grep -o 'Si ese correo está registrado[^<]*' "$TMP/body")"
contains "con un aviso" "Si ese correo está registrado" "$TMP/body"
post_form "$J8" /recuperar.php /recuperar.php "correo=nadie_${RUN}@correo.com"
AVISO_FALSO="$(grep -o 'Si ese correo está registrado[^<]*' "$TMP/body")"
check "un correo que NO existe recibe EXACTAMENTE el mismo aviso (no revela quién está registrado)" "$AVISO_REAL" "$AVISO_FALSO"
check "con el mismo código de respuesta" "200" "$CODE"
post_form "$J8" /recuperar.php /recuperar.php "correo=esto-no-es-un-correo"
check "un texto que ni es un correo también" "200" "$CODE"; contains "con el mismo aviso" "Si ese correo está registrado" "$TMP/body"
wait_mail "$UC1" 1 || true
check "al correo registrado le llegó UN mensaje" "1" "$(mail_count "$UC1")"
check "y al que no existe no se envió nada" "0" "$(mail_count "nadie_${RUN}@correo.com")"
TOK1="$(mail_token "$UC1")"
check "el mensaje trae un enlace con un token de 64 hexadecimales" "64" "${#TOK1}"
check "el token NO se guardó en la BD (solo su hash)" "0" "$(sql "SELECT COUNT(*) FROM restablecimientos WHERE token_hash='$TOK1'")"
check "hay un enlace pendiente guardado" "1" "$(sql "SELECT COUNT(*) FROM restablecimientos r JOIN usuarios u ON u.id_usuario=r.id_usuario WHERE u.correo='$UC1' AND r.usado_en IS NULL")"

request "$J8" GET "/restablecer.php?token=$TOK1"
check "el enlace del correo abre el formulario" "200" "$CODE"
contains "para elegir la contraseña nueva" "Contraseña nueva" "$TMP/body"
contains "reenviando el token en un campo oculto" "name=\"token\" value=\"$TOK1\"" "$TMP/body"
check "la página no se guarda en la caché" "Cache-Control: no-store" "$(grep -i '^cache-control' "$TMP/hdr" | tr -d '\r')"
post_form "$J8" /restablecer.php "/restablecer.php?token=$TOK1" "token=$TOK1" "clave=corta" "clave2=corta"
check "una contraseña corta se rechaza" "200" "$CODE"; contains "con un aviso" "al menos 8 caracteres" "$TMP/body"
contains "y el formulario sigue disponible (el enlace no se gastó)" "Contraseña nueva" "$TMP/body"
post_form "$J8" /restablecer.php "/restablecer.php?token=$TOK1" "token=$TOK1" "clave=$PN" "clave2=otra-distinta-1"
contains "contraseñas distintas se rechazan" "no coinciden" "$TMP/body"
request "$J8" POST /restablecer.php --data-urlencode "token=$TOK1" --data-urlencode "clave=$PN" --data-urlencode "clave2=$PN"
check "restablecer sin token CSRF se rechaza" "403" "$CODE"
ORIGIN_SENT="http://evil.example" post_form "$J8" /restablecer.php "/restablecer.php?token=$TOK1" "token=$TOK1" "clave=$PN" "clave2=$PN"
check "y desde otro origen" "403" "$CODE"
post_form "$J8" /restablecer.php "/restablecer.php?token=$TOK1" "token=$TOK1" "clave=$PN" "clave2=$PN"
check "con una contraseña válida redirige a login" "302 /login.php?msg=reset" "$CODE $LOC"
request "$J8" GET "/login.php?msg=reset";  contains "y lo avisa" "tu contraseña se cambió" "$TMP/body"
JN2="$(new_jar)"; login "$JN2" "$UC1" "$PV"
check "la contraseña VIEJA ya no sirve" "200" "$CODE"; contains "mensaje genérico" "Correo o contraseña incorrectos." "$TMP/body"
login "$JN2" "$UC1" "$PN"
check "la contraseña nueva sí" "302 /panel.php" "$CODE $LOC"
request "$JQ2" GET /panel.php
check "la sesión que estaba abierta antes del cambio se cierra (revoked)" "302 /login.php?msg=revoked" "$CODE $LOC"
request "$JQ1" GET /panel.php
check "y la de la primera también" "302 /login.php?msg=revoked" "$CODE $LOC"
request "$J8" GET "/restablecer.php?token=$TOK1"
contains "el enlace ya usado no sirve" "no es válido o ya caducó" "$TMP/body"
post_form "$J8" /restablecer.php /recuperar.php "token=$TOK1" "clave=otra-clave-99" "clave2=otra-clave-99"
contains "ni usándolo con otra contraseña" "no es válido o ya caducó" "$TMP/body"
JN3="$(new_jar)"; login "$JN3" "$UC1" "otra-clave-99"
check "y la contraseña no cambió" "200" "$CODE"
request "$J8" GET "/restablecer.php?token=abc";  contains "un token con forma inválida no sirve" "no es válido o ya caducó" "$TMP/body"
request "$J8" GET "/restablecer.php?token=$(printf '0%.0s' $(seq 1 64))";  contains "uno falso con forma válida no sirve" "no es válido o ya caducó" "$TMP/body"
request "$J8" GET "/restablecer.php";  contains "sin token tampoco" "no es válido o ya caducó" "$TMP/body"
request "$J8" GET "/restablecer.php?token[]=x";  check "un token con forma de arreglo no rompe" "200" "$CODE"
request "$J8" GET "/restablecer.php?token=%27%20OR%201%3D1--";  contains "ni una inyección" "no es válido o ya caducó" "$TMP/body"

echo; echo "== Recuperar: un enlace nuevo anula el anterior, caducidad, cuentas inactivas y límite"
mail_clear
post_form "$J8" /recuperar.php /recuperar.php "correo=$UC1"; wait_mail "$UC1" 1 || true; TA="$(mail_token "$UC1")"
mail_clear
post_form "$J8" /recuperar.php /recuperar.php "correo=$UC1"; wait_mail "$UC1" 1 || true; TB="$(mail_token "$UC1")"
check "el segundo enlace es distinto" "true" "$([ "$TA" != "$TB" ] && echo true || echo false)"
request "$J8" GET "/restablecer.php?token=$TA";  contains "pedir otro enlace anula el anterior" "no es válido o ya caducó" "$TMP/body"
request "$J8" GET "/restablecer.php?token=$TB";  contains "y el nuevo sirve" "Contraseña nueva" "$TMP/body"
sql "UPDATE restablecimientos r JOIN usuarios u ON u.id_usuario=r.id_usuario SET r.expira_en = NOW() - INTERVAL 1 MINUTE WHERE u.correo='$UC1'" >/dev/null
request "$J8" GET "/restablecer.php?token=$TB";  contains "un enlace caducado no sirve" "no es válido o ya caducó" "$TMP/body"
post_form "$J8" /restablecer.php /recuperar.php "token=$TB" "clave=$PN-x" "clave2=$PN-x"
contains "ni para cambiar la contraseña" "no es válido o ya caducó" "$TMP/body"
mail_clear; sql "UPDATE usuarios SET activo=0 WHERE correo='$UC1'" >/dev/null
post_form "$J8" /recuperar.php /recuperar.php "correo=$UC1"
check "una cuenta desactivada recibe la misma respuesta" "200" "$CODE"; contains "con el mismo aviso" "Si ese correo está registrado" "$TMP/body"
sleep 1.5
check "pero no recibe ningún correo" "0" "$(mail_count "$UC1")"
sql "UPDATE usuarios SET activo=1 WHERE correo='$UC1'" >/dev/null

UC2="smoke_${RUN}_recB@correo.com"; JQ3="$(new_jar)"; register "$JQ3" "$UC2" "$PV"
sql "DELETE FROM intentos_acceso" >/dev/null; mail_clear
for i in 1 2 3 4 5 6 7; do post_form "$J8" /recuperar.php /recuperar.php "correo=$UC2"; done
check "siete peticiones seguidas siguen respondiendo 200 igual (no se revela el límite)" "200" "$CODE"
wait_mail "$UC2" 5 || true; sleep 1.5
check "pero solo se enviaron 5 correos (RNF-24): a partir de ahí no se envía nada" "5" "$(mail_count "$UC2")"
sql "DELETE FROM intentos_acceso" >/dev/null

echo; echo "== Perfil (RF-03)"
JPF="$(new_jar)"; UPF="smoke_${RUN}_perf@correo.com"; PPF='clave-perfil-12'; register "$JPF" "$UPF" "$PPF"
UPFID="$(uid "$UPF")"
request "$J7" GET /perfil.php;  check "sin sesión, el perfil exige login" "302 /login.php" "$CODE $LOC"
request "$JPF" GET /perfil.php
check "el perfil responde 200" "200" "$CODE"
contains "muestra el correo" "$UPF" "$TMP/body"
contains "con la moneda actual seleccionada" '<option value="COP" selected>' "$TMP/body"
post_form "$JPF" /cuenta-form.php /cuenta-form.php "nombre=Efectivo" "tipo=efectivo" "saldo_inicial=1.500.000"
request "$JPF" GET /cuentas.php;  contains "por defecto los montos usan «\$»" '$1.500.000' "$TMP/body"
post_form "$JPF" /perfil.php /perfil.php "accion=datos" "nombre=  Nueva   Persona " "moneda=EUR"
check "guardar los datos redirige al perfil" "302 /perfil.php" "$CODE $LOC"
check "se guardaron nombre (limpio) y moneda" "Nueva Persona|EUR" "$(sql "SELECT CONCAT(nombre,'|',moneda) FROM usuarios WHERE id_usuario=$UPFID")"
request "$JPF" GET /perfil.php;  contains "avisa" "Se guardaron tus datos" "$TMP/body"
contains "la barra lateral ya muestra el nombre nuevo" "Nueva Persona" "$TMP/body"
request "$JPF" GET /cuentas.php;  contains "los montos pasan al símbolo de la moneda (€)" '€1.500.000' "$TMP/body"
absent "y ya no usan «\$»" '$1.500.000' "$TMP/body"
request "$JPF" GET /panel.php;  contains "también en el panel" '€1.500.000' "$TMP/body"
post_form "$JPF" /perfil.php /perfil.php "accion=datos" "nombre=Nueva Persona" "moneda=USD"
request "$JPF" GET /cuentas.php;  contains "USD usa «US\$»" 'US$1.500.000' "$TMP/body"
post_form "$JPF" /perfil.php /perfil.php "accion=datos" "nombre=Nueva Persona" "moneda=XXX"
check "una moneda desconocida se rechaza" "200" "$CODE"; contains "con un aviso" "Elige una moneda" "$TMP/body"
check "y no cambió" "USD" "$(sql "SELECT moneda FROM usuarios WHERE id_usuario=$UPFID")"
post_form "$JPF" /perfil.php /perfil.php "accion=datos" "nombre=A" "moneda=COP"
contains "un nombre corto se rechaza" "Escribe tu nombre" "$TMP/body"
post_form "$JPF" /perfil.php /perfil.php "accion=datos" "nombre=<b>Negrita</b>" "moneda=COP"
request "$JPF" GET /perfil.php
contains "un nombre con HTML se escapa" "&lt;b&gt;Negrita&lt;/b&gt;" "$TMP/body"
absent "y no aparece como etiqueta" "<b>Negrita" "$TMP/body"
post_form "$JPF" /perfil.php /perfil.php "accion=datos" "nombre=Persona Perfil" "moneda=COP"
request "$JPF" POST /perfil.php --data-urlencode "accion=datos" --data-urlencode "nombre=X" --data-urlencode "moneda=COP"
check "sin token CSRF se rechaza" "403" "$CODE"
post_form "$JPF" /perfil.php /perfil.php "accion=otra"
check "una acción desconocida es 400" "400" "$CODE"
request "$JA" GET /perfil.php;  check "el administrador también tiene perfil" "200" "$CODE"
contains "con su propio menú" "Administración" "$TMP/body"
contains "y no puede eliminar su cuenta (lo explica)" "no puedes eliminar tu propia cuenta" "$TMP/body"
request "$JPF" GET /perfil.php;  contains "el menú del usuario tiene «Mi perfil»" ">Mi perfil<" "$TMP/body" 2>/dev/null || contains "el menú del usuario tiene «Mi perfil»" "Mi perfil" "$TMP/body"

echo; echo "== Perfil: cambiar la contraseña"
JPF2="$(new_jar)"; login "$JPF2" "$UPF" "$PPF"
check "hay una segunda sesión abierta del mismo usuario" "302 /panel.php" "$CODE $LOC"
cp_() { post_form "$JPF" /perfil.php /perfil.php "accion=clave" "clave_actual=$1" "clave_nueva=$2" "clave_nueva2=$3"; }
cp_ "equivocada-1" "nueva-clave-56" "nueva-clave-56"
check "con la contraseña actual equivocada se rechaza" "200" "$CODE"; contains "con un aviso" "La contraseña actual no es correcta" "$TMP/body"
cp_ "$PPF" "corta" "corta";  contains "una nueva corta se rechaza" "al menos 8 caracteres" "$TMP/body"
cp_ "$PPF" "nueva-clave-56" "distinta-clave-7";  contains "que no coincide se rechaza" "no coinciden" "$TMP/body"
cp_ "$PPF" "$PPF" "$PPF";  contains "igual a la actual se rechaza" "distinta de la actual" "$TMP/body"
JPF3="$(new_jar)"; login "$JPF3" "$UPF" "$PPF"
check "nada de lo rechazado cambió la contraseña" "302 /panel.php" "$CODE $LOC"
cp_ "$PPF" "nueva-clave-56" "nueva-clave-56"
check "cambiarla redirige al perfil" "302 /perfil.php" "$CODE $LOC"
request "$JPF" GET /perfil.php;  contains "avisa" "Tu contraseña se cambió" "$TMP/body"
request "$JPF" GET /panel.php;  check "esta sesión SIGUE abierta" "200" "$CODE"
request "$JPF2" GET /panel.php;  check "las demás sesiones se cierran" "302 /login.php?msg=revoked" "$CODE $LOC"
request "$JPF3" GET /panel.php;  check "todas, no solo una" "302 /login.php?msg=revoked" "$CODE $LOC"
JPF4="$(new_jar)"; login "$JPF4" "$UPF" "$PPF"
check "la contraseña vieja ya no sirve" "200" "$CODE"
login "$JPF4" "$UPF" "nueva-clave-56"
check "la nueva sí" "302 /panel.php" "$CODE $LOC"
sql "DELETE FROM intentos_acceso" >/dev/null
for i in 1 2 3 4 5; do cp_ "mala-$i" "x-clave-123" "x-clave-123"; done
cp_ "mala-6" "x-clave-123" "x-clave-123"
check "tras 5 contraseñas actuales equivocadas se bloquea (429, RNF-24)" "429" "$CODE"
cp_ "nueva-clave-56" "otra-nueva-78" "otra-nueva-78"
check "y mientras dure, ni la correcta pasa" "429" "$CODE"
sql "DELETE FROM intentos_acceso" >/dev/null

echo; echo "== Perfil: eliminar la cuenta y todos los datos (RNF-20, D5)"
sql "DELETE FROM intentos_acceso" >/dev/null
JEL="$(new_jar)"; UEL="smoke_${RUN}_elim@correo.com"; PEL='clave-elim-12'; register "$JEL" "$UEL" "$PEL"
JEO="$(new_jar)"; UEO="smoke_${RUN}_elimB@correo.com"; register "$JEO" "$UEO" "$PEL"
UELID="$(uid "$UEL")"; UEOID="$(uid "$UEO")"
post_form "$JEL" /cuenta-form.php /cuenta-form.php "nombre=Efectivo" "tipo=efectivo" "saldo_inicial=1.000.000"
post_form "$JEL" /cuenta-form.php /cuenta-form.php "nombre=Banco" "tipo=bancaria" "saldo_inicial=500.000"
post_form "$JEO" /cuenta-form.php /cuenta-form.php "nombre=Efectivo de B" "tipo=efectivo" "saldo_inicial=700.000"
CEL1="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UELID AND nombre='Efectivo'")"; CEL2="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UELID AND nombre='Banco'")"; CEO="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UEOID")"
post_form "$JEL" /categorias.php /categorias.php "accion=crear" "nombre=Propia de elim" "tipo=gasto" "color=#123456"
post_form "$JEO" /categorias.php /categorias.php "accion=crear" "nombre=Propia de B" "tipo=gasto" "color=#654321"
KEL="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario=$UELID")"; KEO="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario=$UEOID")"
KDEF="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND nombre='Transporte'")"
sql "INSERT INTO transacciones (id_usuario,id_cuenta,id_categoria,tipo,monto,fecha,descripcion) VALUES ($UELID,$CEL1,$KEL,'gasto',10,CURDATE(),'a'),($UELID,$CEL1,$KDEF,'gasto',20,CURDATE(),'b'),($UELID,$CEL2,$KDEF,'gasto',30,CURDATE(),'c'),($UEOID,$CEO,$KEO,'gasto',40,CURDATE(),'otro')" >/dev/null
sql "INSERT INTO presupuestos (id_usuario,id_categoria,mes,anio,monto_limite) VALUES ($UELID,$KEL,11,2026,100),($UELID,$KDEF,11,2026,200),($UEOID,$KDEF,11,2026,300)" >/dev/null
post_form "$JEL" /meta-form.php /meta-form.php "nombre=Meta de elim" "monto_objetivo=5.000"
post_form "$JEO" /meta-form.php /meta-form.php "nombre=Meta de B" "monto_objetivo=5.000"
MEL="$(sql "SELECT id_meta FROM metas_ahorro WHERE id_usuario=$UELID")"; MEO="$(sql "SELECT id_meta FROM metas_ahorro WHERE id_usuario=$UEOID")"
post_form "$JEL" /meta-aporte.php "/meta-aporte.php?id=$MEL" "id=$MEL" "id_cuenta=$CEL1" "monto=100" "fecha=$(date +%F)"
post_form "$JEL" /meta-aporte.php "/meta-aporte.php?id=$MEL" "id=$MEL" "id_cuenta=$CEL2" "monto=50" "fecha=$(date +%F)"
post_form "$JEO" /meta-aporte.php "/meta-aporte.php?id=$MEO" "id=$MEO" "id_cuenta=$CEO" "monto=60" "fecha=$(date +%F)"
filas_de() { sql "SELECT (SELECT COUNT(*) FROM usuarios WHERE id_usuario=$1) + (SELECT COUNT(*) FROM cuentas WHERE id_usuario=$1) + (SELECT COUNT(*) FROM categorias WHERE id_usuario=$1) + (SELECT COUNT(*) FROM transacciones WHERE id_usuario=$1) + (SELECT COUNT(*) FROM presupuestos WHERE id_usuario=$1) + (SELECT COUNT(*) FROM metas_ahorro WHERE id_usuario=$1) + (SELECT COUNT(*) FROM aportes_meta WHERE id_meta IN (SELECT id_meta FROM metas_ahorro WHERE id_usuario=$1)) + (SELECT COUNT(*) FROM restablecimientos WHERE id_usuario=$1)"; }
ANTES_ELIM="$(filas_de "$UELID")"; ANTES_OTRO="$(filas_de "$UEOID")"
check "antes: el usuario tiene datos en todas las tablas (usuario, 2 cuentas, 1 categoría, 3 movimientos, 2 presupuestos, 1 meta, 2 aportes)" "12" "$ANTES_ELIM"
request "$JEL" GET /perfil.php;  contains "la página explica qué se elimina" "para siempre" "$TMP/body"
post_form "$JEL" /perfil.php /perfil.php "accion=eliminar" "eliminar_clave=equivocada-1"
check "con la contraseña equivocada no se elimina" "200" "$CODE"; contains "y lo dice" "La contraseña actual no es correcta" "$TMP/body"
check "los datos siguen intactos" "$ANTES_ELIM" "$(filas_de "$UELID")"
request "$JEL" POST /perfil.php --data-urlencode "accion=eliminar" --data-urlencode "eliminar_clave=$PEL"
check "sin token CSRF no se elimina" "403" "$CODE"
ORIGIN_SENT="http://evil.example" post_form "$JEL" /perfil.php /perfil.php "accion=eliminar" "eliminar_clave=$PEL"
check "desde otro origen tampoco" "403" "$CODE"
check "y los datos siguen intactos" "$ANTES_ELIM" "$(filas_de "$UELID")"
post_form "$JEL" /perfil.php /perfil.php "accion=eliminar" "eliminar_clave=$PEL"
check "con la contraseña correcta se elimina y redirige a login" "302 /login.php?msg=deleted" "$CODE $LOC"
check "NO queda ninguna fila suya en ninguna tabla" "0" "$(filas_de "$UELID")"
check "ni sus cuentas, movimientos ni aportes (comprobado por id de cuenta)" "0" "$(sql "SELECT (SELECT COUNT(*) FROM transacciones WHERE id_cuenta IN ($CEL1,$CEL2)) + (SELECT COUNT(*) FROM aportes_meta WHERE id_cuenta IN ($CEL1,$CEL2)) + (SELECT COUNT(*) FROM cuentas WHERE id_cuenta IN ($CEL1,$CEL2))")"
check "los datos de otro usuario quedan intactos" "$ANTES_OTRO" "$(filas_de "$UEOID")"
check "y el saldo de su cuenta sigue bien (700.000 − 40 − 60)" "699900.00" "$(sql "SELECT c.saldo_inicial - COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta),0) - COALESCE((SELECT SUM(monto) FROM aportes_meta WHERE id_cuenta=c.id_cuenta),0) FROM cuentas c WHERE c.id_cuenta=$CEO")"
check "los intentos de acceso no guardan el hash de su correo" "0" "$(sql "SELECT COUNT(*) FROM intentos_acceso WHERE clave='correo:$(printf '%s' "$UEL" | sha256)'")"
request "$JEL" GET /panel.php;  check "la sesión ya no vale" "302 /login.php" "$CODE $LOC"
login "$JEL" "$UEL" "$PEL"
check "y no se puede volver a entrar con esa cuenta" "200" "$CODE"; contains "mensaje genérico" "Correo o contraseña incorrectos." "$TMP/body"
request "$J7" GET "/login.php?msg=deleted";  contains "la pantalla de login confirma la eliminación" "se eliminaron" "$TMP/body"
ADMIN_PASS_CHECK="$ADMIN_PASS"
post_form "$JA" /perfil.php /perfil.php "accion=eliminar" "eliminar_clave=$ADMIN_PASS_CHECK"
check "un administrador no puede eliminar su cuenta" "200" "$CODE"; contains "y se le explica" "otro administrador que te quite el rol" "$TMP/body"
check "su cuenta sigue ahí" "1" "$(sql "SELECT COUNT(*) FROM usuarios WHERE correo='$ADMIN_EMAIL'")"

echo; echo "== Salud y páginas ya sin maqueta (RNF-14)"
request "$J7" GET /salud.php
check "salud.php responde 200 sin sesión" "200" "$CODE"
check "con un JSON mínimo" '{"estado":"ok"}' "$(cat "$TMP/body")"
check "sin caché" "Cache-Control: no-store" "$(grep -i '^cache-control' "$TMP/hdr" | tr -d '\r')"
absent "y sin revelar versiones" "PHP" "$TMP/body"
check "la página de recuperar es la real (pide el correo y envía)" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/recuperar.php")"

echo; echo "== Limitación de intentos (RNF-24)"
J6="$(new_jar)"; U6="smoke_${RUN}_d@correo.com"
for i in 1 2 3 4 5; do login "$J6" "$U6" "mala-clave-$i"; done
check "5 fallos seguidos siguen respondiendo 200" "200" "$CODE"
login "$J6" "$U6" "mala-clave-6"
check "el 6.º intento se bloquea con 429" "429" "$CODE"; contains "con un aviso claro" "Demasiados intentos" "$TMP/body"
check "y manda Retry-After" "Retry-After: 900" "$(grep -i '^retry-after' "$TMP/hdr" | tr -d '\r')"
sql "DELETE FROM intentos_acceso" >/dev/null

echo; echo "== Transferencias entre cuentas y pago de tarjetas (Fase 8)"
JTR="$(new_jar)"; UTR="smoke_${RUN}_tr@correo.com"; register "$JTR" "$UTR" "$P"
UTRID="$(uid "$UTR")"
request "$J7" GET /transferencias.php;      check "sin sesión, transferencias exige login" "302 /login.php" "$CODE $LOC"
request "$J7" GET /transferencia-form.php;  check "y el formulario también" "302 /login.php" "$CODE $LOC"
request "$JTR" GET /transferencias.php;     check "el listado de transferencias responde 200" "200" "$CODE"
contains "con menos de 2 cuentas invita a crear cuentas" "dos cuentas activas" "$TMP/body"

post_form "$JTR" /cuenta-form.php /cuenta-form.php "nombre=Banco" "tipo=bancaria" "saldo_inicial=1.000.000"
post_form "$JTR" /cuenta-form.php /cuenta-form.php "nombre=Visa" "tipo=tarjeta_credito" "saldo_inicial=-300.000"
CBANCO="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UTRID AND nombre='Banco'")"
CVISA="$(sql "SELECT id_cuenta FROM cuentas WHERE id_usuario=$UTRID AND nombre='Visa'")"

post_form "$JTR" /transferencia-form.php /transferencia-form.php "id_cuenta_origen=$CBANCO" "id_cuenta_destino=$CVISA" "monto=200.000" "fecha=$HOY_APP" "descripcion=Pago cuota Visa"
check "registrar transferencia redirige al listado" "302 /transferencias.php" "$CODE $LOC"
TRID="$(sql "SELECT id_transferencia FROM transferencias WHERE id_usuario=$UTRID")"
request "$JTR" GET /transferencias.php
contains "muestra la transferencia registrada" "Pago cuota Visa" "$TMP/body"
contains "con el monto formateado" '$200.000' "$TMP/body"
request "$JTR" GET /cuentas.php
contains "Banco bajó a 800.000" '$800.000' "$TMP/body"
contains "y la deuda de Visa bajó a -100.000" '−$100.000' "$TMP/body"

post_form "$JTR" /transferencia-form.php /transferencia-form.php "id_cuenta_origen=$CBANCO" "id_cuenta_destino=$CBANCO" "monto=10.000" "fecha=$HOY_APP"
contains "misma cuenta origen y destino se rechaza" "distinta de la cuenta de origen" "$TMP/body"
post_form "$JTR" /transferencia-form.php /transferencia-form.php "id_cuenta_origen=$CBANCO" "id_cuenta_destino=$CVISA" "monto=900.000" "fecha=$HOY_APP"
contains "transferir más del saldo disponible se rechaza" "no alcanza para realizar esta operación" "$TMP/body"

request "$JTR" GET "/transferencia-form.php?id=$TRID"
contains "editar carga el monto con puntos de miles" 'value="200.000"' "$TMP/body"
post_form "$JTR" /transferencia-form.php "/transferencia-form.php?id=$TRID" "id=$TRID" "id_cuenta_origen=$CBANCO" "id_cuenta_destino=$CVISA" "monto=300.000" "fecha=$HOY_APP" "descripcion=Pago total Visa"
check "guardar edición redirige al listado" "302 /transferencias.php" "$CODE $LOC"
check "Visa quedó en 0.00 y Banco en 700.000" "700000.00|0.00" "$(sql "SELECT CONCAT((SELECT c.saldo_inicial - COALESCE((SELECT SUM(monto) FROM transferencias WHERE id_cuenta_origen=c.id_cuenta),0) FROM cuentas c WHERE c.id_cuenta=$CBANCO),'|',(SELECT c.saldo_inicial + COALESCE((SELECT SUM(monto) FROM transferencias WHERE id_cuenta_destino=c.id_cuenta),0) FROM cuentas c WHERE c.id_cuenta=$CVISA))")"

post_form "$JTR" /transferencias.php /transferencias.php "id=$TRID" "accion=eliminar"
check "eliminar transferencia redirige al listado" "302 /transferencias.php" "$CODE $LOC"
check "la transferencia se borró de la BD" "0" "$(sql "SELECT COUNT(*) FROM transferencias WHERE id_transferencia=$TRID")"

echo; echo "== Movimientos recurrentes y gastos fijos (Fase 9)"
request "$J7" GET /recurrentes.php;      check "sin sesión, recurrentes exige login" "302 /login.php" "$CODE $LOC"
request "$J7" GET /recurrente-form.php;  check "y el formulario también" "302 /login.php" "$CODE $LOC"
request "$JTR" GET /recurrentes.php;     check "el listado de recurrentes responde 200" "200" "$CODE"
contains "sin programaciones invita a crear la primera" "Programar mi primer gasto fijo" "$TMP/body"

post_form "$JTR" /recurrente-form.php /recurrente-form.php "tipo=gasto" "monto=80.000" "frecuencia=mensual" "proxima_fecha=$HOY_APP" "id_cuenta=$CBANCO" "id_categoria=$KG" "auto_aplicar=0" "descripcion=Gimnasio mensual"
check "crear gasto fijo manual redirige al listado" "302 /recurrentes.php" "$CODE $LOC"
RECID="$(sql "SELECT id_recurrente FROM movimientos_recurrentes WHERE id_usuario=$UTRID")"
request "$JTR" GET /recurrentes.php
contains "muestra el gasto fijo programado" "Gimnasio mensual" "$TMP/body"
contains "avisa que está pendiente de confirmar hoy" "Pendiente hoy" "$TMP/body"
request "$JTR" GET /panel.php
contains "el panel principal avisa del gasto fijo pendiente" "gasto o ingreso fijo pendiente" "$TMP/body"

post_form "$JTR" /recurrentes.php /recurrentes.php "id=$RECID" "accion=aplicar"
check "aplicar ahora redirige a recurrentes" "302 /recurrentes.php" "$CODE $LOC"
check "creó la transacción en el historial y descontó de Banco (1.000.000 - 80.000 = 920.000)" "1|920000.00" "$(sql "SELECT CONCAT((SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UTRID AND descripcion='Gimnasio mensual'),'|',(SELECT c.saldo_inicial - COALESCE((SELECT SUM(monto) FROM transacciones WHERE id_cuenta=c.id_cuenta),0) FROM cuentas c WHERE c.id_cuenta=$CBANCO))")"

post_form "$JTR" /recurrentes.php /recurrentes.php "id=$RECID" "accion=pausar"
check "pausar deja activo=0" "0" "$(sql "SELECT activo FROM movimientos_recurrentes WHERE id_recurrente=$RECID")"
post_form "$JTR" /recurrentes.php /recurrentes.php "id=$RECID" "accion=activar"
check "activar deja activo=1" "1" "$(sql "SELECT activo FROM movimientos_recurrentes WHERE id_recurrente=$RECID")"
post_form "$JTR" /recurrentes.php /recurrentes.php "id=$RECID" "accion=eliminar"
check "eliminar borra la programación pero conserva la transacción ya aplicada" "0|1" "$(sql "SELECT CONCAT((SELECT COUNT(*) FROM movimientos_recurrentes WHERE id_recurrente=$RECID),'|',(SELECT COUNT(*) FROM transacciones WHERE id_usuario=$UTRID AND descripcion='Gimnasio mensual'))")"

echo; echo "== Espacios compartidos y Cuenta Familiar (Fase 10)"
request "$J7" GET /espacios.php;         check "sin sesión, espacios exige login" "302 /login.php" "$CODE $LOC"
request "$JTR" GET /espacios.php;        check "la página de espacios responde 200" "200" "$CODE"
contains "muestra Mi espacio personal activo" "Mi espacio personal" "$TMP/body"

JF2="$(new_jar)"; UF2="smoke_${RUN}_fam2@correo.com"; register "$JF2" "$UF2" "$P"
UF2ID="$(uid "$UF2")"

post_form "$JTR" /espacios.php /espacios.php "accion=crear" "nombre=Hogar Familia Smoke" "moneda=COP"
check "crear espacio familiar redirige a espacios" "302 /espacios.php" "$CODE $LOC"
ESPID="$(sql "SELECT id_espacio FROM espacios WHERE id_creador=$UTRID")"
request "$JTR" GET /espacios.php
contains "el espacio familiar recién creado queda activo en la barra lateral" "Hogar Familia Smoke" "$TMP/body"
request "$JTR" GET /cuentas.php
contains "en el espacio familiar recién creado aún no aparecen las cuentas personales" "Aún no tienes cuentas" "$TMP/body"

post_form "$JTR" /cuenta-form.php /cuenta-form.php "nombre=Cuenta Hogar" "tipo=bancaria" "saldo_inicial=2.000.000"
CHOGAR="$(sql "SELECT id_cuenta FROM cuentas WHERE id_espacio=$ESPID")"
check "la cuenta creada lleva el id_espacio del hogar" "$ESPID" "$(sql "SELECT id_espacio FROM cuentas WHERE id_cuenta=$CHOGAR")"

post_form "$JTR" /espacios.php /espacios.php "accion=invitar" "id_espacio=$ESPID" "correo=$UF2" "rol=editor"
check "invitar al segundo miembro redirige a espacios" "302 /espacios.php" "$CODE $LOC"
INVID="$(sql "SELECT id_invitacion FROM espacio_invitaciones WHERE id_espacio=$ESPID AND correo='$UF2'")"

request "$JF2" GET /espacios.php
contains "el segundo usuario ve la invitación pendiente" "Hogar Familia Smoke" "$TMP/body"
post_form "$JF2" /espacios.php /espacios.php "accion=aceptar_invitacion" "id_invitacion=$INVID"
check "aceptar la invitación redirige y activa el espacio familiar" "302 /espacios.php" "$CODE $LOC"
request "$JF2" GET /cuentas.php
contains "el segundo usuario ahora ve la Cuenta Hogar y su saldo de 2.000.000" "Cuenta Hogar" "$TMP/body"
contains "con los 2.000.000 del hogar" '$2.000.000' "$TMP/body"

post_form "$JF2" /movimiento-form.php /movimiento-form.php "tipo=gasto" "monto=150.000" "fecha=$HOY_APP" "id_cuenta=$CHOGAR" "id_categoria=$KG" "descripcion=Mercado familiar"
check "el segundo miembro registra un gasto en la cuenta familiar" "302 /movimientos.php" "$CODE $LOC"
request "$JTR" GET /movimientos.php
contains "el creador del espacio ve el gasto registrado por el otro miembro" "Mercado familiar" "$TMP/body"
contains "con la autoría del miembro" "Por Prueba Smoke" "$TMP/body"
request "$JTR" GET /cuentas.php
contains "el saldo de Cuenta Hogar bajó a 1.850.000 para todos" '$1.850.000' "$TMP/body"

post_form "$JTR" /espacios.php /espacios.php "accion=cambiar_rol" "id_espacio=$ESPID" "id_usuario=$UF2ID" "rol=lector"
check "cambiar rol a lector redirige a espacios" "302 /espacios.php" "$CODE $LOC"
request "$JF2" GET /movimiento-form.php
check "un miembro con rol lector recibe 403 al intentar crear movimientos" "403" "$CODE"
request "$JF2" GET /movimientos.php
check "pero sí puede consultar el listado de movimientos del hogar (200)" "200" "$CODE"

post_form "$JTR" /espacios.php /espacios.php "accion=cambiar" "id_espacio=0"
check "volver al espacio personal redirige al panel" "302 /panel.php" "$CODE $LOC"
request "$JTR" GET /cuentas.php
contains "en el espacio personal vuelven a verse las cuentas personales (Banco y Visa)" "Banco" "$TMP/body"
absent "y no se mezcla la Cuenta Hogar en el espacio personal" "Cuenta Hogar" "$TMP/body"

echo; echo "== Deudas, cálculo de intereses y estrategias Bola de Nieve / Avalancha (Fase 11)"
request "$J7" GET /deudas.php;           check "sin sesión, deudas exige login" "302 /login.php" "$CODE $LOC"
request "$J7" GET /deuda-form.php;       check "y el formulario también" "302 /login.php" "$CODE $LOC"
request "$JTR" GET /deudas.php;          check "el listado de deudas responde 200" "200" "$CODE"
contains "sin deudas invita a registrar la primera" "Registrar mi primera deuda" "$TMP/body"

post_form "$JTR" /deuda-form.php /deuda-form.php "nombre=Prestamo Libre" "acreedor=Banco Popular" "monto_inicial=500.000" "tasa_mensual=2" "cuota_minima=110.000" "dia_pago=15"
check "crear deuda redirige al listado" "302 /deudas.php" "$CODE $LOC"
DEUDAID="$(sql "SELECT id_deuda FROM deudas WHERE id_usuario=$UTRID")"
request "$JTR" GET "/deudas.php?extra=50.000"
contains "muestra la deuda y el simulador Bola de Nieve vs. Avalancha" "Método Bola de Nieve" "$TMP/body"
contains "muestra el método Avalancha" "Método Avalancha" "$TMP/body"
contains "calcula el interés mensual (2 % de 500.000 = 10.000)" '$10.000' "$TMP/body"

post_form "$JTR" /deuda-pago.php "/deuda-pago.php?id=$DEUDAID" "id=$DEUDAID" "id_cuenta=$CBANCO" "monto_total=110.000" "modo_pago=cuota" "fecha=$HOY_APP" "descripcion=Primera cuota"
check "registrar pago de cuota redirige al listado de deudas" "302 /deudas.php" "$CODE $LOC"
check "desglosó 10.000 a intereses y 100.000 a capital" "100000.00|10000.00" "$(sql "SELECT CONCAT(abono_capital,'|',pago_interes) FROM pagos_deuda WHERE id_deuda=$DEUDAID")"

echo; echo "== Clonación de presupuestos y asistente 50/30/20 (Fase 12)"
request "$J7" GET /presupuesto-asistente.php; check "sin sesión, asistente 50/30/20 exige login" "302 /login.php" "$CODE $LOC"
request "$JTR" GET "/presupuesto-asistente.php?mes=10&anio=2026&ingreso=4.000.000"
check "el asistente 50/30/20 responde 200" "200" "$CODE"
contains "calcula el 50 % de 4.000.000 (2.000.000)" '$2.000.000' "$TMP/body"
contains "calcula el 30 % de 4.000.000 (1.200.000)" '$1.200.000' "$TMP/body"
contains "calcula el 20 % de 4.000.000 (800.000)" '$800.000' "$TMP/body"

post_form "$JTR" /presupuesto-asistente.php "/presupuesto-asistente.php?mes=10&anio=2026" "mes=10" "anio=2026" "limites[$KG]=600.000"
check "aplicar presupuestos desde el asistente redirige al mes" "302 /presupuestos.php?mes=10&anio=2026" "$CODE $LOC"
check "se guardó el presupuesto de octubre" "600000.00" "$(sql "SELECT monto_limite FROM presupuestos WHERE id_usuario=$UTRID AND mes=10 AND anio=2026 AND id_categoria=$KG")"

post_form "$JTR" "/presupuestos.php?mes=11&anio=2026" "/presupuestos.php?mes=11&anio=2026" "accion=clonar_anterior"
check "clonar del mes anterior redirige a noviembre" "302 /presupuestos.php?mes=11&anio=2026" "$CODE $LOC"
check "copió el límite de 600.000 a noviembre" "600000.00" "$(sql "SELECT monto_limite FROM presupuestos WHERE id_usuario=$UTRID AND mes=11 AND anio=2026 AND id_categoria=$KG")"

echo; echo "== Conciliación rápida de saldos e importación CSV (Fase 13)"
request "$J7" GET "/cuenta-conciliar.php?id=$CBANCO"; check "sin sesión, conciliar cuenta exige login" "302 /login.php" "$CODE $LOC"
request "$J7" GET /movimientos-importar.php;          check "sin sesión, importar CSV exige login" "302 /login.php" "$CODE $LOC"
request "$JTR" GET "/cuenta-conciliar.php?id=$CBANCO"
check "la pantalla de conciliación responde 200" "200" "$CODE"
contains "muestra el nombre de la cuenta a conciliar" "Conciliar saldo de «Banco»" "$TMP/body"

post_form "$JTR" /cuenta-conciliar.php "/cuenta-conciliar.php?id=$CBANCO" "id=$CBANCO" "saldo_real=850.000" "id_categoria_gasto=$KG" "id_categoria_ingreso=$KI" "fecha=$HOY_APP" "descripcion=Ajuste conciliacion"
check "conciliar saldo redirige a cuentas" "302 /cuentas.php" "$CODE $LOC"
request "$JTR" GET /cuentas.php
contains "el saldo de Banco quedó exactamente en 850.000" '$850.000' "$TMP/body"

request "$JTR" GET /movimientos-importar.php
check "la pantalla de importar CSV responde 200" "200" "$CODE"
post_form "$JTR" /movimientos-importar.php /movimientos-importar.php "id_cuenta=$CBANCO" "id_categoria_gasto=$KG" "id_categoria_ingreso=$KI" "csv_texto=$HOY_APP;Reembolso CSV;+50000"
check "importar CSV redirige a movimientos" "302 /movimientos.php" "$CODE $LOC"
request "$JTR" GET /movimientos.php
contains "muestra el movimiento importado desde CSV" "Reembolso CSV" "$TMP/body"

echo; echo "== División de movimientos (Splits) y etiquetas transversales #tags (Fase 14)"
KT2="$(sql "SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND nombre='Transporte'")"
post_form "$JTR" /movimiento-form.php /movimiento-form.php \
  "tipo=gasto" "monto=120.000" "fecha=$HOY_APP" "id_cuenta=$CBANCO" \
  "descripcion=Supermercado y taxi" "etiqueta=#viaje-fin-anio" \
  "split_categoria[0]=$KG" "split_monto[0]=80.000" "split_descripcion[0]=Mercado viaje" \
  "split_categoria[1]=$KT2" "split_monto[1]=40.000" "split_descripcion[1]=Taxi aeropuerto"
check "registrar un movimiento dividido con etiqueta redirige a movimientos" "302 /movimientos.php" "$CODE $LOC"
request "$JTR" GET /movimientos.php
contains "avisa que se registró el gasto dividido en 2 categorías" "el gasto dividido (2 categorías) por \$120.000" "$TMP/body"
contains "muestra la etiqueta normalizada #viaje-fin-anio" "#viaje-fin-anio" "$TMP/body"
contains "muestra el indicador Dividido" "Dividido" "$TMP/body"
request "$JTR" GET "/movimientos.php?etiqueta=viaje-fin-anio"
contains "filtrar por etiqueta muestra los 2 movimientos del split" "2 movimientos" "$TMP/body"
request "$JTR" GET /reportes.php
contains "los reportes muestran la tarjeta de gastos por etiqueta" "Gastos por etiqueta" "$TMP/body"
contains "con la etiqueta #viaje-fin-anio y su total de 120.000" "#viaje-fin-anio" "$TMP/body"

echo; echo "== Gestión avanzada de tarjetas de crédito: cuotas, corte y pago (Fase 15)"
post_form "$JTR" /cuenta-form.php "/cuenta-form.php?id=$CVISA" "id=$CVISA" "nombre=Visa" "tipo=tarjeta_credito" "saldo_inicial=-200.000" "dia_corte=15" "dia_pago_limite=28"
check "guardar día de corte y límite de pago en tarjeta Visa redirige a cuentas" "302 /cuentas.php" "$CODE $LOC"
check "la BD guardó dia_corte=15 y dia_pago_limite=28" "15|28" "$(sql "SELECT CONCAT(dia_corte,'|',dia_pago_limite) FROM cuentas WHERE id_cuenta=$CVISA")"

post_form "$JTR" /movimiento-form.php /movimiento-form.php \
  "tipo=gasto" "monto=300.000" "fecha=$HOY_APP" "id_cuenta=$CVISA" \
  "id_categoria=$KG" "descripcion=Nevera a 6 cuotas" "num_cuotas=6"
check "registrar compra a 6 cuotas con tarjeta redirige a movimientos" "302 /movimientos.php" "$CODE $LOC"
check "generó las 6 filas en cuotas_tarjeta por 50.000 cada una" "6|300000.00" "$(sql "SELECT CONCAT(COUNT(*),'|',SUM(monto_total)) FROM cuotas_tarjeta WHERE id_cuenta=$CVISA")"

request "$JTR" GET /movimientos.php
contains "el listado de movimientos muestra el número de cuotas y el valor mensual" '6 cuotas ($50.000/mes)' "$TMP/body"

request "$JTR" GET /cuentas.php
contains "la tarjeta en Cuentas muestra Cuota próximo corte" "Cuota próximo corte" "$TMP/body"
contains "muestra el monto diferido a meses futuros (\$250.000)" '$250.000' "$TMP/body"
contains "muestra el día de corte configurado" "Día 15" "$TMP/body"
contains "muestra la fecha límite de pago configurada" "Día 28" "$TMP/body"

echo; echo "Resultado: $passed correctas, $failed fallidas"
[ "$failed" -eq 0 ]
