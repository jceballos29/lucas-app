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
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Base y usuario de la **aplicación** (solo `SELECT/INSERT/UPDATE/DELETE`). |
| `DB_MIGRATION_USERNAME`, `DB_MIGRATION_PASSWORD` | Usuario de **migraciones, scripts y copias** (la aplicación web no lo conoce). |
| `DB_ROOT_PASSWORD` | `root` de MySQL: solo para administrar el servidor. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USER`, `MAIL_PASSWORD`, `MAIL_FROM`, `MAIL_FROM_NAME` | El SMTP real. `MAIL_ENCRYPTION`: `starttls` (puerto 587), `tls` (465) o `none` (solo para Mailpit). |
| `PHP_FPM_MAX_CHILDREN` (y `START_SERVERS`, `MIN_SPARE`, `MAX_SPARE`) | Tamaño del pool de PHP. Ver «Capacidad». |
| `CERTS_DIR` | Carpeta con `fullchain.pem` y `privkey.pem` (por defecto `./certs`). |

No subas `.env` a git (ya está en `.gitignore`).

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
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml ps          # todos «healthy» / «running»
curl -fsS https://$APP_DOMAIN/salud.php                # {"estado":"ok"}
```

### El administrador

Con el volumen de MySQL vacío, `database/02-seed.sql` crea las categorías predeterminadas **y un administrador de desarrollo
(`admin@lucas.local`, clave pública)**. En producción:

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

## 6. Copias de seguridad (RNF-15)

`bin/backup-db` genera un volcado comprimido y consistente (no bloquea la aplicación), comprueba que se pueda leer, le pone permisos `600`
y conserva las últimas 8. Programa una por semana:

```cron
0 3 * * 0  cd /ruta/a/lucas-app && COMPOSE_FILE=docker-compose.prod.yml bin/backup-db /var/backups/lucas >> /var/log/lucas-backup.log 2>&1
```

- **Copia fuera del servidor.** Una copia en el mismo disco no protege de perder el servidor: sincroniza `/var/backups/lucas` con otro
  sitio (`rsync`, `rclone` a un bucket...). La copia contiene datos personales y hashes de contraseñas: cífrala o protégela como protegerías la base.
- **Prueba que se puede restaurar** (una copia que nunca se ha restaurado no es una copia): `bin/restore-db backups/<archivo>.sql.gz`
  la carga en una base temporal `lucas_test_restore` y se niega a pisar datos existentes. `tests/backup.sh` automatiza esa comprobación
  (copia, restaura y compara el número de filas de cada tabla).
- **Recuperación tras un desastre:** los pasos están en la cabecera de `bin/restore-db`.

## 7. Monitorización (RNF-14)

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
