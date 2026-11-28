<?php

declare(strict_types=1);

/**
 * Sesión de usuario. El servidor guarda QUIÉN eres en $_SESSION (un archivo en el contenedor
 * de PHP, en /tmp: se pierde si se recrea el contenedor y todos tienen que volver a entrar); el navegador
 * solo lleva una cookie con un identificador al azar (el id de sesión), que por sí solo no dice nada.
 *
 * Las funciones puras (opciones de la cookie, caducidad) se prueban en src/tests/unit.php; el resto toca
 * la sesión de PHP y las cabeceras, y se prueba de punta a punta (tests/smoke.sh).
 */

/** Nombre base de la cookie de sesión (no el PHPSESSID por defecto, que delata la tecnología). */
const SESSION_NAME = 'lucas_session';

/**
 * B7: con HTTPS la cookie se llama `__Host-lucas_session`. El prefijo `__Host-` hace que el navegador solo la acepte si es `Secure`, de
 * `Path=/` y SIN `Domain`: un subdominio o un sitio hermano no puede crearla ni pisarla. En HTTP (desarrollo) no es posible y se usa el nombre base.
 */
function session_cookie_name(): string
{
    return app_uses_https() ? '__Host-' . SESSION_NAME : SESSION_NAME;
}

/** Segundos de INACTIVIDAD tras los que la sesión caduca (30 minutos). Cada petición lo renueva. */
const SESSION_IDLE_TIMEOUT = 1800;

/**
 * Opciones de la cookie de sesión.
 * - httponly: JavaScript no puede leerla (document.cookie), así que un XSS no se la lleva.
 * - samesite=Lax: el navegador no la envía en peticiones POST que vienen de OTRO sitio (ni en
 *   subpeticiones como imágenes o iframes), solo cuando se llega por un enlace normal (GET). Es una capa
 *   contra CSRF, no la sustituye (M6): Lax no protege un GET que cambie datos ni a un sitio hermano.
 * - secure: solo se envía por HTTPS. Con http://localhost no puede activarse (el navegador no la
 *   guardaría), por eso depende de APP_ORIGIN.
 * - lifetime 0: cookie de sesión, desaparece al cerrar el navegador (además de la caducidad por inactividad).
 *
 * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
 */
function session_cookie_options(bool $secure): array
{
    return ['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'];
}

/** ¿La aplicación se sirve por HTTPS? Según APP_ORIGIN; si falta o no es válido, false. */
function app_uses_https(): bool
{
    try {
        return str_starts_with(strtolower(\App\Config\Environment::get('APP_ORIGIN')), 'https://');
    } catch (RuntimeException) {
        return false;
    }
}

/**
 * ¿Caducó la sesión por inactividad?
 *
 * @param mixed $lastSeen Marca de tiempo de la última petición guardada en la sesión (si no es un entero, se
 *                        considera caducada: una sesión sin marca no es de fiar).
 * @param int $now Ahora (time()).
 * @param int $ttl Segundos de inactividad permitidos.
 */
function session_is_expired(mixed $lastSeen, int $now, int $ttl = SESSION_IDLE_TIMEOUT): bool
{
    return \App\Auth\ContextoSesion::haCaducado($lastSeen, $now, $ttl);
}

/** Inicia la sesión de PHP con la configuración segura de la aplicación. Se puede llamar varias veces. */
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // strict_mode: PHP no acepta un id de sesión que él no haya creado (si no, un atacante podría
    // «regalarle» un id conocido a la víctima: fijación de sesión). only_cookies: nada de ?PHPSESSID= en la URL.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    // El recolector de basura borra los archivos de sesión sin tocar durante gc_maxlifetime (por defecto 24
    // minutos): tiene que ser MÁS que la caducidad por inactividad o la sesión desaparecería antes.
    ini_set('session.gc_maxlifetime', (string) (SESSION_IDLE_TIMEOUT * 2));

    // Sin esto PHP añade sus propias cabeceras de caché (no-store, no-cache, must-revalidate, Expires,
    // Pragma) y pisa las que manda la aplicación: así el comportamiento depende solo de nuestras cabeceras.
    session_cache_limiter('');

    session_name(session_cookie_name());
    session_set_cookie_params(session_cookie_options(app_uses_https()));
    session_start();
}

/**
 * Estado de la sesión: el usuario conectado (o null), si acaba de caducar por inactividad y si se le revocó el acceso.
 * Renueva la marca de actividad si la sesión sigue viva.
 *
 * En CADA petición vuelve a leer al usuario de la BD (una consulta por clave primaria): si un administrador lo
 * desactivó (RF-31) la sesión se cierra en su siguiente petición, y si le cambió el rol (RF-32) el cambio vale
 * de inmediato, sin esperar a que vuelva a entrar. El rol de la sesión es solo una copia para mostrar.
 *
 * @return array{user: array{id: int, name: string, email: string, role: string}|null, expired: bool, revoked: bool}
 * @throws RuntimeException Si no se puede consultar la BD (lo traduce require_login()).
 */
function resolve_user_session(): array
{
    // Sin la cookie de sesión no hay sesión que consultar: no se inicia ninguna (cada visita anónima crearía
    // un archivo de sesión en el servidor). La sesión se crea al iniciar sesión (log_in_user()).
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[session_cookie_name()])) {
        return ['user' => null, 'expired' => false, 'revoked' => false];
    }

    start_app_session();

    // Una visita sin usuario (la página de login tiene sesión por el token CSRF) no toca la base de datos: el contexto, que abre la
    // conexión al construir sus repositorios, solo se crea cuando hay un usuario que comprobar.
    if (!isset($_SESSION['user_id'])) {
        return ['user' => null, 'expired' => false, 'revoked' => false];
    }

    $sid = (string) session_id();
    $estado = session_context()->resolver($_SESSION, $sid !== '' ? \App\Repository\SesionRepository::hashId($sid) : '', time());

    if ($estado->cerrar()) {
        log_out_user();
    } elseif ($estado->monedaActiva !== null) {
        // Los montos de toda la petición se muestran en la moneda del usuario o del espacio compartido activo (RF-03, Fase 10).
        \App\Support\Dinero::usarMoneda($estado->monedaActiva);
    }

    return $estado->comoArreglo();
}

/**
 * El contexto de sesión de esta petición: la lógica de «quién está conectado» ({@see \App\Auth\ContextoSesion}) con los repositorios del
 * contenedor. Es el ÚNICO sitio de este archivo que conoce `Container`; las pruebas pueden poner otro con {@see use_session_context()}.
 */
function session_context(): \App\Auth\ContextoSesion
{
    return $GLOBALS['__lucas_contexto_sesion'] ??= (static function (): \App\Auth\ContextoSesion {
        $c = \App\Container::shared();

        return new \App\Auth\ContextoSesion($c->usuarios(), $c->sesiones(), $c->espacios(), SESSION_IDLE_TIMEOUT);
    })();
}

/** Sustituye el contexto de sesión (pruebas); `null` vuelve al del contenedor. */
function use_session_context(?\App\Auth\ContextoSesion $contexto): void
{
    if ($contexto === null) {
        unset($GLOBALS['__lucas_contexto_sesion']);

        return;
    }

    $GLOBALS['__lucas_contexto_sesion'] = $contexto;
}

/** Cambia el espacio activo en la sesión (`null` = volver a Mi espacio personal). */
function set_active_espacio(?int $espacioId): void
{
    start_app_session();
    if ($espacioId === null || $espacioId < 1) {
        unset($_SESSION['espacio_id']);
    } else {
        $_SESSION['espacio_id'] = $espacioId;
    }
}

/**
 * Exige que el usuario tenga permiso de escritura en el espacio activo (`propietario` o `editor`, o espacio personal).
 * Si su rol en el espacio compartido es `lector`, responde 403 y corta.
 *
 * @param array{espacio_rol?: string|null} $user
 */
function require_espacio_write(array $user): void
{
    if (!\App\Espacios\ReglasEspacio::puedeEscribir($user['espacio_rol'] ?? null)) {
        \App\Http\Response::plain(403, "Acceso denegado: tienes rol de solo lectura en este espacio compartido.\n", ['Cache-Control: no-store']);
    }
}

/** Huella corta del hash de una contraseña (no revela el hash): sirve para saber si la contraseña cambió desde que se abrió la sesión. */
function password_fingerprint(string $hash): string
{
    return \App\Auth\ContextoSesion::huella($hash);
}

/** Tras cambiar la contraseña en ESTA sesión, se actualiza su huella para que siga abierta (las demás se cierran). */
function refresh_session_fingerprint(string $hash): void
{
    start_app_session();
    $_SESSION['pw_fp'] = password_fingerprint($hash);
}

/** El usuario conectado, o null. */
function current_user(): ?array
{
    return resolve_user_session()['user'];
}

/**
 * Guarda en la sesión que este usuario se autenticó. Antes cambia el id de sesión
 * (session_regenerate_id(true)): el id que tenía el navegador ANTES de entrar deja de valer y el archivo
 * viejo se borra. Es la defensa contra la fijación de sesión: aunque alguien hubiera conseguido que la
 * víctima use un id que él conoce, ese id no llega a estar autenticado.
 *
 * El rol sale de la BD (lo pasa el controlador desde el Usuario), nunca de la petición (RF-25).
 *
 * @param array{id: int, name: string, email: string, role: string, fp: string} $user `fp`: {@see password_fingerprint()} del hash vigente
 */
function log_in_user(array $user): void
{
    start_app_session();
    session_regenerate_id(true);
    // Se REEMPLAZA todo $_SESSION: también se descarta el token CSRF de la sesión anónima, y el primer formulario
    // de la sesión ya autenticada recibe uno nuevo (el que conociera quien vio la página de login no sirve).
    $_SESSION = ['user_id' => $user['id'], 'user_name' => $user['name'], 'user_email' => $user['email'], 'user_role' => $user['role'], 'pw_fp' => $user['fp'], 'last_seen' => time()];

    $sid = (string) session_id();
    if ($sid !== '') {
        try {
            session_context()->registrarInicio(
                (int) $user['id'],
                \App\Repository\SesionRepository::hashId($sid),
                (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Navegador web'),
            );
        } catch (Throwable) {
            // Si la BD falla aquí, la siguiente petición en resolve_user_session() lo detectará de forma controlada.
        }
    }
}

/** Minutos que se concede para escribir el código del segundo factor tras acertar la contraseña, y fallos tolerados en ese reto. */
const TWO_FACTOR_CHALLENGE_SECONDS = 300;
const TWO_FACTOR_CHALLENGE_MAX_FAILURES = 5;

/**
 * Deja la sesión a medio abrir (M7): la contraseña fue correcta pero falta el segundo factor. NO es una sesión iniciada (no hay `user_id`):
 * ninguna página protegida la acepta. Igual que en {@see log_in_user()}, el id de sesión se renueva y se descarta lo anterior.
 */
function begin_two_factor_challenge(int $userId, string $fingerprint): void
{
    start_app_session();
    session_regenerate_id(true);
    $_SESSION = ['2fa_uid' => $userId, '2fa_fp' => $fingerprint, '2fa_hasta' => time() + TWO_FACTOR_CHALLENGE_SECONDS, '2fa_fallos' => 0, 'last_seen' => time()];
}

/** El reto de segundo factor en curso de ESTA sesión, o null si no hay o caducó. @return array{uid: int, fp: string, fallos: int}|null */
function pending_two_factor(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[session_cookie_name()])) {
        return null;
    }

    start_app_session();

    if (!isset($_SESSION['2fa_uid']) || !is_int($_SESSION['2fa_uid']) || (int) ($_SESSION['2fa_hasta'] ?? 0) < time()) {
        return null;
    }

    return ['uid' => $_SESSION['2fa_uid'], 'fp' => (string) ($_SESSION['2fa_fp'] ?? ''), 'fallos' => (int) ($_SESSION['2fa_fallos'] ?? 0)];
}

function record_two_factor_failure(): int
{
    start_app_session();
    $_SESSION['2fa_fallos'] = (int) ($_SESSION['2fa_fallos'] ?? 0) + 1;

    return (int) $_SESSION['2fa_fallos'];
}

/** Abandona el reto sin iniciar sesión (demasiados fallos, caducó o el usuario volvió atrás). */
function discard_two_factor_challenge(): void
{
    start_app_session();
    unset($_SESSION['2fa_uid'], $_SESSION['2fa_fp'], $_SESSION['2fa_hasta'], $_SESSION['2fa_fallos']);
}

/** Cierra la sesión: vacía los datos, borra la cookie del navegador, elimina el registro en BD y destruye el archivo del servidor. */
function log_out_user(): void
{
    start_app_session();
    $sid = (string) session_id();
    if ($sid !== '' && isset($_SESSION['user_id'])) {
        try {
            session_context()->olvidar(\App\Repository\SesionRepository::hashId($sid));
        } catch (Throwable) {
        }
    }
    $_SESSION = [];

    if (!headers_sent()) {
        $options = session_cookie_options(app_uses_https());
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $options['path'],
            'domain' => $options['domain'],
            'secure' => $options['secure'],
            'httponly' => $options['httponly'],
            'samesite' => $options['samesite'],
        ]);
    }

    session_destroy();
}

/**
 * Exige una sesión iniciada. Va al principio de cada página protegida, ANTES de cualquier salida: sin sesión
 * redirige a login.php (302) y corta; con ella devuelve al usuario.
 *
 * También manda Cache-Control: no-store, para que el navegador no guarde estas páginas: tras cerrar sesión,
 * el botón «atrás» no debe enseñar el panel desde la memoria del navegador.
 *
 * @return array{id: int, name: string, email: string, role: string}
 */
function require_login(): array
{
    header('Cache-Control: no-store');

    // Si la BD falla aquí, la excepción sube al manejador central (App\Http\ManejadorErrores) y responde 503/500.
    ['user' => $user, 'expired' => $expired, 'revoked' => $revoked] = resolve_user_session();

    if ($user === null) {
        $reason = $expired ? '?msg=expired' : ($revoked ? '?msg=revoked' : '');
        header('Location: /login.php' . $reason);
        exit;
    }

    return $user;
}

/**
 * Exige sesión Y un rol concreto, comprobado en el servidor en cada petición (RNF-22): ocultar el menú no basta.
 * Sin sesión redirige a login; con otro rol responde 403.
 *
 * @return array{id: int, name: string, email: string, role: string}
 */
function require_role(string $role): array
{
    $user = require_login();

    if ($user['role'] !== $role) {
        \App\Http\Response::plain(403, "Acceso denegado: no tienes permiso para ver esta página.\n", ['Cache-Control: no-store']);
    }

    // M7: un administrador sin segundo factor no entra a la administración hasta activarlo en Mi perfil (que sí puede abrir).
    $obligatorio = \App\Auth\DosPasos::obligatorioParaAdmin((string) getenv('APP_ENV'), getenv('ADMIN_2FA_OBLIGATORIO') === false ? null : (string) getenv('ADMIN_2FA_OBLIGATORIO'));
    if (\App\Auth\DosPasos::adminDebeActivar($user['role'], (bool) ($user['dos_pasos'] ?? false), $obligatorio)) {
        \App\Http\Flash::set('warn', 'Los administradores deben activar la verificación en dos pasos antes de usar la administración. Actívala aquí abajo.');
        header('Location: /perfil.php#dos-pasos');
        exit;
    }

    return $user;
}
