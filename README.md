# 💲 Lucas

**Sistema web de gestión de finanzas personales.** Cada persona registra sus ingresos y gastos, los organiza por cuentas y categorías,
fija presupuestos mensuales y crea metas de ahorro. Un **dashboard de administración** permite gestionar a los usuarios de la aplicación.

PHP 8.4 puro (MVC, sin frameworks) · MySQL 8.4 · Docker Compose · HTML/CSS/JS propios, sin CDN ni servicios externos.

| | |
|---|---|
| **Estudiante** | Juan Antonio Ceballos Usuga |
| **Programa** | Tecnología en Desarrollo de Software y Aplicativos Móviles · Politécnico Internacional I.E.S. |
| **Documentación base** | [`../lucas-docs/DOCUMENTACION.md`](../lucas-docs/DOCUMENTACION.md) · maqueta en [`../lucas-frontend/`](../lucas-frontend/) |

> **Estado:** esqueleto e **interfaz** migrados (pantallas con datos de ejemplo, sin funcionalidad). Siguiente: Fase 1 (registro, login y roles reales). Ver [`docs/PLAN.md`](docs/PLAN.md).

### Pantallas disponibles hoy (maqueta)
| Usuario | Administrador |
|---|---|
| [login](http://localhost:8090/login.php) · [registro](http://localhost:8090/registro.php) · [recuperar](http://localhost:8090/recuperar.php) | [dashboard](http://localhost:8090/admin/index.php) · [usuarios](http://localhost:8090/admin/usuarios.php) |
| [panel](http://localhost:8090/panel.php) · [movimientos](http://localhost:8090/movimientos.php) · [nuevo movimiento](http://localhost:8090/movimiento-form.php) · [cuentas](http://localhost:8090/cuentas.php) · [presupuestos](http://localhost:8090/presupuestos.php) · [metas](http://localhost:8090/metas.php) · [reportes](http://localhost:8090/reportes.php) | |

En la maqueta, el login lleva al dashboard si el correo contiene «admin» y al panel en cualquier otro caso (no valida nada).

## Contenido
- [Funcionalidades](#funcionalidades)
- [Inicio rápido](#inicio-rápido)
- [Cuentas de desarrollo](#cuentas-de-desarrollo)
- [Configuración](#configuración)
- [Estructura](#estructura)
- [Comandos útiles](#comandos-útiles)
- [Pruebas](#pruebas)
- [Solución de problemas](#solución-de-problemas)
- [Documentación](#documentación)

## Funcionalidades

**Para el usuario**
- **Registro** público e inicio/cierre de sesión; recuperación de contraseña por correo.
- **Cuentas** (efectivo, bancaria, tarjeta de crédito, ahorro) con saldo calculado en vivo y saldo total.
- **Categorías** predeterminadas y propias.
- **Movimientos** de ingreso y gasto con filtros por fecha, cuenta, categoría y tipo, paginados.
- **Presupuestos** mensuales por categoría con barra de progreso y avisos al 80 % y 100 %.
- **Metas de ahorro** con aportes y porcentaje de avance.
- **Panel y reportes** del mes, gráficos y exportación a CSV.

**Para el administrador**
- **Dashboard** con métricas globales: usuarios totales, activos y nuevos, cuentas y movimientos, registros por mes.
- **Gestión de usuarios:** buscar, activar/desactivar y cambiar el rol, con salvaguardas (no se puede quedar el sistema sin administradores).
- **Categorías predeterminadas** editables.
- Por privacidad, el administrador **no ve** los movimientos ni los saldos de nadie (RNF-21).

**Seguridad:** contraseñas con bcrypt, consultas preparadas, token CSRF, comprobación de origen, CSP estricta, sesión `HttpOnly`/`SameSite`
que caduca a los 30 min, rol verificado en el servidor, usuario de BD con mínimo privilegio. Detalle en [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md).

## Inicio rápido

**Requisitos:** Docker y Docker Compose v2.

```bash
cp .env.example .env              # ya trae claves de DESARROLLO
docker compose up -d --build
```

La primera vez MySQL crea la base `lucas`, las tablas, la primera seed (categorías y administrador) y los usuarios de BD, y el
contenedor PHP genera `vendor/`. Espera a que `docker compose ps` muestre `db` en `healthy`.

| Servicio | Dirección | Notas |
|---|---|---|
| Aplicación | http://localhost:8090 | Solo escucha en `127.0.0.1` |
| Diagnóstico | http://localhost:8090/status.php | Solo con `APP_ENV=local` |
| Adminer | http://localhost:8091 | Sistema MySQL, servidor `db`, base `lucas` |

Los puertos (8090/8091) difieren de `crud-usuarios-docker` (8080/8081) para poder tener ambos proyectos levantados.

## Cuentas de desarrollo

Claves **conocidas, solo para tu máquina**. Cámbialas antes de exponer la aplicación (RNF-23).

| Cuenta | Para qué | Usuario | Clave |
|---|---|---|---|
| Administrador de la seed | Entrar al dashboard de administración | `admin@lucas.local` | `Admin#2026dev` |
| Usuarios normales | Se crean desde la vista de **registro** (`registro.php`) | el correo que quieras | la que elijas (≥ 8) |
| MySQL de la aplicación | PHP, solo `SELECT/INSERT/UPDATE/DELETE` | `lucas_app` | `lucas_app_dev_2026` |
| MySQL de migraciones | Esquema, scripts y pruebas (`bin/as-migrator`) | `lucas_migrator` | `lucas_migrator_dev_2026` |
| MySQL root | Administrar el servidor | `root` | `lucas_root_dev_2026` |

> El administrador y el registro de usuarios llegan en la **Fase 1** (ver [`docs/PLAN.md`](docs/PLAN.md)); hoy solo funciona el esqueleto.

## Configuración

Todo sale de `.env` (nunca se sube a git; la plantilla es `.env.example`).

| Variable | Para qué | Por defecto |
|---|---|---|
| `APP_ENV` | `local`: errores en pantalla y `status.php`. Cualquier otro valor: modo seguro (errores solo al log). | `local` |
| `APP_PORT` · `ADMINER_PORT` | Puertos locales | `8090` · `8091` |
| `APP_ORIGIN` | Origen público (esquema+host+puerto) para validar los POST. Con HTTPS activa la cookie `Secure`. | `http://localhost:APP_PORT` |
| `DB_HOST` `DB_PORT` `DB_DATABASE` | Conexión | `db` `3306` `lucas` |
| `DB_USERNAME` `DB_PASSWORD` | Usuario de la aplicación (mínimo privilegio) | `lucas_app` |
| `DB_MIGRATION_USERNAME` `DB_MIGRATION_PASSWORD` | Usuario de migraciones (PHP no lo conoce) | `lucas_migrator` |
| `DB_ROOT_PASSWORD` | root de MySQL | — |
| `HOST_UID` `HOST_GID` | Dueño de los archivos (`id -u`, `id -g`) | `1000` |
| `XDEBUG_MODE` | `off`, `debug`, `develop`… (VS Code: «Escuchar Xdebug (Docker)», puerto 9003) | `off` |

## Estructura

```
lucas-app/
├── docker-compose.yml · .env.example · bin/as-migrator
├── docker/            nginx (CSP, cabeceras) y php (Dockerfile, ini, xdebug)
├── database/          01-schema.sql · 02-seed.sql · 03-users.sh · migrations/
├── docs/              REQUISITOS · ARQUITECTURA · BASE-DE-DATOS · PLAN
└── src/               app/ (clases) · views/ · public/ (lo único que ve nginx) · scripts/ · tests/
```
El mapa completo de carpetas, capas y rutas está en [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md).

## Comandos útiles

```bash
docker compose up -d --build            # levantar / reconstruir
docker compose logs -f php              # errores de PHP
docker compose down -v                  # parar y BORRAR la base (se vuelve a sembrar al levantar)
docker compose exec php composer dump-autoload   # tras añadir clases nuevas, si hace falta
bin/as-migrator php scripts/<script>.php         # scripts que cambian datos/esquema (usuario de migraciones)
docker compose exec db mysql -ulucas_migrator -p lucas   # consola SQL
```

## Pruebas

```bash
docker compose exec php php tests/unit.php      # lógica pura (hoy: 12 comprobaciones)
bin/as-migrator php tests/integration.php       # repositorios (Fase 1 en adelante)
tests/run-all.sh                                # todo, antes de cerrar una fase
```

## Solución de problemas

| Síntoma | Causa y arreglo |
|---|---|
| `Falta DB_MIGRATION_PASSWORD en .env` | No existe `.env`: `cp .env.example .env`. |
| `Origen no permitido` (403) al enviar un formulario | Abriste la app con otro host. `localhost` y `127.0.0.1` son orígenes distintos: usa el de `APP_ORIGIN`. |
| `status.php` da 404 | `APP_ENV` no es `local` (es intencional). |
| Cambié `01-schema.sql`/`02-seed.sql` y no se nota | MySQL solo los ejecuta con el volumen vacío: `docker compose down -v && docker compose up -d`. |
| Permisos raros en `src/` | Ajusta `HOST_UID`/`HOST_GID` en `.env` y reconstruye. |
| La fuente o un recurso externo no carga | La CSP solo permite recursos propios; aloja el archivo en `src/public/` (ver ARQUITECTURA → Front-end). |
| Puerto ocupado | Cambia `APP_PORT`/`ADMINER_PORT` en `.env`. |

## Documentación

| Documento | Contenido |
|---|---|
| [`../lucas-docs/DOCUMENTACION.md`](../lucas-docs/DOCUMENTACION.md) | Documento académico original: problemática, RF/RNF, mockups, modelo de datos |
| [`docs/REQUISITOS.md`](docs/REQUISITOS.md) | Requisitos nuevos (registro, administración) y estado de cada módulo |
| [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md) | Capas, rutas, autenticación y roles, seguridad, convenciones |
| [`docs/BASE-DE-DATOS.md`](docs/BASE-DE-DATOS.md) | Esquema (con `rol` y `activo`), seed, permisos, consultas clave |
| [`docs/PLAN.md`](docs/PLAN.md) | Fases de migración con criterios de «terminado» |
