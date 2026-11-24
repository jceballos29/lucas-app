# Plan de migración por fases

Cada fase termina en algo que funciona y se prueba en el navegador y con pruebas automáticas. Marca `[x]` solo cuando pase.

| Fase | Resultado | Estado |
|---|---|---|
| 0 | Esqueleto Docker/PHP funcionando | [x] |
| UI | Migración de la interfaz (maqueta PHP sin funcionalidad, incl. registro y dashboard de admin) | [x] |
| 1 | Registro, login/logout, sesión con roles, seed con admin | [ ] |
| 2 | Dashboard de administración | [ ] |
| 3 | Cuentas y categorías | [ ] |
| 4 | Movimientos | [ ] |
| 5 | Presupuestos y metas de ahorro | [ ] |
| 6 | Panel y reportes | [ ] |
| 7 | Perfil, recuperación de contraseña, endurecimiento | [ ] |

## Fase 0 · Esqueleto ✅
Docker (nginx, php-fpm, MySQL, Adminer), conexión PDO, sesión/CSRF/origen, vistas, pruebas unitarias, usuarios de BD con mínimo privilegio.

## UI · Interfaz migrada ✅
CSS/JS en `src/public/`, layouts (`auth`, `app`, `admin`), 7 vistas de usuario, `registro`, `recuperar`, y el dashboard de administración (`admin/index`, `admin/usuarios`) con datos de ejemplo servidos por `Controller\PreviewController` (temporal: cada método se reemplaza por su controlador real en su fase y la clase se borra al final).

## Fase 1 · Registro, acceso y primera seed
1. **BD:** añadir `rol` y `activo` a `usuarios` en `01-schema.sql`; crear `02-seed.sql` con categorías predeterminadas y el admin inicial (`docs/BASE-DE-DATOS.md`).
2. **Dominio y datos:** `Domain\Usuario`, `Repository\UsuarioRepository` (`findByEmail`, `create`, `countActiveAdmins`...), `Container::usuarios()`.
3. **Validación:** `Validation\RegistroValidator` (nombre 2–80, correo válido ≤120 y único, contraseña ≥ 8 y confirmada).
4. **Autenticación:** `Auth\Autenticador::authenticate()` con `password_verify`, rehash, rechazo de `activo = 0`, mismo mensaje si el correo no existe; limitación de intentos (RNF-24).
5. **Sesión:** guardar `user_role`; añadir `require_role()`; `log_in_user()` ya regenera id y token CSRF.
6. **Páginas y vistas:** `registro.php`, `login.php`, `logout.php`, `index.php` (redirección por rol) y `views/auth/{login,registro}.php` con el diseño de la maqueta (`login.html`).
7. **Pruebas:** unitarias del validador y la autenticación; integración del repositorio; humo: registrarse → entrar → salir, admin entra, usuario inactivo no entra, el rol del formulario se ignora.

**Listo cuando:** `admin@lucas.local` entra tras `docker compose up` con volumen vacío, cualquiera puede registrarse y queda con rol `usuario`.

## Fase 2 · Dashboard de administración
1. `Repository\AdminStatsRepository`: totales de usuarios/activos/nuevos del mes, cuentas, movimientos; registros por mes.
2. `Controller\AdminController`: `dashboard()`, `usuarios()`, `cambiarEstado()`, `cambiarRol()`, protegidos con `require_role('admin')`.
3. Reglas RF-33 (no auto-desactivarse, no quedar sin admins) **dentro de una transacción** con `SELECT ... FOR UPDATE`.
4. Vistas `views/admin/{index,usuarios}.php` con menú propio; `admin/categorias.php` para las predeterminadas (RF-34).
5. Pruebas: un `usuario` recibe 403 en `admin/*`; el admin no puede quedarse sin admins; un usuario desactivado pierde la sesión en su siguiente petición; el dashboard no expone movimientos (RNF-21).

## Fase 3 · Cuentas y categorías
`CuentaRepository` (saldo calculado), `CategoriaRepository` (predeterminadas + propias), páginas `cuentas.php`, `cuenta-form.php`, `categorias.php`. Desactivar en vez de borrar cuentas con movimientos.

## Fase 4 · Movimientos
`MovimientoRepository` con filtros (fecha, cuenta, categoría, tipo) y paginación de 20 (RNF-09), formulario con categorías filtradas por tipo, eliminar con confirmación (`data-confirm`), exportación CSV puede esperar a la fase 6. Comprobar que cuenta y categoría pertenecen al usuario.

## Fase 5 · Presupuestos y metas
Presupuesto único por (usuario, categoría, mes, año); barra de progreso y avisos al 80 % y 100 % (RF-17). Metas con aportes desde una cuenta: el aporte y el cambio de estado a `lograda` van en una transacción.

## Fase 6 · Panel y reportes
Panel del mes (ingresos, gastos, balance, saldo total), gráficos por categoría y evolución mensual, exportación CSV (RF-21 a RF-23).

## Fase 7 · Cierre
Perfil y cambio de contraseña, recuperación por correo (decidir servicio de correo), eliminar cuenta y datos (RNF-20), HTTPS y `APP_ENV=production`, copias de seguridad semanales (RNF-15), revisión de accesibilidad (RNF-13).

## Pruebas (todas las fases)
- `docker compose exec php php tests/unit.php` — lógica pura.
- `bin/as-migrator php tests/integration.php` — repositorios contra una BD temporal.
- `tests/smoke.sh` — punta a punta con `curl` (flujos, CSRF, permisos, 403/404).
- `tests/run-all.sh` — todo lo anterior; se ejecuta antes de dar una fase por terminada.
