# Plan de migración por fases

Cada fase termina en algo que funciona y se prueba en el navegador y con pruebas automáticas. Marca `[x]` solo cuando pase.

| Fase | Resultado | Estado |
|---|---|---|
| 0 | Esqueleto Docker/PHP funcionando | [x] |
| UI | Migración de la interfaz (maqueta PHP sin funcionalidad, incl. registro y dashboard de admin) | [x] |
| 1 | Infraestructura de pruebas, registro, login/logout, sesión con roles, seed con admin | [x] |
| 2 | Dashboard de administración (métricas y usuarios) | [x] |
| 3 | Cuentas y categorías (incl. categorías predeterminadas del admin) | [x] |
| 4 | Movimientos | [x] |
| 5 | Presupuestos y metas de ahorro | [ ] |
| 6 | Panel y reportes | [ ] |
| 7 | Perfil, recuperación de contraseña, endurecimiento | [ ] |

## Decisiones tomadas

Cambiar cualquiera de ellas obliga a revisar las fases indicadas.

| # | Decisión | Fases |
|---|---|---|
| D1 | **Limitación de intentos (RNF-24):** tabla `intentos_acceso` (`clave`, `tipo` login/registro, `creado_en`) con índice por (`clave`, `creado_en`); se cuenta por IP y por correo en una ventana de 15 min (máx. 5 por correo, 20 por IP). Se añade a `01-schema.sql` y a `APP_TABLES` de `03-users.sh`. | 1 |
| D2 | **Registro y enumeración de correos:** el **login** responde igual exista o no el correo. El **registro** sí dice «correo ya registrado» (sin correo transaccional no hay alternativa); queda mitigado por la limitación de intentos y se ajusta la redacción de RNF-24. | 1 |
| D3 | **Tras registrarse** el usuario entra directo al panel (RF-26): se llama a `log_in_user()`. | 1 |
| D4 | **Borrar categorías (RF-09, RF-34):** si tienen movimientos o presupuestos se **rechaza** con un mensaje claro; no se borra en silencio ni se reasigna. Las cuentas con movimientos se desactivan (RF-06). | 3 |
| D5 | **Eliminar cuenta de usuario (RNF-20):** en una sola transacción, en orden explícito (aportes → movimientos → presupuestos → metas → cuentas → categorías → usuario). No se confía en el orden de los `ON DELETE CASCADE`, porque `transacciones` y `aportes_meta` referencian `cuentas` sin cascada. | 7 |
| D6 | **BD de pruebas:** `lucas_migrator` recibe `ALL` sobre `lucas\_test%`; `integration.php` crea una base temporal con ese prefijo, aplica `01-schema.sql` y la borra al terminar. Nunca toca `lucas`. | 1 |
| D7 | **Servicio de correo** (RF-04): se elige al empezar la Fase 5 (SMTP propio, Mailpit en local); se implementa en la Fase 7. | 5, 7 |
| D8 | **Reglas de dinero:** el `tipo` del movimiento debe coincidir con el de su categoría; una cuenta inactiva no acepta movimientos ni aportes; un aporte no puede superar el saldo de la cuenta; los presupuestos solo admiten categorías de gasto. Todo se valida en el servidor. | 4, 5 |

## Fase 0 · Esqueleto ✅
Docker (nginx, php-fpm, MySQL, Adminer), conexión PDO, sesión/CSRF/origen, vistas, pruebas unitarias (`src/tests/unit.php`), usuarios de BD con mínimo privilegio.

## UI · Interfaz migrada ✅
CSS/JS en `src/public/`, layouts (`auth`, `app`, `admin`), 7 vistas de usuario (`views/app/`), `registro`, `recuperar`, y el dashboard de administración (`admin/index`, `admin/usuarios`) con datos de ejemplo servidos por `Controller\PreviewController` (temporal: cada método se reemplaza por su controlador real en su fase y la clase se borra al final).

**Vistas que faltan** (se crean en la fase que las usa, con el mismo sistema de diseño): `cuenta-form` y `categorias` (Fase 3), `admin/categorias` (Fase 3), formularios de presupuesto y de meta/aporte (Fase 5), `perfil` (Fase 7).

## Fase 1 · Pruebas, registro, acceso y primera seed ✅
1. **Infraestructura de pruebas** (antes de escribir lógica): `src/tests/integration.php` (BD temporal, D6), `tests/smoke.sh` (curl), `tests/run-all.sh`; ajustar `03-users.sh` para el permiso `lucas\_test%`. Los scripts de humo usan una IP/correo propios o reinician `intentos_acceso` para no chocar con la limitación.
2. **BD:** `rol` y `activo` ya están en `01-schema.sql`. Añadir `intentos_acceso` (D1); crear `02-seed.sql` con las categorías predeterminadas y el admin inicial (`docs/BASE-DE-DATOS.md`); actualizar «7 tablas» → 8 en la documentación y `APP_TABLES`. Corregir en `BASE-DE-DATOS.md` que la seed es segura por ejecutarse una sola vez (`categorias` no tiene clave única).
3. **Dominio y datos:** `Domain\Usuario`, `Repository\UsuarioRepository` (`findByEmail`, `findById`, `create`, `countActiveAdmins`...), `Repository\IntentoRepository`, `Container::usuarios()`.
4. **Validación:** `Validation\RegistroValidator` (nombre 2–80, correo válido ≤120, contraseña de 8 a 72 bytes —el límite de bcrypt— y confirmada). Es puro, como exige la arquitectura: la **unicidad** del correo la comprueba el controlador y, ante dos registros simultáneos, la clave `UNIQUE` (`create()` devuelve `null`).
5. **Autenticación:** `Auth\Autenticador::authenticate()` con `password_verify`, rehash, rechazo de `activo = 0`, mismo mensaje y mismo coste si el correo no existe; limitación de intentos en login **y** registro (D1, D2, RNF-24).
6. **Sesión:** `log_in_user()` guarda `user_role`. `resolve_user_session()` vuelve a leer `activo` y `rol` de la BD **en cada petición** (una consulta por clave primaria): un usuario desactivado o con otro rol lo nota de inmediato. Añadir `require_role()` (403 si el rol no coincide).
7. **Páginas y vistas:** `registro.php` (entra al panel, D3), `login.php`, `logout.php`, `index.php` (redirección por rol) y `views/auth/{login,registro}.php`. Las páginas de maqueta pasan a exigir `require_login()` y las de `admin/` `require_role('admin')`, aunque sigan con datos de ejemplo.
8. **Admin por consola:** `scripts/create-admin.php` (RNF-23), para entornos que no sean local.
9. **Pruebas:** unitarias del validador y la autenticación; integración del repositorio y de la limitación de intentos; humo: registrarse → entra al panel → salir, admin entra, usuario inactivo no entra, el rol del formulario se ignora, un `usuario` recibe 403 en `admin/*` (RF-27), sin sesión se redirige a login, un usuario desactivado a mitad de sesión es expulsado.

Notas de la implementación: la IP para limitar es `REMOTE_ADDR` (no se confía en `X-Forwarded-For`; tras un proxy habrá que configurarlo); un usuario desactivado ve el mismo mensaje genérico que una clave incorrecta (no se revela el estado de la cuenta); `docker-compose.yml` monta `database/` (solo lectura) en el contenedor php para que las pruebas de integración creen su BD temporal.

**Listo cuando:** `admin@lucas.local` entra tras `docker compose up` con volumen vacío, cualquiera puede registrarse y queda con rol `usuario`, y `tests/run-all.sh` pasa.

## Fase 2 · Dashboard de administración ✅
1. `Repository\AdminStatsRepository`: totales de usuarios/activos/nuevos del mes, cuentas, movimientos; registros por mes (últimos 12).
2. `Controller\AdminController`: `dashboard()`, `usuarios()` (búsqueda por nombre/correo, 20 por página), `cambiarEstado()`, `cambiarRol()`, protegidos con `require_role('admin')`.
3. Reglas RF-33 (no auto-desactivarse ni quitarse el rol, no quedar sin admins) **dentro de una transacción**: `SELECT id_usuario FROM usuarios WHERE rol='admin' AND activo=1 FOR UPDATE` para bloquear a los admins activos antes de decidir.
4. Vistas `views/admin/{index,usuarios}.php` con menú propio (sustituyen a `PreviewController`).
5. Notas de la implementación: las reglas puras viven en `Admin\ReglasAdmin` y la transacción en `Admin\GestionUsuarios`; quien actúa debe seguir siendo admin **activo** dentro de la transacción (si otro admin lo desactivó mientras tanto, se rechaza con 403); los avisos usan `Http\Flash` (PRG); `ULTIMO_ADMIN` es una red de seguridad que las reglas anteriores hacen inalcanzable, y una prueba de propiedad comprueba que tras cualquier acción permitida queda un admin activo.
6. Pruebas: el admin no puede quedarse sin admins (el bloqueo `FOR UPDATE` se prueba con dos conexiones reales); los cambios de estado/rol exigen POST + CSRF (RNF-22); el dashboard no expone movimientos ni saldos (RNF-21).

## Fase 3 · Cuentas y categorías ✅
1. `CuentaRepository` (saldo calculado: `saldo_inicial + ingresos − gastos − aportes`), `CategoriaRepository` (predeterminadas + propias), páginas `cuentas.php`, `cuenta-form.php`, `categorias.php`. Desactivar en vez de borrar cuentas con movimientos.
2. Borrado de categorías según D4; mensaje claro, sin detalle técnico (RNF-12).
3. **RF-34:** `admin/categorias.php` (vista nueva) sobre las categorías con `id_usuario IS NULL`, con `require_role('admin')` y las mismas reglas de borrado.
4. Pruebas: una cuenta/categoría ajena da 404 (RNF-04); no se borra una categoría en uso; un `usuario` no edita predeterminadas; el saldo coincide con la fórmula.

Notas de la implementación:
- **Las cuentas no se borran desde la interfaz**: solo se desactivan y reactivan (RF-06), siempre, tengan o no movimientos. Así el historial nunca queda huérfano; el borrado real queda para eliminar la cuenta de usuario (D5, Fase 7). Una cuenta inactiva no cuenta en el saldo total (RF-07) pero su saldo se sigue calculando.
- **El tipo de una categoría no se edita** (solo nombre y color): los movimientos ya guardados dependen de él (D8). Una categoría propia no puede repetir el nombre de una predeterminada del mismo tipo (sin distinguir mayúsculas ni tildes).
- **D4 se garantiza con la clave foránea**, no con una comprobación previa: `DELETE` falla con `23000` si hay movimientos o presupuestos y se responde con el aviso; así también resiste dos peticiones a la vez. Para el administrador el aviso no revela de quién son los movimientos (RNF-21).
- **Permisos:** una categoría de otro usuario o una cuenta ajena dan 404; una categoría predeterminada que un usuario intenta modificar da 403; `admin/categorias.php` solo ve y toca las predeterminadas.
- **Dinero:** nace `Support\Dinero` (montos como cadenas decimales, centavos enteros para sumar, formato colombiano `1.250.000,50`); el saldo total se suma en centavos, nunca con `float`. El saldo inicial solo puede ser negativo en una tarjeta de crédito.
- **Error corregido de la Fase 1:** la seed guardaba «Alimentación» como «AlimentaciÃ³n» porque el cliente `mysql` de la imagen arranca en `latin1`. `02-seed.sql` ahora empieza con `SET NAMES utf8mb4`, y el humo comprueba la codificación sobre la base real (la prueba de integración no lo veía porque carga la seed por PDO, que ya usa `utf8mb4`).

## Fase 4 · Movimientos ✅
`MovimientoRepository` con filtros (fecha, cuenta, categoría, tipo) y paginación de 20 (RNF-09), formulario con categorías filtradas por tipo, eliminar con confirmación (`data-confirm`). Aplicar D8: comprobar en el servidor que cuenta y categoría pertenecen al usuario, que el tipo coincide, que la cuenta está activa y que el monto es positivo con ≤ 2 decimales (cadenas, nunca `float`). Alta/edición/borrado y efecto en saldo en una transacción (RF-14). Pruebas: IDs ajenos → 404; tipo discordante → error de validación.

Notas de la implementación:
- **Capas:** `MovimientoValidator` (puro: monto > 0 con ≤ 2 decimales, fecha real entre 2000 y 2100, descripción ≤ 150), `Movimientos\ReglasMovimiento` (D8, pura), `Movimientos\GestionMovimientos` (la transacción), `Movimientos\Filtros` (limpia lo que llega en la URL) y `MovimientoRepository`. Nace `Database\Transaction` como asistente común (lo usan también las reglas de administración).
- **D8 dentro de la transacción:** se bloquea la cuenta (`SELECT … FOR UPDATE`) y con lo leído ahí se comprueba que la cuenta y la categoría sean del usuario (la categoría puede ser predeterminada), que el tipo coincida y que la cuenta esté activa; solo entonces se escribe. Una cuenta que se desactiva a la vez no recibe movimientos «a escondidas» (probado con dos conexiones reales).
- **404 frente a error de validación:** una cuenta, categoría o movimiento ajeno (o inexistente) responde 404 y no revela que existe; un tipo que no coincide con la categoría o una cuenta inactiva dan un error en el formulario.
- **Cuenta inactiva:** no acepta altas ni ediciones (el formulario le pide al usuario activarla u elegir otra), pero sus movimientos se pueden eliminar.
- **RF-14:** el saldo no se guarda, se calcula, así que alta, edición y borrado lo actualizan solos; las pruebas lo verifican al centavo, también al mover un gasto de una cuenta a otra.
- **Listado:** 20 por página, filtros por fecha (incluye ambos días), cuenta, categoría y tipo, resumen de ingresos, gastos y balance de TODO lo filtrado (no solo de la página). Los filtros inválidos se ignoran. Tras eliminar se vuelve a la misma página y filtros, aceptando solo parámetros conocidos. La paginación muestra una ventana (primera, última y vecinas) en vez de cientos de enlaces.
- **Formulario:** el servidor pone todas las categorías en el `<select>` con su `data-tipo` y el JS deja solo las del tipo elegido (sin JS se ven todas y el servidor rechaza la que no corresponda). El monto se formatea al escribir (`1.250.000,50`).
- El botón «Exportar CSV» queda deshabilitado hasta la Fase 6.

## Fase 5 · Presupuestos y metas
Al empezar, decidir el servicio de correo (D7). Vistas nuevas: formularios de presupuesto y de meta/aporte.
- Presupuesto único por (usuario, categoría, mes, año), solo categorías de gasto; barra de progreso (RF-16). **Avisos (RF-17):** al 80 % y al superar el 100 % se muestran como aviso en `presupuestos.php` y en el panel, y como `notice` tras guardar un gasto que cruce el umbral; se calculan al leer, no se guardan.
- Metas con aportes desde una cuenta activa con saldo suficiente (D8): el aporte y el cambio de estado a `lograda` van en una transacción. Pruebas: aporte que supera el saldo rechazado; meta que alcanza el objetivo pasa a `lograda`.

## Fase 6 · Panel y reportes
Panel del mes (ingresos, gastos, balance, saldo total), gráficos por categoría y evolución mensual con SVG/CSS propios (sin CDN, por la CSP), exportación CSV (RF-21 a RF-23). El CSV **neutraliza la inyección de fórmulas** (prefijo `'` en celdas que empiezan con `=`, `+`, `-`, `@`) y solo exporta los datos del usuario de la sesión.

## Fase 7 · Cierre
- `perfil.php` (vista nueva): nombre, moneda, cambio de contraseña (RF-03).
- Recuperación por correo (RF-04) con el servicio elegido en D7: token de un solo uso, hash en BD, caducidad corta, misma respuesta exista o no el correo.
- Eliminar cuenta y datos (RNF-20) según D5, con prueba de que no quedan filas del usuario en ninguna tabla.
- HTTPS y `APP_ENV=production` (RNF-07), copias de seguridad semanales (RNF-15), revisión de accesibilidad (RNF-13).
- **Verificación transversal** (hasta ahora sin dueño): tiempos de carga (RNF-08), prueba de carga ligera hacia 500 usuarios simultáneos (RNF-19), navegadores soportados (RNF-17) y plan de disponibilidad (RNF-14).
- Borrar `PreviewController` y comprobar que no quedan rutas de maqueta.

## Pruebas (todas las fases)
- `docker compose exec php php tests/unit.php` — lógica pura (`src/tests/unit.php`).
- `bin/as-migrator php tests/integration.php` — repositorios contra una BD temporal `lucas_test_*` (D6); se crea en la Fase 1.
- `tests/smoke.sh` — punta a punta con `curl` desde la raíz (flujos, CSRF, permisos, 403/404); se crea en la Fase 1.
- `tests/run-all.sh` — todo lo anterior; se ejecuta antes de dar una fase por terminada.
