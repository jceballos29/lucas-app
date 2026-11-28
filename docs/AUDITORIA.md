# Auditoría de Lucas: seguridad, calidad de código y UX/UI

**Fecha:** 2026-11-26 · **Alcance:** todo el repositorio hasta el commit `30764d4` (Fase 20) · **Entorno probado:** stack Docker de desarrollo (PHP 8.4.28, MySQL 8.4, nginx 1.30) en `http://localhost:8090`.

## 1. Veredicto

Lucas está **bien construida y la base de seguridad es sólida** para una aplicación hecha sin frameworks. No encontré inyección SQL, XSS, IDOR (acceso a datos de otro usuario), escalada de privilegios ni condiciones de carrera que rompan los saldos. Los problemas reales están en **la frontera con producción** (cuenta admin sembrada, correo sin verificar, Xdebug en la imagen) y en **abuso de funciones legítimas** (invitaciones sin límite, bloqueo de cuentas ajenas).

| Severidad | Cantidad | Qué significa |
|---|---|---|
| Crítica | 1 (resuelta) | Compromete producción si se despliega tal cual. |
| Alta | 2 (2 resueltas) | Explotable hoy por un usuario registrado o anónimo. |
| Media | 8 (7 resueltas) | Debilita seguridad, disponibilidad o integridad de datos; conviene corregir antes de abrir la app al público. |
| Baja | 12 | Endurecimiento y buenas prácticas. |
| Refactor / código muerto | 9 | Deuda técnica; no afecta a usuarios hoy. |
| UX/UI | 11 | Accesibilidad, móvil y flujos. |

**Orden recomendado de trabajo:** C1 → A1 → A2 → M1, M2, M3 → resto (ver sección 9).

---

## 2. Cómo se hizo la auditoría

1. **Revisión de código** de la capa de seguridad (sesión, CSRF, origen, login, recuperación, límites de intentos), adjuntos, importador/exportador CSV, perfil, administración, espacios compartidos, borrado de cuentas, correo, repositorios (consultas SQL), vistas (escape de salida), Docker, nginx, PHP y scripts de base de datos.
2. **Suites del proyecto:** unitarias (504), integración con BD temporal (436) y humo con curl (792). **Todas pasan, 0 fallos.** `php -l` sobre todos los `.php` de `app/`, `public/`, `views/` y `scripts/`: sin errores de sintaxis.
3. **Pruebas de ataque en vivo** (scripts en Python contra la app en marcha, con 3 usuarios de prueba y un espacio compartido; ver sección 7).
4. **UX/UI** con las skills `ui-ux-pro-max` y `frontend-design`: capturas a 375 px, auditoría de accesibilidad de 16 páginas y revisión del CSS.
5. **Limpieza:** los usuarios `audit_*`/`victima_*`, sus datos, comprobantes en disco y correos de prueba se borraron al terminar. No se modificó ningún archivo del código.

**Lo que NO se revisó a fondo** (para que no se dé por cubierto): `bin/backup-db`/`restore-db` y `tests/backup.sh`, `tests/carga.py`, la lógica matemática de `PlanificadorDeudas`/`PlanCuotas`/`ConversorMoneda` (solo mediante sus pruebas existentes) y los scripts de cron `procesar-recurrentes`/`enviar-resumen-mensual` más allá de leerlos. No se hizo prueba de carga ni escaneo de dependencias (no hay dependencias de terceros: `composer.lock` vacío).

---

## 3. Hallazgos de seguridad

### CRÍTICA

#### C1 · El administrador de la seed (clave pública) se crea también en producción — ✅ RESUELTO (2026-11-26)
- **Dónde:** `database/02-seed.sql:24-25`, `docker-compose.prod.yml` (monta `./database` en `docker-entrypoint-initdb.d`), `docs/DESPLIEGUE.md:67`.
- **Evidencia:** `admin@lucas.local` / `Admin#2026dev` está documentado en `docs/BASE-DE-DATOS.md:211-212` y en `tests/smoke.sh:19`. En un volumen vacío de producción, MySQL ejecuta `02-seed.sql` y el usuario queda **activo y con rol admin**. La única defensa es un paso manual de la documentación («desactiva el admin»).
- **Impacto:** quien conozca el repositorio entra como administrador en cualquier despliegue donde se olvide ese paso. El rol admin no ve movimientos ni saldos (RNF-21), pero sí puede **crear otros admins, desactivar o reactivar usuarios** y ver todos los correos.
- **Solución:**
  1. Sacar el admin de `02-seed.sql` y dejarlo en un archivo que solo monte el compose de desarrollo (`./database/dev:/docker-entrypoint-initdb.d/dev:ro`), o en un `04-dev-admin.sh` que no haga nada si `APP_ENV != local` (pasar `APP_ENV` al servicio `db` solo en desarrollo).
  2. Defensa en profundidad: si `APP_ENV != local` y existe un usuario activo con ese correo y el hash conocido, que `salud.php` devuelva 503 y el dashboard admin muestre un aviso rojo. Ya existe en `unit.php:159` la comprobación de que el hash de la seed es `Admin#2026dev`; reutilizarla.
  3. Probar en `tests/smoke.sh` con `APP_ENV=production` que el admin de la seed **no existe**.
- **Esfuerzo:** 1-2 h.
- **Qué se hizo:**
  - `database/02-seed.sql` ya **no crea usuarios** (solo categorías). El administrador de desarrollo pasó a `database/dev/admin.sql` (subcarpeta: MySQL no la ejecuta sola) y lo aplica `database/04-dev-admin.sh` **solo si el servicio `db` recibe `APP_ENV=local`**, que únicamente define `docker-compose.yml`.
  - Defensa en profundidad: `App\Security\AdminSemilla`. Fuera de `local`, si `admin@lucas.local` sigue activo con el hash público (base antigua o volcado restaurado), `salud.php` responde **503** y registra `[seguridad] …`, y el dashboard de administración muestra un aviso rojo.
  - Pruebas: 11 nuevas en `unit.php` (hash, seed sin usuarios, script condicionado, `AdminSemilla`), comprobaciones en `smoke.sh` y `ataques.py`, y `integration.php` carga `dev/admin.sql` a propósito.
  - Verificado con arranques reales de MySQL con volumen vacío: sin `APP_ENV` → 0 usuarios y 12 categorías; con `APP_ENV=local` → `admin@lucas.local`.
  - Documentación actualizada: `DESPLIEGUE.md` (incluye qué hacer si la base viene de una versión anterior), `BASE-DE-DATOS.md`, `REQUISITOS.md` (RNF-23) y `ARQUITECTURA.md`.
  - **Para bases ya desplegadas** el cambio no borra nada: sigue valiendo desactivar la cuenta desde Administración → Usuarios, y ahora Lucas te lo avisa.

### ALTA

#### A1 · El correo no se verifica en el registro — ✅ RESUELTO (2026-11-27)
- **Dónde:** `AuthController::register()`, tabla `usuarios` (no tiene columna de verificación).
- **Evidencia (probado):** registrar `victima_…@correo.com` desde otra persona entra directo al panel (`302 /panel.php`). El correo ajeno queda ocupado.
- **Impacto, encadenado con otras funciones:**
  - **Robo de invitaciones familiares:** un propietario invita a `ana@x.com` a su espacio (datos financieros del hogar). `aceptarInvitacion` solo exige que el correo de la sesión coincida con el invitado. Quien se registró antes con `ana@x.com` sin ser Ana **entra al espacio**.
  - La víctima real no puede registrarse («Este correo ya está registrado») y solo recupera la cuenta si usa «Olvidé mi contraseña» (el correo sí le llega a ella).
  - El atacante puede activar alertas/resumen y hacer que Lucas **envíe correos a una persona que no los pidió** (hasta 1 resumen de prueba por hora y cuenta, y se pueden crear 20 cuentas por IP cada 15 min).
- **Solución:** migración `013-verificacion-correo.sql` con `correo_verificado_en DATETIME NULL`; enlace con token de 256 bits guardado como hash (reutilizar el patrón de `restablecimientos`); y **exigir verificación** para: aceptar invitaciones, activar notificaciones por correo, y enviar el resumen de prueba. Las cuentas existentes pueden marcarse verificadas por migración si se acepta el riesgo, o forzar verificación al próximo inicio de sesión. Al restablecer la contraseña con éxito se puede marcar el correo como verificado (demuestra control del buzón).
- **Esfuerzo:** 0,5-1 día.
- **Qué se hizo:**
  - **Migración 013** (`correo_verificado_en` en `usuarios` + tabla `verificaciones_correo`, ya incluida en `01-schema.sql` y en los permisos de `03-users.sh`). Los administradores nacen y se migran verificados; las demás cuentas existentes quedan **sin verificar** (decisión segura; en `DESPLIEGUE.md` está el `UPDATE` para marcarlas si confías en ellas).
  - **Servicio `VerificacionCorreo`**: token de 256 bits guardado solo como hash, 48 h, un solo uso, el enlace deja de valer si la cuenta cambió de correo o está inactiva, y máximo 3 envíos por hora y usuario. El registro envía el enlace **después** de responder (no espera al SMTP).
  - **`/verificar.php`**: el enlace (GET) solo muestra un botón; **confirmar es un POST con CSRF y origen**, así que un antivirus o un previsualizador de enlaces no lo gasta. No exige sesión. Restablecer la contraseña con el enlace del correo también verifica.
  - **Qué exige correo verificado:** enviar invitaciones, aceptar invitaciones (por token o por id; se comprueba antes de tocar la invitación), recibir alertas de presupuesto y resúmenes mensuales (incluido el de prueba) y activar esos avisos. Las invitaciones dirigidas a una dirección **no se listan** a una cuenta sin verificar (no se filtran nombres de espacios).
  - **UX:** aviso en todas las páginas del usuario mientras falte verificar, tarjeta «Correo electrónico» en *Mi perfil* con «Reenviar enlace», mensajes claros en cada bloqueo y `espacios.php`/`verificar.php` dentro de la zona `limit_req` de nginx.
  - Pruebas: 5 unitarias, 31 de integración (token, hash, caducidad, cambio de correo, un solo uso, límite de reenvíos, restablecer verifica, invitaciones, notificaciones, migración idempotente), 28 de humo HTTP de punta a punta y 11 de `ataques.py` (el escenario completo: el atacante registra el correo de la víctima, no puede aceptar la invitación ni recibir correo; la víctima verifica por el enlace real de Mailpit). `ataques.py` ya no marca A1 como conocido.
  - **Limitación que queda:** si el atacante registra primero el correo de la víctima y la víctima real hace clic en el enlace de verificación que le llega, la cuenta del atacante queda verificada. El correo le avisa «si no creaste una cuenta en Lucas, ignóralo»; la defensa completa sería permitir que quien demuestra el buzón (recuperar contraseña) tome la cuenta, y eso ya ocurre: restablecer la contraseña cambia la clave y cierra las sesiones del atacante. Además, para ENTRAR a un espacio hay que estar invitado por su propietario, y el propietario verá en la lista de miembros el nombre que puso el atacante.

#### A2 · Las invitaciones a espacios no tienen límite: sirven para enviar correo a terceros — ✅ RESUELTO (2026-11-27)
- **Dónde:** `GestionEspacios::invitar()`, `EspacioController` (acción `invitar`); la ruta `espacios.php` tampoco está en el `limit_req` de nginx de producción.
- **Evidencia (probado):** un usuario recién registrado creó un espacio e invitó a **30 direcciones externas seguidas, 30/30 aceptadas** y 30 correos salieron (Mailpit). El cuerpo incluye el nombre del invitador y del espacio, ambos texto libre.
- **Impacto:** Lucas se convierte en un emisor de spam/phishing con el dominio y la reputación de la aplicación; el proveedor SMTP puede suspender la cuenta.
- **Solución:**
  - Límite por usuario y por día (p. ej. 10 invitaciones/24 h) y por espacio (p. ej. máx. 20 pendientes), reutilizando `LimitadorIntentos` con un tipo `invitacion`.
  - Exigir correo verificado (A1) para invitar.
  - Añadir `espacios.php` a la zona `limit_req` de nginx.
  - Limitar el texto libre del correo (nombre del espacio sin URL) o mostrar un aviso fijo «Este correo lo envió Lucas en nombre de un usuario».
- **Esfuerzo:** 2-3 h.
- **Qué se hizo:**
  - **Tres límites** (`ReglasEspacio::evaluarLimitesInvitacion`, función pura) sobre una ventana móvil de 24 h: **10 invitaciones por usuario**, **3 por dirección de destino** (venga de quien venga; el correo se normaliza a minúsculas) y **20 pendientes por espacio** (las caducadas no cuentan; reinvitar a quien ya tiene una vigente la sustituye y no suma).
  - Los contadores usan la tabla existente `notificaciones_enviadas` (sin migración): claves `invitacion:u<id>:…` e `invitacion-destino:<hash>:…`, anotadas **sin `id_espacio`**, para que borrar un espacio no reinicie los contadores (se probó). La fila del usuario se bloquea con `FOR UPDATE` mientras se cuenta, de modo que peticiones simultáneas no se salten el tope.
  - El control de permisos sigue yendo **antes** que los límites (un lector recibe «sin permiso», un no miembro «no existe», y ninguno consume cupo).
  - `EspacioController`: responde **429** con un mensaje claro en el campo del correo para cada límite.
  - El correo incluye ahora un aviso fijo: lo envió Lucas porque un usuario escribió esa dirección, Lucas no verifica quién es, y se puede ignorar.
  - `production.conf.template`: `espacios.php` entra en la zona `limit_req` de nginx (30 peticiones/min por IP, ráfaga 10).
  - Pruebas: 10 nuevas en `unit.php`, 14 en `integration.php` (incluye reinicio a las 24 h y caducadas) y `ataques.py` ya no marca A2 como conocido (ahora exige ≤ 9 invitaciones aceptadas en la ráfaga).
  - **Cerrado con A1:** enviar invitaciones exige correo verificado, así que crear cuentas desechables ya no sirve para enviar correo a terceros (sigue valiendo el límite por destinatario).

### MEDIA

#### M1 · Cualquiera puede bloquear el inicio de sesión de otra persona — ✅ RESUELTO (2026-11-27)
- **Dónde:** `LimitadorIntentos` (clave por correo, 5 intentos/15 min).
- **Evidencia (probado):** 7 intentos fallidos con el correo de la víctima → el **intento correcto del dueño devolvió 429**. Otro usuario desde la misma IP sí pudo entrar.
- **Impacto:** negación de servicio dirigida (el atacante repite cada 15 min). Compromiso de diseño conocido de este tipo de límite.
- **Solución:** contar los fallos por **par (correo, IP)** para el bloqueo duro y mantener un umbral mucho mayor (p. ej. 50) solo por correo; o bloquear con retardo progresivo en vez de 429 total; avisar por correo «hubo N intentos fallidos» al dueño.
- **Esfuerzo:** 2 h.
- **Qué se hizo:**
  - `LimitadorIntentos`: en `login` y `clave` (los que adivinan una contraseña) el bloqueo duro es por **par (correo, IP)**, 5 fallos / 15 min (clave `par:<sha256 de correo+IP>`, sin datos en claro). Los fallos de otra IP ya no dejan fuera al dueño.
  - Contra quien rote IP para probar claves de una misma cuenta se mantiene un límite **por correo, pero alto (50)**, y el de IP (20). `registro` conserva el límite clásico (5 por correo, 20 por IP), que protege el correo, no una contraseña.
  - Un acceso correcto perdona el par y el contador del correo.
  - Pruebas: 6 unitarias, 8 de integración y 3 de `ataques.py` (el dueño entra tras 5 fallos desde otra IP; tras fallos repartidos de 3 IP; 50 fallos desde IP rotatorias sí bloquean). `ataques.py` ya no marca M1 como conocido.
  - Limitación que queda: quien comparta IP con el atacante (NAT, oficina) sigue pudiendo quedar bloqueado 15 min; el bloqueo por IP (20) también lo afecta. Al eliminar una cuenta, la clave por par no se borra (no se puede localizar sin la IP), pero es un hash de correo+IP que caduca a los 15 min.

#### M2 · La imagen de producción incluye Xdebug activo en modo `develop` — ✅ RESUELTO (2026-11-26)
- **Dónde:** `docker/php/Dockerfile` (`install-php-extensions … xdebug`), `docker-compose.prod.yml` (no define `XDEBUG_MODE`), `docker/php/xdebug.ini` (solo se carga con `APP_ENV=local`).
- **Evidencia (probado):** con `PHP_INI_SCAN_DIR` de producción y sin `XDEBUG_MODE`, el contenedor responde `xdebug.mode=develop` y la extensión cargada.
- **Impacto:** sobrecoste de rendimiento en cada petición, trazas de pila ampliadas y superficie innecesaria.
- **Solución (cualquiera de las dos):** `ARG INSTALL_XDEBUG=0` y solo instalarlo en dev; o añadir `XDEBUG_MODE: "off"` al servicio `php` de `docker-compose.prod.yml`. Hacerlo mejor con un `Dockerfile` de varias etapas (ver B1).
- **Esfuerzo:** 30 min.
- **Qué se hizo:**
  - `docker/php/Dockerfile`: nuevo `ARG INSTALL_XDEBUG=1`; Xdebug se instala en una capa aparte **solo si vale 1**. Desarrollo no cambia (`docker-compose.yml` pasa `${INSTALL_XDEBUG:-1}`).
  - `docker-compose.prod.yml`: construye con `INSTALL_XDEBUG: "0"` y además fija `XDEBUG_MODE: "off"` en el servicio `php` (segunda barrera por si alguien reconstruye con Xdebug).
  - Verificado construyendo ambas imágenes: la de producción → `xdebug: no` (y mantiene `pdo_mysql`, `intl`, `zip`, `opcache`, `mbstring`); la de desarrollo → Xdebug cargado y `xdebug.mode=off` por defecto, como antes. Con Xdebug presente, `XDEBUG_MODE=off` deja el modo efectivo vacío (`xdebug_info('mode')`), mientras que sin la variable queda `["develop"]`, lo que confirma el hallazgo original.
  - Pruebas: 3 comprobaciones nuevas en `smoke.sh` y 3 en `ataques.py` sobre la configuración de producción (el contenedor en marcha es el de desarrollo y lleva Xdebug a propósito, así que no sirve para probarlo en vivo).
  - Nota: tras actualizar, la imagen de producción hay que **reconstruirla** (`docker compose -f docker-compose.prod.yml up -d --build`).

#### M3 · Al eliminar un espacio compartido los comprobantes quedan en disco — ✅ RESUELTO (2026-11-27)
- **Dónde:** `GestionEspacios::eliminarEspacio()` → `EspacioRepository::deleteWithAllData()`: borra filas pero no recoge `adjunto_ruta` ni llama a `AlmacenAdjuntos::eliminar()`. (`EliminarCuenta` sí lo hace para cuentas personales.)
- **Impacto:** archivos con datos financieros huérfanos y sin forma de acceso, violando RNF-20 («se borra todo»), además de crecer el almacenamiento.
- **Solución:** antes del `DELETE`, `SELECT adjunto_ruta FROM transacciones WHERE id_espacio = :e AND adjunto_ruta IS NOT NULL`; tras confirmar la transacción, `AlmacenAdjuntos::eliminar()` de cada ruta (mismo patrón de `EliminarCuenta`). Añadir prueba de integración. Tarea de mantenimiento adicional: un script que detecte archivos en `storage/adjuntos` sin fila en `transacciones`.
- **Esfuerzo:** 1 h.
- **Qué se hizo:**
  - `EspacioRepository::deleteWithAllData()` ahora devuelve las rutas de comprobantes del espacio (las lee antes de borrar) y `GestionEspacios::eliminarEspacio()` las elimina del disco **después de confirmar la transacción** (si la transacción fallara, no se pierde ningún archivo). Es el mismo patrón de `EliminarCuenta`.
  - Nueva herramienta de mantenimiento `scripts/huerfanos-adjuntos.php` (clase `App\Support\HuerfanosAdjuntos`): lista —y con `--borrar` elimina— los archivos de `storage/adjuntos` que ninguna fila referencia; solo toca nombres canónicos (`YYYY/MM/<32 hex>.<ext>`) de más de 24 h, para no pisar una subida en curso; avisa además de filas cuyo archivo falta.
  - Pruebas: 5 unitarias del detector y 8 de integración (editor sin permiso no borra nada, el propietario borra los comprobantes de **todos** los miembros, los de otro espacio no se tocan, sin huérfanos después, un archivo suelto sí se detecta). Comprobado que las pruebas **fallan** si se quita el borrado. `ataques.py` ya no marca M3 como conocido.
  - **Hallazgo nuevo al hacerlo (B12):** las copias de seguridad (`bin/backup-db`) solo incluyen la base de datos, **no los comprobantes**. Una restauración deja movimientos con «comprobante» que no se puede abrir. Está documentado en `docs/DESPLIEGUE.md` (qué copiar con `rsync`); queda pendiente decidir si `bin/backup-db` debe empaquetarlos.

#### M4 · El correo se envía dentro de la petición web — ✅ RESUELTO (2026-11-28)
- **Dónde:** `NotificadorPresupuesto::notificarGasto()` (se llama desde `MovimientoController::form`), `GestionEspacios::invitar()`, `PerfilController` (resumen de prueba). `SmtpMailer` tiene timeout de 10 s.
- **Impacto:** si el SMTP está lento o caído, cada gasto registrado puede tardar hasta ~10 s por destinatario y ocupa un proceso de PHP-FPM (hay 40 en el pool): con tráfico, el sitio entero se degrada.
- **Solución:** tabla `cola_correo` (destinatario, asunto, cuerpo, intentos, estado) y un script `scripts/enviar-cola.php` por cron cada minuto (el proyecto ya usa scripts de cron). La petición solo inserta la fila. Reintentos con backoff y límite.
- **Esfuerzo:** 0,5 día.
- **Qué se hizo:**
  - **Tabla `cola_correo`** (migración 015) y `ColaMailer`: en una petición web, `Mailer::send()` solo inserta la fila (valida la dirección y los saltos de línea, para que un error evidente se vea al momento). Los scripts de consola (`enviar-resumen-mensual.php`…) siguen enviando directo.
  - **Servicio `correo`** en `docker-compose.yml` y `docker-compose.prod.yml` (mismo código y variables que `php`): ejecuta `scripts/enviar-cola.php` en bucle (cada 2 s en desarrollo, 5 s en producción). No hace falta cron en el host.
  - **Reintentos**: hasta 5 intentos con espera creciente (1, 5, 15 y 60 min); después queda «fallido» y se registra en el log. Dos procesos a la vez no se reparten el mismo correo (`FOR UPDATE SKIP LOCKED`), y si el proceso muere a medias el correo vuelve a estar disponible a los 5 min.
  - **El cuerpo se borra** de la base en cuanto el correo sale o se da por perdido (puede llevar enlaces con token de restablecimiento o verificación). El historial se purga: enviados a los 7 días, fallidos a los 30.
  - **Monitorización**: `docker compose exec -T php php scripts/enviar-cola.php --estado` (pendientes, enviados, fallidos y antigüedad del más viejo).
  - Medido con el SMTP caído: `recuperar.php` responde en **0,05 s** (antes, hasta ~10 s por destinatario con un proceso de PHP-FPM ocupado) y el correo queda pendiente con su reintento programado.
  - Pruebas: 12 de integración y 3 de humo. La suite de humo espera a que la cola esté vacía antes de mirar Mailpit.
  - Efecto en E1: si el SMTP falla, la invitación sigue guardada y el correo se reintenta solo (el aviso «Se envió» ya es cierto en el sentido de que está en cola).
  - Limitación: el aviso al usuario ya no puede decir «no se pudo enviar» al instante (p. ej. «resumen de prueba»); un fallo definitivo solo aparece en el log y en `--estado`. · Sesiones: dos fuentes de verdad, escritura por petición y sin topes — ✅ RESUELTO PARCIALMENTE (2026-11-27)
- **Dónde:** `app/Security/session.php`, `SesionRepository`.
- **Hechos:**
  - Los datos de sesión van a archivos en `/tmp` del contenedor PHP: se pierden al recrearlo y **no funcionan con más de un contenedor PHP**. A la vez existe la tabla `sesiones_usuario`. Dos fuentes que pueden desincronizarse.
  - Cada página hace `SELECT` + `UPDATE sesiones_usuario` (una escritura por petición), más `findById` y `findForMember`: 4-5 consultas antes de renderizar.
  - **Sin tope de sesiones simultáneas** (probado: 9 sesiones acumuladas tras 9 inicios) y **sin caducidad absoluta** (una sesión activa sigue indefinidamente mientras haya actividad).
- **Solución:** un `SessionHandler` propio sobre la tabla (o Redis) para unificar; actualizar `ultimo_uso` como máximo 1 vez por minuto; fusionar `validarYRenovar` en un solo `UPDATE … WHERE` y mirar `rowCount()`; tope de 10 sesiones por usuario (se cierra la más antigua) y caducidad absoluta de 12-24 h. Mientras tanto, montar `/tmp/sessions` como volumen si algún día se reinicia el contenedor sin querer perder sesiones.
- **Esfuerzo:** 0,5-1 día.
- **Qué se hizo** (`SesionRepository`):
  - **Tope de 10 sesiones** por usuario: al abrir una más se cierra la menos usada (nunca la nueva ni las de otros usuarios).
  - **Caducidad absoluta de 12 h** (`creado_en`): una sesión con actividad continua también termina y se borra.
  - **Una consulta por petición** (antes `SELECT` + `UPDATE`), y `ultimo_uso` solo se escribe si pasó más de 1 minuto.
  - Pruebas: 7 de integración y la de `ataques.py` (ya no es CONOCIDO).
- **Pendiente (decisión consciente):** unificar los datos de sesión de PHP (archivos en `/tmp`) con la tabla mediante un `SessionHandler` propio. Solo hace falta si algún día hay más de un contenedor PHP; con uno solo, los archivos funcionan y cambiarlo arriesga la autenticación. Mientras tanto, la tabla es la que manda: si falta la fila, la sesión no vale. Si se reinicia el contenedor, las sesiones se pierden (hay que volver a entrar).

#### M6 · Los comprobantes se sirven «inline» desde el mismo origen sin CSP propia — ✅ RESUELTO (2026-11-27)
- **Dónde:** `MovimientoController::adjunto()`.
- **Estado:** bien resuelto lo principal (fuera de `public/`, nombre aleatorio, firma + `finfo`, `nosniff`, `no-store`, IDOR probado → 404). Las pruebas con `shell.php`, SVG con `onload`, GIF, PNG+`<?php`, doble extensión y nombre `../../` quedaron todos inertes: se guardan como `.png/.jpg/.pdf` con nombre aleatorio y **nunca se ejecutan**.
- **Riesgo residual:** un PDF con JavaScript o una imagen con EXIF (ubicación GPS del móvil) se muestran/entregan sin tratar.
- **Solución:** añadir a la respuesta `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` y, para PDF, forzar `attachment` por defecto. Opcional: re-codificar JPG/PNG/WebP con GD/Imagick para eliminar EXIF y cargas ocultas.
- **Esfuerzo:** 1 h (cabeceras) / 3 h (re-codificado).
- **Qué se hizo:**
  - `MovimientoController::adjunto()`: todas las respuestas llevan `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` y `Cross-Origin-Resource-Policy: same-origin`.
  - Los **PDF siempre se descargan** (`Content-Disposition: attachment`): no se abren en el origen de la aplicación, y además un `sandbox` rompería el visor de PDF del navegador. Las imágenes siguen mostrándose en una pestaña.
  - Pruebas: 3 en `smoke.sh` y 4 en `ataques.py` (ya no es CONOCIDO).
  - **No se hizo** el re-codificado de imágenes para quitar EXIF (3 h, opcional): una foto del móvil conserva su ubicación GPS al descargarla. Queda anotado como mejora futura.

#### M7 · No hay autenticación en dos pasos — ✅ RESUELTO (2026-11-28)
- Está listada como pendiente (`docs/MEJORAS-FUTURAS.md`, propuesta 10: la parte de sesiones activas ya existe, falta TOTP). Para una app financiera es la mejora de seguridad con más valor que falta. **Solución:** TOTP (RFC 6238) con códigos de recuperación, obligatorio para administradores. **Esfuerzo:** 1-2 días.
- **Qué se hizo** (✅ RESUELTO 2026-11-28):
  - **TOTP propio** (`Auth\Totp`, PHP puro, verificado con los vectores del RFC 6238): 6 dígitos, 30 s, tolera ±1 paso. Compatible con Google Authenticator, Aegis, 2FAS, Authy…
  - **Activación en Mi perfil**: clave en grupos de 4 + enlace `otpauth://`, y se confirma con un código antes de activar. Entrega **10 códigos de recuperación** (80 bits, solo se guarda su hash; se muestran una vez). Al activarlo se **cierran las demás sesiones**.
  - **Inicio de sesión en dos pasos**: con la contraseña correcta queda un *reto* de 5 min (sin `user_id`: ninguna página lo acepta); el id de sesión se renueva al completarlo. Acepta el código de la app o uno de recuperación (con aviso de cuántos quedan).
  - **Secreto cifrado** con libsodium y `APP_KEY` (nueva variable; obligatoria en producción). Un código TOTP no se acepta dos veces (`totp_ultimo_paso`, atómico).
  - **Límites**: 5 fallos por reto, 5 por (correo, IP) y 10 por correo en 15 min (contadores propios, clave `2fa:correo`), también en las acciones del perfil.
  - **Desactivar / regenerar códigos** exigen contraseña **y** un código. **Administradores**: obligatorio fuera de `APP_ENV=local` (o con `ADMIN_2FA_OBLIGATORIO=1`): sin 2FA, las páginas de administración redirigen a Mi perfil, y no pueden desactivarlo.
  - **Recuperación de último recurso**: `bin/as-migrator php scripts/desactivar-2fa.php correo` (requiere acceso al servidor).
  - Pruebas: 23 unitarias, 23 de integración, 18 de humo y 26 de `ataques.py` (repetición, fuerza bruta, fijación de sesión, CSRF, códigos de un solo uso, secretos no en claro, sesiones cerradas al activar). La obligatoriedad para administradores se comprobó a mano arrancando con `ADMIN_2FA_OBLIGATORIO=1` (las suites corren en `APP_ENV=local`).
  - **No se hizo**: código QR (hay clave para teclear y enlace `otpauth://`; un QR necesitaría una librería o generarlo a mano), aviso por correo al activar/desactivar, ni 2FA por SMS/correo (a propósito: más débiles).
  - Si se pierde `APP_KEY`, los secretos guardados no se pueden leer: nadie con 2FA podría pasar el segundo paso hasta usar `desactivar-2fa.php` y reactivarlo.

#### M8 · Importación CSV sin vista previa y con formato de número ambiguo
- **Dónde:** `ImportadorCsv`, `Dinero::parse()`.
- **Evidencia (probado):** `0.001` se acepta como **$1,00** y `1.234` como **$1.234** (punto = miles, decisión documentada). Un CSV de un banco con decimales en punto («1.500» = ¿1,5 o mil quinientos?) se importa **sin que el usuario vea el resultado antes de guardar**, y no hay detección de duplicados.
- **Solución:** paso intermedio «Vista previa» (tabla con fecha, descripción, monto interpretado y total), botón «Confirmar importación», aviso si algún monto es ambiguo, y detección de duplicados (misma fecha+monto+descripción en la cuenta). **Esfuerzo:** 0,5 día.

### BAJA

| ID | Hallazgo | Solución |
|---|---|---|
| B1 | La imagen de producción lleva `git`, `unzip`, `composer` y Xdebug, y `src/` se monta **con escritura** en el contenedor PHP de producción. Si hubiera una ejecución de código, el atacante podría modificar la aplicación de forma persistente. | `Dockerfile` multi-etapa; copiar `src/` a la imagen (no montar) y dejar un único volumen escribible `storage/`. `opcache.validate_timestamps=0` en producción. |
| B2 | Tokens de recuperación e invitación viajan en la URL (`?token=`): quedan en los logs de nginx y en el historial. Aceptar una invitación con un **GET que cambia estado**. `Referrer-Policy: same-origin` evita fugas a terceros. | La página con `?token` solo muestra un botón «Aceptar» que hace POST con CSRF; excluir `token` del `access_log` (`map`/`log_format`). |
| B3 | El límite por IP usa `REMOTE_ADDR`. Detrás de un balanceador o CDN todos los visitantes compartirían IP (20 intentos globales bloquearían a todos) y, si se confiara en `X-Forwarded-For` sin lista de proxys, se podría falsificar. | Documentar y configurar `set_real_ip_from` + `real_ip_header` en nginx solo si hay proxy delante. |
| B4 | La página de login crea un archivo de sesión por cada visita (necesario para el token CSRF), sin límite → llenado de disco/inodos por bots. | `limit_req` ya cubre `/login.php` en producción; añadir limpieza (`session.gc_probability`) y vigilar `/tmp`. |
| B5 | `SmtpMailer` permite `AUTH LOGIN` con `MAIL_ENCRYPTION=none`, enviando credenciales en claro si se configura mal en producción. | Rechazar `none` con usuario/clave cuando `APP_ENV != local`. |
| B6 | Política de contraseña: solo largo ≥ 8. Sin comprobación de contraseñas comunes o filtradas. | Lista de las 10 000 contraseñas más comunes incluida en el repo; mínimo 10-12 caracteres. |
| B7 | Cookie de sesión sin prefijo `__Host-` en producción. | `session_name('__Host-lucas_session')` cuando `app_uses_https()` (exige `Secure`, `Path=/`, sin `Domain`). |
| B8 | Al eliminar una cuenta quedan invitaciones pendientes a ese correo en `espacio_invitaciones` (dato personal residual, RNF-20). | `DELETE FROM espacio_invitaciones WHERE correo = :c` dentro de `deleteWithAllData`. |
| B9 | `RestablecimientoRepository::purge()` nunca se llama: los enlaces caducados se acumulan. La purga de `intentos_acceso` es probabilística (1/50). | Llamarlos desde un script de mantenimiento diario. |
| B10 | Valores de desarrollo por defecto (`lucas_app_dev_2026`, `lucas_root_dev_2026`) en `.env.example` y en `docker-compose.yml`. El compose de producción exige variables pero no valida su fortaleza. | Validación al arrancar (`APP_ENV != local` y contraseña contiene `dev` → fallar), o `openssl rand` en la guía. |
| B12 | **Nuevo.** Las copias de seguridad no incluyen los comprobantes de `src/storage/adjuntos/` (ni `tests/backup.sh` lo comprueba). | Documentado en `DESPLIEGUE.md` (copiar con `rsync`/`tar`). Mejor: que `bin/backup-db` genere también `adjuntos-<fecha>.tar.gz` y `bin/restore-db`/`tests/backup.sh` lo prueben. |
| B11 | Un fallo al guardar el comprobante después de guardar el movimiento devuelve 500 con el movimiento ya creado (no es atómico). | Guardar el archivo primero y borrarlo si la transacción falla, o capturar la excepción y mostrar «Movimiento guardado, pero el comprobante falló». |

### Lo que se probó y está bien (no tocar)

Todo esto se verificó con peticiones reales o se cubre con pruebas existentes:

- **Aislamiento de datos (IDOR):** 26 intentos de un usuario B sobre objetos de A (movimientos, cuentas, categorías, metas, aportes, deudas, pagos, transferencias, recurrentes, presupuestos, conciliar, importar CSV, adjuntos). Resultado: todo `404`/rechazado, **ningún dato de A cambió**. Cuentas ajenas como origen/destino/pago también se rechazan.
- **Roles en espacios compartidos:** un `lector` recibe `403` al intentar crear cuentas, categorías, metas, presupuestos, deudas, importar, guardar tasas, recurrentes, cambiar su rol a propietario, invitar o eliminar el espacio. Un no miembro no puede cambiar a un espacio ajeno (`404`).
- **Administración:** un usuario normal recibe `403` en `/admin/*` y no puede hacer admin a otro. El campo `rol` enviado por POST se ignora (anti *mass assignment*). Las categorías globales no se pueden editar ni borrar por usuarios normales.
- **Carreras (20 peticiones simultáneas):** 20 gastos de $100 contra una cuenta con $100 → **1 guardado**; 20 transferencias → **1**. Seis usos simultáneos del mismo token de recuperación → **solo 1 exitoso**. Los saldos no quedan en negativo.
- **Sesión:** un id de sesión impuesto por el atacante se reemplaza (no hay fijación); la cookie de una sesión cerrada ya no sirve; cookie `HttpOnly; SameSite=Lax`; cambiar la contraseña cierra las demás sesiones.
- **CSRF y origen:** sin token, otro origen o `GET` en rutas de acción → rechazados (también cubierto por el humo).
- **Inyección y XSS:** payloads `'; DROP TABLE…`, `<script>`, `"><svg onload>`, `<img onerror>`, nombres con `<b>`, null bytes y emoji en categorías, cuentas, descripciones, etiquetas y filtros: **siempre escapados** en listados, formularios, selects, reportes y la vista imprimible. Todas las consultas usan sentencias preparadas (`ATTR_EMULATE_PREPARES=false`); el SQL dinámico solo interpola fragmentos fijos de código (`$scope`, `$limit` entero).
- **Fuzzing de parámetros:** `pagina=-1/0/99999…`, arrays en lugar de texto (`mes[]=1`, `id[]=1`), ids enormes, meses inválidos, montos `NaN`, `1e9`, `٣`, negativos, cero, `num_cuotas` = 1 000 000 / -1 → **sin ningún 500**; las cuotas absurdas se rechazan.
- **Subida de archivos:** `.php`, SVG con script y GIF rechazados; PNG/JPG con código PHP incrustado y doble extensión se guardan con **extensión canónica y nombre aleatorio fuera de `public/`**; 7 MB → 413 de nginx.
- **Enumeración de usuarios:** misma respuesta y mismo tiempo (0,245 s vs 0,245 s) con correo existente e inexistente; la recuperación responde igual siempre. (El registro sí revela «ya registrado»: decisión D2 documentada.)
- **Cabeceras y exposición:** CSP estricta sin `unsafe-inline`, `X-Frame-Options: DENY`, `nosniff`, `COOP/CORP`, `Permissions-Policy`, `Server: nginx` sin versión, sin `X-Powered-By`; `/.env` → 403; `/vendor`, `/app`, `/bootstrap.php`, `/storage` no accesibles; `TRACE` → 405; `status.php` solo en local (404 en producción). Las páginas no se pueden enmarcar (clickjacking).
- **Permisos de BD:** el usuario de la app solo tiene `SELECT/INSERT/UPDATE/DELETE`, sin DDL, y no conoce la clave del usuario de migraciones. Contraseñas con bcrypt, hash señuelo contra temporización, `password_needs_rehash`, tokens de 256 bits guardados como hash y de un solo uso.
- **Dinero:** nunca `float`; centavos enteros; `DECIMAL(12,2)`; `FOR UPDATE` en el orden correcto.

---

## 4. Errores funcionales y de robustez

No se encontraron errores que corrompan datos. Los que hay son de comportamiento:

| ID | Hallazgo | Severidad | Solución |
|---|---|---|---|
| E1 | Si el servidor SMTP falla en `invitar`, la invitación queda guardada y el mensaje de éxito dice «Se envió la invitación» aunque el correo no salió (el `catch` está vacío). | Media | Devolver `correoEnviado` y avisar «Invitación creada, pero no pudimos enviar el correo; comparte este enlace». Con la cola (M4) queda resuelto. |
| E2 | `Dinero::parse` trata `1.234` como mil doscientos treinta y cuatro (documentado) pero en un campo de monto de formulario el usuario europeo/anglosajón podría escribir `12.50` esperando 12,50: funciona; `1,234.56` se rechaza con un mensaje genérico. | Baja | Mensaje de error específico: «Usa punto para miles y coma para decimales: 1.234,56». |
| E3 | Los errores en `Response::plain` (403 CSRF, 404, 429 del perfil) salen como **texto plano sin layout** ni enlace de vuelta. | Baja | Vista de error común (`views/errors/*.php`) con el layout y un botón «Volver». |
| E4 | `post_max_size` superado deja `$_POST` vacío → mensaje «Token de seguridad no válido» confuso. | Baja | Detectar `$_SERVER['CONTENT_LENGTH'] > post_max_size` y mostrar «El archivo es demasiado grande». |
| E5 | El service worker nunca elimina entradas antiguas de la caché (`lucas-static-v1` fijo; cada `?v=` nuevo añade una entrada). | Baja | Incluir la versión del despliegue en `CACHE_NAME` o limpiar las URLs viejas del mismo path. |

---

## 5. Código muerto y refactorización

### Código sin uso aparente
- Solo los llaman las pruebas, nunca la aplicación: `UsuarioRepository::countActiveAdmins()`, `ConversorMoneda::tasaImplicita()` y `AlmacenAdjuntos::guardarDesdeContenido()`.
- Nadie la llama: `RestablecimientoRepository::purge()` (ver B9).
- **Acción:** decidir si cada una se usará (p. ej. `purge()` en un script de mantenimiento) o se borra junto con su prueba. El resto del código revisado no tiene métodos públicos huérfanos ni restos de depuración (`var_dump`, `TODO`, `eval`, etc.).

### Refactor sugerido (por impacto)

| ID | Qué | Por qué | Cómo |
|---|---|---|---|
| R1 | `MovimientoController::form()` (~300 líneas, ramas de división y normal casi duplicadas) | Es el archivo más difícil de cambiar sin romper algo; ya concentra adjuntos, cuotas, presupuesto y correo. | Extraer `FormularioMovimiento` (lee/valida) y `GuardarMovimiento` (aplica + adjunto + avisos) y dejar el controlador en <100 líneas. |
| R2 | La lista de tablas a borrar se repite en **3 sitios** (`UsuarioRepository::deleteWithAllData` ×2 y `EspacioRepository::deleteWithAllData`), además de `APP_TABLES` en `03-users.sh` y `cleanup()` en `smoke.sh`. | Añadir una tabla nueva exige tocar 5 lugares; olvidar uno deja datos huérfanos o un error de permisos. | Una sola constante `Esquema::TABLAS_POR_ESPACIO`; test de integración que compare las tablas de `information_schema` con las listas. |
| R3 | `AlmacenAdjuntos::validarSubida` devuelve el mismo array de 7 claves ocho veces; `guardarDesdeArchivo/Contenido` duplican la creación de ruta. | Código largo y propenso a divergir. | Objeto `ResultadoSubida` con constructores estáticos `ok()`, `vacio()`, `error()`; método privado `rutaNueva()`. |
| R4 | `is_string($_POST['x'] ?? null) ? trim($_POST['x']) : ''` aparece decenas de veces en controladores. | Ruido y riesgo de olvidar `is_string`. | Clase `Request` con `texto('x')`, `entero('id')`, `esPost()`. |
| R5 | `Container::shared()` y funciones globales con `$_SESSION` (`session.php`). | Difícil de probar y de reutilizar en CLI. | Inyectar dependencias en `AuthContext`; las funciones globales quedan como fachada fina. |
| R6 | Plantilla de manejo de errores `try/catch RuntimeException → self::fail()` en cada controlador. | Repetición. | Manejador central en el front controller. |
| R7 | `View/Ui.php` (581 líneas) mezcla componentes de formulario, gráficos y botones. | Difícil de navegar. | Separar `Ui/Form.php`, `Ui/Chart.php`, `Ui/Button.php`. |
| R8 | Sin CI ni análisis estático: `.gitignore` menciona PHPStan/php-cs-fixer pero no están configurados. Tests con harness propio (3 000 líneas). | Los errores de tipo y estilo se descubren a mano. | `phpstan.neon` nivel 6, `php-cs-fixer`, y un workflow de GitHub Actions que ejecute `tests/run-all.sh`. Migrar el harness a PHPUnit cuando convenga. |
| R9 | Índices: las consultas de espacios compartidos filtran por `id_espacio` y ordenan por `fecha`, pero solo existe `idx_usuario_fecha (id_usuario, fecha)`. | Con muchos movimientos las pantallas de espacios degradarán. | `INDEX (id_espacio, fecha)` en `transacciones`, `transferencias` y `movimientos_recurrentes`; revisar con `EXPLAIN`. |

---

## 6. Revisión UX/UI

Método: `ui-ux-pro-max` (checklist de accesibilidad, interacción, rendimiento, layout, tipografía, estilo) y `frontend-design` (identidad visual y calidad), aplicados sobre capturas a 375 px de login, panel y formulario de movimiento, auditoría programática de 16 páginas y lectura de `tokens.css`, `components.css`, `layout.css`, `base.css`.

### Lo que está muy bien
- **Sistema de diseño coherente:** tokens de color, espaciado y radios en `tokens.css`, con contrastes WCAG medidos y documentados (texto secundario 5,7:1; bordes de control ≥ 3:1).
- **Accesibilidad de marcado excelente:** en las 16 páginas auditadas hay `lang="es"`, un único `h1`, `<main>`, enlace de salto, **0 campos sin etiqueta**, **0 botones de icono sin nombre**, tablas con `<caption>`, `autocomplete` correcto (`current-password`/`new-password`), `inputmode="decimal"` en montos, foco visible, `prefers-reduced-motion` respetado y botones de 44 px.
- **Móvil primero:** tablas que se transforman en tarjetas, menú lateral con foco gestionado, PWA con pantalla sin conexión, hoja de estilos de impresión para el reporte.
- **Identidad (`frontend-design`):** paleta propia sobre un navy/teal sobrio, bien ajustada a «finanzas de confianza». El único elemento protagonista, la tarjeta oscura de saldo, funciona: el resto se mantiene calmado. No cae en las plantillas genéricas de crema + serif ni de negro + verde ácido.

### Hallazgos

| ID | Severidad | Hallazgo | Solución |
|---|---|---|---|
| U1 | Media | **El botón flotante (+) tapa contenido** en móvil (en la captura del panel oculta texto de «Gastos por categoría») y duplica el botón «Nuevo movimiento» de la cabecera. | Añadir `padding-bottom: 88px` al contenido en móvil, o quitar el botón de cabecera en móvil y dejar solo el FAB; ocultar el FAB cuando el teclado está abierto. |
| U2 | Media | **El foco no pasa al primer error al fallar la validación.** (Corrección: `Ui::notice` sí usa `role="alert"`/`"status"`; la auditoría automática no los vio porque las 16 páginas se pidieron sin avisos ni errores visibles.) Falta verificar que los errores por campo se anuncien (`aria-describedby` ya existe en `Ui`). | `role="status"` en avisos de éxito, `role="alert"` en errores; resumen de errores arriba del formulario con enlaces a cada campo y `focus()` al cargar si hay errores; `aria-describedby` en cada campo inválido. |
| U3 | Media | **Objetivos táctiles menores de 44 px:** `.btn--sm` (36 px), botones de paginación (40 px) y los enlaces de texto «Ver reportes / Ver todos / Ver presupuestos» de las tarjetas. La guía `ui-ux-pro-max` pide ≥ 44 px y 8 px de separación. | `min-height: 44px` en móvil (`@media (pointer: coarse)`) para estos controles; padding vertical en los enlaces de cabecera de tarjeta. |
| U4 | Media | **Importar CSV no tiene vista previa** (ver M8) y solo muestra los 3 primeros errores del archivo. | Tabla de vista previa, lista completa de errores con número de línea y botón «Confirmar». |
| U5 | Baja | **Los errores del servidor salen como texto plano** (403/404/429 de `Response::plain`, 413 de nginx): sin marca, sin navegación, sin botón de volver. | Páginas de error con layout (ver E3) y `error_page 413` en nginx apuntando a una página estática. |
| U6 | Baja | **Formulario de movimiento largo y con campos innecesarios:** «Número de cuotas (tarjeta de crédito)» aparece siempre aunque la cuenta no sea tarjeta; no se preselecciona la cuenta cuando solo hay una; el campo Monto no recibe el foco; el selector de archivo es el nativo («Browse…» en inglés según el navegador) y rompe el estilo. | Mostrar «Cuotas» solo si la cuenta elegida es de tarjeta (el atributo ya se puede leer con `data-tipo`); preseleccionar cuenta única y última categoría usada; `autofocus` en monto; estilizar el `input[type=file]` con una etiqueta «Elegir archivo». |
| U7 | Baja | **No hay modo oscuro** (0 reglas `prefers-color-scheme`). El panel ya usa una tarjeta oscura, así que el sistema de color lo admite fácilmente. | Redefinir los tokens bajo `@media (prefers-color-scheme: dark)` y probar contrastes. |
| U8 | Baja | **Tipografía:** la pila declara `Inter` pero no se carga (la CSP solo permite `'self'`); la letra depende del sistema operativo (en Linux se ve Ubuntu). El texto base es 15 px (la guía recomienda 16 px; los campos sí tienen 16 px y no provocan zoom en iOS). | Alojar una fuente variable propia (`woff2` en `/fonts`, permitido por `font-src 'self'`) para una identidad uniforme; subir el texto base a 16 px en móvil. |
| U9 | Baja | **Alineación de los enlaces de cabecera de tarjeta** (en móvil «Ver reportes» queda debajo del título y sangrado respecto a él). | Alinear a la izquierda con el título o a la derecha con `margin-inline-start: auto`. |
| U10 | Baja | **La sesión caduca a los 30 min sin aviso**; si el usuario estaba rellenando un formulario largo (importar CSV, división de gasto) lo pierde. | Aviso a los 28 min con botón «Seguir conectado» (un `fetch` ligero) o guardar borrador en `sessionStorage`. |
| U11 | Baja | Las tarjetas son todas iguales (mismo radio y sombra) y la jerarquía entre «dato principal» y «dato de apoyo» depende solo del tamaño de texto. | Reservar el estilo de tarjeta elevada para lo accionable (saldo, presupuestos excedidos) y usar secciones con divisor para lo informativo, para que lo urgente destaque más. |

**U1, U2 y U3 — ✅ RESUELTOS (2026-11-28).**
- **U1 (botón flotante):** en móvil la cabecera ya no repite «Nuevo movimiento» cuando hay botón flotante; el botón flotante respeta el área segura de los móviles con muesca y se oculta mientras un campo tiene el foco (teclado abierto). El relleno inferior del contenido (104 px) ya evitaba que tapara el final de la página.
- **U2 (foco en errores):** `app.js` lleva el foco al primer campo con `aria-invalid="true"` al volver el formulario con errores, y lo centra en pantalla; el lector de pantalla lo anuncia con su mensaje (`aria-describedby`). No se añadió resumen de errores: con el foco en el campo y su mensaje enlazado cubre el caso sin ruido adicional.
- **U3 (objetivos táctiles):** en pantallas táctiles o de menos de 768 px los botones `sm`, los botones de icono, la paginación y el logo miden ≥ 44 px, y los enlaces de texto de avisos y `.link` ganan zona pulsable (relleno con margen negativo, sin cambiar el diseño). Medido en el panel a 390 px: ningún control interactivo por debajo de 44 px y sin desbordamiento horizontal.
- La caché del service worker pasó a `lucas-static-v2` para que quien ya tenga la PWA reciba el CSS/JS nuevos.
- Pruebas: 9 comprobaciones en `smoke.sh` (reglas CSS, JS, versión de la caché y el marcado `aria-invalid`/`aria-describedby` real de un formulario con error).


---

## 7. Pruebas de ataque realizadas (resumen reproducible)

Se ejecutaron desde fuera de la aplicación (HTTP), sobre la BD de desarrollo, y los casos quedaron en el repositorio como **`tests/ataques.py`** (solo biblioteca estándar de Python; ~108 comprobaciones, ~30 s):

```bash
python3 tests/ataques.py             # ejecuta todo; crea y borra usuarios ataque_*@correo.com
python3 tests/ataques.py --limpiar   # borra restos si una ejecución se interrumpió
```

Los hallazgos de este reporte que siguieran abiertos (hoy ninguno) se marcan en el script como `CONOCIDO <id>`: no hacen fallar la ejecución. Al corregir uno, la prueba imprime `RESUELTO <id>`; entonces se borra su argumento `conocido=` para que, si reaparece, falle de verdad. Hoy el script no está en `tests/run-all.sh` (envía ~12 correos a Mailpit y gasta cupo de registro por IP); conviene añadirlo cuando haya CI.

| Grupo | Casos | Resultado |
|---|---|---|
| IDOR entre usuarios | 26 | ✅ todos bloqueados |
| Roles de espacios compartidos | 14 | ✅ todos bloqueados |
| Escalada a admin / mass assignment | 6 | ✅ bloqueados |
| Carreras de saldo y de token | 3 × 20 hilos | ✅ sin sobregiro ni doble uso |
| XSS / inyección SQL / null bytes / unicode | 15 | ✅ escapado, sin errores |
| Fuzzing de parámetros y montos | 35 | ✅ sin 500 |
| Subida de archivos maliciosos | 8 + 7 MB | ✅ inertes (ver M6) |
| Sesión: fijación, reutilización tras logout | 3 | ✅ |
| Fuerza bruta y límites | 7 intentos | ✅ 429 a partir del 6.º desde la misma IP; el dueño desde otra IP entra (M1) |
| Enumeración por mensaje y por tiempo | 3 | ✅ sin diferencias (excepto registro, D2) |
| Abuso de invitaciones | 30 | ✅ corregido: tope de 10/día (antes ❌ sin límite, A2) |
| Correo ajeno sin verificar | 11 | ✅ corregido: no recibe invitaciones ni correo (antes ❌, A1) |
| Xdebug en configuración de producción | 1 | ❌ `develop` activo (M2) |

---

## 8. Cobertura de pruebas del proyecto

- Unitarias: **504 correctas, 0 fallidas**. Integración: **436 correctas, 0 fallidas**. Humo: **792 correctas, 0 fallidas**.
- Está bien cubierto: validadores, dinero, CSRF/origen, sesión, límites, correo, adjuntos, migraciones idempotentes, IDOR básico.
- **Falta cubrir:** pruebas de carrera con concurrencia real (hoy solo secuenciales), abuso de invitaciones, borrado de comprobantes al eliminar un espacio (M3), y que el admin de la seed no exista en producción (C1). Sin CI no se ejecutan en cada cambio (R8).

---

## 9. Plan de corrección priorizado

| Orden | ID | Tarea | Esfuerzo | Riesgo si se pospone |
|---|---|---|---|---|
| 1 | ~~C1~~ ✅ | Quitar el admin de la seed en producción + prueba | hecho | Acceso admin público |
| 2 | ~~M2~~ ✅ | Xdebug fuera de producción | hecho | Rendimiento / superficie |
| 3 | ~~A2~~ ✅ | Límite de invitaciones | hecho | Abuso de correo |
| 4 | ~~M3~~ ✅ | Borrar comprobantes al eliminar espacio | hecho | Datos huérfanos |
| 5 | ~~A1~~ ✅ | Verificación de correo | hecho | Toma de invitaciones |
| 6 | ~~M1~~ ✅ | Bloqueo por (correo, IP) | hecho | DoS de cuentas |
| 7 | ~~M6~~ ✅ | CSP/attachment en comprobantes | hecho | Endurecimiento |
| 8 | ~~M4~~ ✅ | Cola de correo | hecho | Disponibilidad |
| 9 | U1-U3 | FAB, aria-live y tamaños táctiles | 0,5 día | Accesibilidad |
| 10 | M8 / U4 | Vista previa de importación | 0,5 día | Datos erróneos |
| 11 | ~~M5~~ ✅ (parcial) | Tope de sesiones, caducidad absoluta, menos escrituras; falta el `SessionHandler` | hecho | Escalado |
| 12 | ~~M7~~ ✅ | 2FA TOTP | hecho | Cuenta financiera |
| 13 | R2, R8, R1 | Lista única de tablas, CI + PHPStan, partir `MovimientoController` | 1-2 días | Deuda técnica |
| 14 | Resto de B*, E*, U*, R* | Endurecimiento y pulido | continuo | Bajo |

### Antes del primer despliegue público (lista mínima)
- [x] C1 resuelto (la seed ya no crea el admin; verificado con arranque real y `salud.php`).
- [x] M2 (Xdebug) resuelto.
- [ ] B1 (código no escribible en producción) resuelto o aceptado por escrito.
- [x] A2 resuelto.
- [x] A1 resuelto.
- [ ] `APP_ORIGIN` con `https://`, certificados, `APP_ENV=production`, contraseñas de BD generadas (B10).
- [ ] SMTP real con TLS (B5) y SPF/DKIM/DMARC del dominio.
- [ ] Copias de seguridad probadas con restauración real (no cubierto en esta auditoría) **y que incluyan los comprobantes** (B12).
- [ ] CI con `tests/run-all.sh` (R8).

## 10. Conclusión

La aplicación está **bien construida**: arquitectura clara por capas, consultas preparadas, escape de salida sistemático, CSP sin `unsafe-inline`, bloqueos transaccionales correctos, aislamiento por usuario y por espacio verificado con ataques reales, y una cobertura de pruebas poco común en un proyecto de este tamaño. Lo que impide llamarla «lista para internet» no son fallos de programación, sino **tres decisiones de producto/operación**: la cuenta admin sembrada (C1), la ausencia de verificación de correo con invitaciones por correo (A1/A2) y el cuidado de la imagen de producción (M2/B1). Con las 5-6 primeras tareas del plan (menos de dos días de trabajo) queda en un nivel de seguridad adecuado para un lanzamiento controlado.
