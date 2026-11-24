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

/** Nombre de la cookie de sesión (no el PHPSESSID por defecto, que delata la tecnología). */
const SESSION_NAME = 'lucas_session';

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
    if (!is_int($lastSeen)) {
        return true;
    }

    // Una marca del futuro (el reloj retrocedió) tampoco es de fiar.
    return $lastSeen > $now || $now - $lastSeen >= $ttl;
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

    session_name(SESSION_NAME);
    session_set_cookie_params(session_cookie_options(app_uses_https()));
    session_start();
}

/**
 * Estado de la sesión: el usuario conectado (o null) y si acaba de caducar por inactividad.
 * Renueva la marca de actividad si la sesión sigue viva.
 *
 * @return array{user: array{id: int, name: string, email: string}|null, expired: bool}
 */
function resolve_user_session(): array
{
    // Sin la cookie de sesión no hay sesión que consultar: no se inicia ninguna (cada visita anónima crearía
    // un archivo de sesión en el servidor). La sesión se crea al iniciar sesión (log_in_user()).
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[SESSION_NAME])) {
        return ['user' => null, 'expired' => false];
    }

    start_app_session();

    if (!isset($_SESSION['user_id'])) {
        return ['user' => null, 'expired' => false];
    }

    if (session_is_expired($_SESSION['last_seen'] ?? null, time())) {
        log_out_user();

        return ['user' => null, 'expired' => true];
    }

    $_SESSION['last_seen'] = time();

    return ['user' => ['id' => (int) $_SESSION['user_id'], 'name' => (string) ($_SESSION['user_name'] ?? ''), 'email' => (string) ($_SESSION['user_email'] ?? '')], 'expired' => false];
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
 * @param array{id: int, name: string, email: string} $user
 */
function log_in_user(array $user): void
{
    start_app_session();
    session_regenerate_id(true);
    // Se REEMPLAZA todo $_SESSION: también se descarta el token CSRF de la sesión anónima, y el primer formulario
    // de la sesión ya autenticada recibe uno nuevo (el que conociera quien vio la página de login no sirve).
    $_SESSION = ['user_id' => $user['id'], 'user_name' => $user['name'], 'user_email' => $user['email'], 'last_seen' => time()];
}

/** Cierra la sesión: vacía los datos, borra la cookie del navegador y destruye el archivo del servidor. */
function log_out_user(): void
{
    start_app_session();
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
 * @return array{id: int, name: string, email: string}
 */
function require_login(): array
{
    header('Cache-Control: no-store');

    ['user' => $user, 'expired' => $expired] = resolve_user_session();

    if ($user === null) {
        header('Location: login.php' . ($expired ? '?msg=expired' : ''));
        exit;
    }

    return $user;
}
