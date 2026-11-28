# Despliegue a producción

Esta guía lleva Lucas desde el repositorio a un servidor con **HTTPS** (RNF-07), correo real, copias de seguridad semanales (RNF-15) y
monitorización (RNF-14). Se usa un archivo aparte, `docker-compose.prod.yml`, **solo** (no como complemento del de desarrollo).

> El `docker-compose.yml` normal es para desarrollo: expone Adminer y Mailpit, crea un administrador con clave pública y muestra errores.
> **No lo uses en un servidor.**

## 1. Requisitos

- Un servidor Linux con Docker y Docker Compose, y los puertos 80 y 443 libres.
- Un dominio (p. ej. `lucas.tudominio.com`) apuntando a la IP del servidor.
- Un servidor SMTP para enviar correos (el de tu proveedor de dominio, SES, Mailgun, Gmail con clave de aplicación...).

## 2. Configuración (`.env`)

Parte de `.env.example` y **cambia todas las claves** (las de desarrollo son públicas). Variables que producción exige o usa:

| Variable | Qué es |
|---|---|
| `APP_DOMAIN` | El dominio, sin `https://` (lo usa nginx). |
| `APP_ORIGIN` | El origen completo, **con `https://`** (p. ej. `https://lucas.tudominio.com`). Hace que la cookie de sesión sea `Secure` y que solo se acepten POST de ese origen. |
| `APP_KEY` | **Obligatoria.** Cifra los secretos de la verificación en dos pasos en la base. Genérala una vez: `openssl rand -base64 32`. Guárdala fuera del repositorio y con copia aparte de la base de datos; si la pierdes o la cambias, nadie con 2FA podrá iniciar sesión hasta que se le quite con `scripts/desactivar-2fa.php`. |
| `ADMIN_2FA_OBLIGATORIO` | Opcional. Vacío = los administradores **deben** activar el 2FA (salvo con `APP_ENV=local`); `0`/`1` lo fuerza. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Base y usuario de la **aplicación** (solo `SELECT/INSERT/UPDATE/DELETE`). |
| `DB_MIGRATION_USERNAME`, `DB_MIGRATION_PASSWORD` | Usuario de **migraciones, scripts y copias** (la aplicación web no lo conoce). |
| `DB_ROOT_PASSWORD` | `root` de MySQL: solo para administrar el servidor. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USER`, `MAIL_PASSWORD`, `MAIL_FROM`, `MAIL_FROM_NAME` | El SMTP real. `MAIL_ENCRYPTION`: `starttls` (puerto 587), `tls` (465) o `none` (solo para Mailpit). |
| `PHP_FPM_MAX_CHILDREN` (y `START_SERVERS`, `MIN_SPARE`, `MAX_SPARE`) | Tamaño del pool de PHP. Ver «Capacidad». |
| `CERTS_DIR` | Carpeta con `fullchain.pem` y `privkey.pem` (por defecto `./certs`). |

No subas `.env` a git (ya está en `.gitignore`).

**Antes del primer arranque (y cada vez que cambies el `.env`) ejecuta `bin/comprobar-produccion`** (usa `docker-compose.prod.yml` por defecto; respeta `COMPOSE_FILE` si defines otro). Revisa que ninguna clave sea de
desarrollo o débil (menos de 12 caracteres, de ejemplo, repetida entre roles), que `APP_KEY` tenga 32 caracteres o más, que `APP_ORIGIN` sea
`https://` y no `localhost`, que el SMTP no mande usuario y clave sin cifrar y que existan los certificados. No imprime ninguna clave y sale
con código 1 si algo está mal. Además, `salud.php` responde **503** (y registra `[seguridad]…`) si en producción la clave de la base de datos
de la aplicación o `APP_KEY` son las públicas de desarrollo o débiles. `MAIL_ENCRYPTION=none` con `MAIL_USER`/`MAIL_PASSWORD` se rechaza al
enviar el primer correo fuera de `APP_ENV=local`: usa `starttls` (587) o `tls` (465).

## 3. Certificados HTTPS

nginx espera `fullchain.pem` y `privkey.pem` en `CERTS_DIR`. Con **Let's Encrypt** y `certbot` (la validación usa `./certbot-webroot`):

```bash
# 1) Arranca solo nginx en HTTP para que Let's Encrypt pueda validar el dominio. Hasta que existan los certificados el bloque 443
#    no arranca: crea unos temporales autofirmados y reemplázalos en el paso 3.
mkdir -p certs certbot-webroot
openssl req -x509 -nodes -newkey rsa:2048 -days 1 -subj "/CN=$APP_DOMAIN" -keyout certs/privkey.pem -out certs/fullchain.pem
docker compose -f docker-compose.prod.yml up -d nginx

# 2) Pide el certificado real
docker run --rm -v "$PWD/certbot-webroot:/var/www/certbot" -v "$PWD/letsencrypt:/etc/letsencrypt" certbot/certbot \
    certonly --webroot -w /var/www/certbot -d "$APP_DOMAIN" --agree-tos -m tucorreo@ejemplo.com --non-interactive

# 3) Copia los certificados y recarga nginx
cp letsencrypt/live/$APP_DOMAIN/fullchain.pem letsencrypt/live/$APP_DOMAIN/privkey.pem certs/
docker compose -f docker-compose.prod.yml exec nginx nginx -s reload
```

Los certificados de Let's Encrypt caducan a los 90 días: programa la renovación (`certbot renew` con el mismo montaje, más el `cp` y
el `nginx -s reload` de arriba) cada semana en cron. Si prefieres otra forma (un balanceador del proveedor, Caddy delante...), nginx solo
necesita ver `X-Forwarded-*`; en ese caso configura `real_ip` para que la limitación de intentos use la IP real.

## 4. Primer arranque

```bash
bin/comprobar-produccion                                # revisa el .env (claves, HTTPS, SMTP, certificados)
mkdir -p src/storage/adjuntos                           # los comprobantes (único sitio donde escribe PHP); su dueño debe ser HOST_UID
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml ps          # todos «healthy» / «running»
curl -fsS https://$APP_DOMAIN/salud.php                # {"estado":"ok"}
```

### La imagen de producción (código de solo lectura)

`docker-compose.prod.yml` construye la imagen PHP con **`docker/php/Dockerfile.prod`**, no con la de desarrollo: el código y las dependencias van
**dentro de la imagen** (de `root`, sin permiso de escritura para el usuario de PHP), el contenedor corre con el sistema de archivos de solo
lectura (`read_only`, `/tmp` en memoria, sin capacidades extra) y lo único escribible es `src/storage/` (los comprobantes, en el host para que
`bin/backup-db` los empaquete). La imagen no lleva git, unzip, Composer ni Xdebug, y OPcache no revalida archivos. Consecuencias:

- **Cambiar código = reconstruir** (`docker compose -f docker-compose.prod.yml up -d --build`); editar `src/` en el servidor ya no cambia lo que sirve PHP (nginx sí lee `src/public/` del host para los archivos estáticos: despliega siempre con `git pull` + `--build`).
- Los scripts siguen funcionando (`exec php php scripts/...`); las migraciones van incluidas en la imagen (`/var/www/database/migrations`).
- `src/storage` debe existir y ser escribible por `HOST_UID` (`mkdir -p src/storage/adjuntos`); si PHP no puede guardar comprobantes, mira ahí primero.
- `tests/imagen-produccion.sh` construye la imagen y comprueba todo esto (también corre en `tests/run-all.sh` y en CI).

### El administrador

> **Verificación en dos pasos.** Tras crear tu administrador, inicia sesión y actívala en **Mi perfil → Verificación en dos pasos** (en producción es obligatoria para administradores: sin ella no se abre la administración). Guarda los 10 códigos de recuperación. Si pierdes teléfono y códigos: `COMPOSE_FILE=docker-compose.prod.yml bin/as-migrator php scripts/desactivar-2fa.php tu@correo.com`.

En producción **no existe ningún administrador al arrancar**: `database/02-seed.sql` solo crea las categorías predeterminadas, y el
administrador de desarrollo (`admin@lucas.local`, clave pública) lo crea `database/04-dev-admin.sh` únicamente si el servicio `db`
recibe `APP_ENV=local`, algo que solo define `docker-compose.yml` (desarrollo). Crea el tuyo:

```bash
COMPOSE_FILE=docker-compose.prod.yml bin/as-migrator php scripts/create-admin.php tu@correo.com "Tu nombre"
```

(pide la contraseña por la terminal, sin mostrarla).

**Si tu base viene de una versión anterior, o restauraste un volcado de desarrollo**, puede traer `admin@lucas.local` con la clave
pública. Lucas te avisa: con `APP_ENV` distinto de `local`, `salud.php` responde **503** (el contenedor `nginx` aparece
«unhealthy» y se registra `[seguridad] admin@lucas.local sigue activo…` en `docker compose logs php`) y el dashboard de
administración muestra un aviso rojo. Se resuelve entrando con tu administrador real y **desactivando** esa cuenta en
**Administración → Usuarios** (no se puede borrar desde la interfaz, pero desactivada no inicia sesión), o cambiando su clave.

### Qué cambia con `APP_ENV=production`

Los errores van solo al log (`docker compose logs php`), `status.php` responde 404 y no se carga la configuración de desarrollo
(`php.local.ini`) y la imagen se construye **sin Xdebug** (`INSTALL_XDEBUG=0`; además `XDEBUG_MODE=off` como segunda barrera), y `salud.php` falla si sigue activo el administrador de desarrollo con su clave pública.

## 5. Actualizar

```bash
git pull
docker compose -f docker-compose.prod.yml up -d --build
COMPOSE_FILE=docker-compose.prod.yml bin/as-migrator php scripts/migrate.php    # migraciones pendientes (idempotentes)
# si una migración añadió tablas, da permisos a la aplicación sobre ellas:
docker compose -f docker-compose.prod.yml exec db bash /docker-entrypoint-initdb.d/03-users.sh
```

Haz una copia (`bin/backup-db`) **antes** de actualizar.

**Al actualizar a la versión con verificación en dos pasos (migración 014):** define `APP_KEY` en `.env` **antes** de levantar (el compose de producción se niega a arrancar sin ella), ejecuta `03-users.sh` (tabla `codigos_recuperacion`) y pide a los administradores que activen el 2FA: hasta entonces la administración les redirige a su perfil.

**Al actualizar desde una versión sin verificación de correo (migración 013):** las cuentas existentes quedan con el correo **sin verificar**
(los administradores sí se marcan verificados). Verán un aviso en cada página y podrán pedir el enlace desde *Mi perfil*; hasta entonces no
reciben alertas por correo ni pueden enviar/aceptar invitaciones a espacios. Es lo más seguro, porque Lucas no puede saber cuáles de las
cuentas actuales registraron el correo de otra persona. Si confías en todas ellas, márcalas como verificadas:
`docker compose -f docker-compose.prod.yml exec db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" lucas -e "UPDATE usuarios SET correo_verificado_en = creado_en WHERE correo_verificado_en IS NULL"'`.
La migración añade la tabla `verificaciones_correo`: ejecuta `03-users.sh` para dar permisos a la aplicación (arriba). Los correos de
verificación salen por el SMTP de producción: pruébalo registrando una cuenta.

## 6. Copias de seguridad (RNF-15)

`bin/backup-db` genera un volcado comprimido y consistente (no bloquea la aplicación) **y un archivo con los comprobantes**
(`src/storage/adjuntos`), con el mismo nombre base: `lucas-FECHA.sql.gz` + `lucas-FECHA.adjuntos.tar.gz`. Comprueba que ambos se puedan leer,
les pone permisos `600` y conserva las últimas 8 parejas. Programa una por semana:

```cron
0 3 * * 0  cd /ruta/a/lucas-app && COMPOSE_FILE=docker-compose.prod.yml bin/backup-db /var/backups/lucas >> /var/log/lucas-backup.log 2>&1
```

- **Copia fuera del servidor.** Una copia en el mismo disco no protege de perder el servidor: sincroniza `/var/backups/lucas` con otro
  sitio (`rsync`, `rclone` a un bucket...). La copia contiene datos personales y hashes de contraseñas: cífrala o protégela como protegerías la base.
- **Prueba que se puede restaurar** (una copia que nunca se ha restaurado no es una copia): `bin/restore-db backups/<archivo>.sql.gz`
  la carga en una base temporal `lucas_test_restore` y se niega a pisar datos existentes. `tests/backup.sh` automatiza esa comprobación
  (copia, restaura y compara el número de filas de cada tabla).
- **Recuperación tras un desastre:** los pasos están en la cabecera de `bin/restore-db`.
- **Los comprobantes van en la copia, en su propio archivo.** Los adjuntos (facturas, recibos) viven en `src/storage/adjuntos/`, fuera de la
  base de datos; por eso `bin/backup-db` genera `lucas-FECHA.adjuntos.tar.gz` junto al volcado (si `ADJUNTOS_DIR` no es el de siempre, indícalo
  como variable). Contiene documentos financieros: protégelo igual que el volcado y **sincroniza los dos archivos** fuera del servidor.
  Para recuperarlos: `bin/restore-adjuntos backups/<archivo>.adjuntos.tar.gz` (extrae en `src/storage/adjuntos`; se niega si la carpeta ya
  tiene contenido y rechaza archivos con rutas fuera de `adjuntos/` o con `..`; acepta una carpeta destino para probarla). Una restauración de
  la base sin sus comprobantes deja movimientos con «comprobante» que ya no se puede abrir; `bin/restore-db` avisa de ello. Tras restaurar,
  `scripts/huerfanos-adjuntos.php` informa de archivos sin movimiento y de movimientos cuyo archivo falta.
- **Comprobantes huérfanos** (archivos sin ningún movimiento, p. ej. tras un borrado interrumpido). Al eliminar una cuenta, un movimiento o un
  espacio compartido Lucas borra sus archivos; para detectar restos: `docker compose -f docker-compose.prod.yml exec -T php php scripts/huerfanos-adjuntos.php`
  (solo informa; con `--borrar` los elimina; ignora lo más reciente de 24 h). También avisa de movimientos cuyo archivo falta.

## 6b. Detrás de un proxy, sesiones y mantenimiento

- **Proxy o CDN delante (B3).** Lucas limita los intentos de acceso por IP con la que ve nginx. Si hay un balanceador delante, todos los visitantes llegarían con la IP del proxy y 20 intentos globales bloquearían a todos: en `docker/nginx/production.conf.template` descomenta `set_real_ip_from` (**solo** la red del proxy, nunca un rango abierto: cualquiera falsificaría `X-Forwarded-For`), `real_ip_header` y `real_ip_recursive`. Sin proxy delante, déjalos comentados.
- **Sesiones (B4, B7).** Cada visita al login crea un archivo de sesión pequeño (lleva el token CSRF); el recolector de PHP los borra (1 de cada 20 inicios) y `/tmp` del contenedor es una `tmpfs` de 128 MB. Si ves errores al iniciar sesión, mira `docker compose -f docker-compose.prod.yml exec php df -h /tmp`. Con HTTPS la cookie se llama `__Host-lucas_session`: **al actualizar desde una versión anterior, las sesiones abiertas se cierran una vez.**
- **Mantenimiento y tareas programadas (B9, N13).** El servicio `correo` ejecuta una vez al día `scripts/mantenimiento.php` (enlaces vencidos, intentos viejos, sesiones caducadas, historial de la cola) y `scripts/procesar-recurrentes.php` (movimientos recurrentes automáticos cuya fecha llegó). Para lanzarlos a mano: `docker compose -f docker-compose.prod.yml exec -T php php scripts/mantenimiento.php` y `docker compose -f docker-compose.prod.yml exec -T php php scripts/procesar-recurrentes.php`. Si quieres que Lucas envíe además el resumen financiero mensual el día 1 de cada mes a quienes lo activaron, añade en cron: `0 7 1 * * cd /ruta/a/lucas-app && docker compose -f docker-compose.prod.yml exec -T php php scripts/enviar-resumen-mensual.php >> /var/log/lucas-resumen.log 2>&1`.
- **Registro de accesos (B2).** nginx no escribe el valor de `token=` en `access.log` (lo sustituye por `oculto`).

## 7. Monitorización (RNF-14)

**Correo.** Los correos de la aplicación salen por una cola: el servicio `correo` de `docker-compose.prod.yml` (arranca con el resto, se reinicia solo) los envía con reintentos. Comprueba que está vivo y la cola sana con
`docker compose -f docker-compose.prod.yml exec -T php php scripts/enviar-cola.php --estado` (pendientes, enviados, fallidos y antigüedad del más viejo; si hay «pendientes» de hace muchos minutos, revisa `docker compose logs correo` y el SMTP). Tras actualizar a esta versión: migración 015 y `03-users.sh` (tabla `cola_correo`).

`https://tu-dominio/salud.php` responde `200 {"estado":"ok"}` si la aplicación y la base de datos responden, y `503` si no (sin revelar
versiones ni errores). Apunta un monitor externo (UptimeRobot, Better Stack, un `curl` en cron...) a esa dirección y avisa por correo.
Los contenedores se reinician solos (`restart: unless-stopped`) y nginx tiene un `healthcheck`. El 98 % mensual de disponibilidad (RNF-14)
equivale a unas 14 horas de caída al mes: una única máquina lo cumple con reinicio automático y copias, pero **no** sobrevive a
una avería de hardware; para más, hace falta un segundo servidor, que queda fuera de esta versión.

## 8. Capacidad (RNF-08 y RNF-19)

Las cifras medidas están en [`docs/PLAN.md`](PLAN.md) (Fase 7). Lo que conviene saber para dimensionar:

- Las páginas ya autenticadas son baratas (milisegundos). Lo caro es **iniciar sesión**: `password_hash` usa bcrypt con coste 12 *a propósito*
  (~240 ms de CPU) para encarecer los ataques de fuerza bruta. La capacidad de inicios de sesión por segundo es ≈ núcleos ÷ 0,24 s.
- `PHP_FPM_MAX_CHILDREN` ≈ memoria disponible para PHP ÷ ~40 MB por proceso, sin pasar de `max_connections` de MySQL (151 por
  defecto; cada proceso usa una conexión mientras atiende una petición).
- Para medir tu servidor: `python3 tests/carga.py --setup --users 500` y `python3 tests/carga.py --users 500 --duration 60 --ramp 150`
  (solo en un entorno de pruebas: crea usuarios; `--cleanup` los borra).

## 9. CI/CD con GitHub Actions (GHCR y despliegue automático)

El flujo [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) automatiza cinco etapas en cada *push*, *pull request* o etiqueta `v*.*.*`:

1. **`calidad`**: valida `composer.json` (`--strict`), comprueba la sintaxis de todos los `.php` y scripts `.sh`, ejecuta `composer audit`, **PHPStan (nivel 7, línea base vacía)** y **PHP CS Fixer**.
2. **`pruebas`**: levanta el entorno con Docker Compose y ejecuta `tests/run-all.sh` (unitarias, integración, humo, copias e imagen de producción) más `tests/ataques.py`.
3. **`produccion`**: genera un `.env` de producción con claves fuertes y certificados TLS temporales, pasa `bin/comprobar-produccion`, levanta `docker-compose.prod.yml` de verdad y verifica HTTPS, redirección `80 → 443`, `HSTS`, `CSP`, cookie `__Host-lucas_session`, `/salud.php`, migraciones y copias de seguridad.
4. **`publicar` (CD)**: en cada *push* a `main` o etiqueta `v*.*.*`, construye la imagen PHP de producción (`docker/php/Dockerfile.prod`) con caché Buildx y la publica en **GitHub Container Registry**:
   - `ghcr.io/<tu-usuario>/lucas-app:latest`
   - `ghcr.io/<tu-usuario>/lucas-app:sha-<commit>`
   - `ghcr.io/<tu-usuario>/lucas-app:<versión>` (al crear un tag `v1.0.0`)
5. **`desplegar` (CD por SSH)**: si configuraste los *Secrets* de tu servidor en GitHub, se conecta por SSH, valida `.env`, hace una copia de seguridad preventiva (`bin/backup-db`), actualiza el commit, reconstruye/levanta los contenedores, aplica las migraciones (`scripts/migrate.php` + `03-users.sh`) y comprueba que `https://localhost/salud.php` responda `{"estado":"ok"}`. Si aún no has configurado los *Secrets*, el trabajo termina en verde sin fallar y muestra las instrucciones en el resumen.

### Cómo activar el despliegue automático por SSH a tu servidor (p. ej. Oracle Cloud / VPS)

 tras realizar el primer arranque manual en tu servidor (secciones 1 a 4), abre tu repositorio en GitHub → **Settings → Secrets and variables → Actions → New repository secret** y añade:

| Secret | Obligatorio | Qué poner |
|---|---|---|
| `DEPLOY_HOST` | Sí | IP pública o dominio de tu servidor (p. ej. `lucas.tudominio.com`). |
| `DEPLOY_USER` | Sí | Usuario SSH del servidor (p. ej. `ubuntu` o `opc`). |
| `DEPLOY_SSH_KEY` | Sí | Llave privada SSH (`~/.ssh/id_ed25519`) cuya llave pública esté en `~/.ssh/authorized_keys` del servidor. |
| `DEPLOY_PORT` | Opcional | Puerto SSH si no es el `22`. |
| `DEPLOY_PATH` | Opcional | Carpeta del repositorio en el servidor (por defecto `~/lucas-app`). |
| `DEPLOY_KNOWN_HOSTS` | Opcional | Salida de `ssh-keyscan -H <tu-servidor>` para fijar la huella del host SSH. |

También puedes lanzar el pipeline manualmente desde la pestaña **Actions → CI/CD → Run workflow** (con la opción de omitir el despliegue SSH si solo quieres validar y publicar la imagen).
