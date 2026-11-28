<?php

declare(strict_types=1);

/**
 * Token CSRF (M6). CSRF (Cross-Site Request Forgery): el navegador adjunta la cookie de sesión a CUALQUIER
 * petición hacia la aplicación, también a una que origina otra página (un formulario oculto que se envía
 * solo). El servidor no puede distinguirla de una legítima mirando solo la cookie. La defensa es pedir algo
 * que la página ajena no pueda conocer: un valor secreto al azar, guardado en la sesión del servidor y puesto
 * por la aplicación en cada formulario. La página atacante puede ENVIAR un formulario a nuestra dirección,
 * pero no puede LEER nuestras páginas (la política del mismo origen del navegador se lo impide), así que no
 * sabe el token.
 *
 * Es un token POR SESIÓN (no por petición): sirve para varias pestañas y para el botón «atrás». Se renueva
 * al iniciar sesión (log_in_user() reemplaza $_SESSION) y desaparece al cerrarla.
 *
 * Capas, de fuera adentro: comprobación de Origin/Referer (app/Security/request-guard.php), SameSite=Lax en la cookie
 * (app/Security/session.php) y este token. Ninguna sustituye a las otras.
 */

/** Nombre del campo oculto de los formularios. */
const CSRF_FIELD = 'csrf_token';

/** Bytes al azar del token (64 caracteres hexadecimales). */
const CSRF_TOKEN_BYTES = 32;

/**
 * El token de la sesión actual; lo crea si aún no existe (random_bytes es un generador criptográfico).
 * Inicia la sesión si hace falta: así lo puede usar también el formulario de login, donde todavía no hay
 * ningún usuario, pero sí una sesión anónima que guarda el token.
 */
function csrf_token(): string
{
    start_app_session();

    if (!isset($_SESSION[CSRF_FIELD]) || !is_string($_SESSION[CSRF_FIELD])) {
        $_SESSION[CSRF_FIELD] = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
    }

    return $_SESSION[CSRF_FIELD];
}

/**
 * ¿Es válido el token recibido?
 *
 * Se compara con hash_equals() y no con === : === se detiene en el primer carácter distinto, así que cuánto
 * tarda depende de cuántos caracteres acertaste, y midiendo tiempos un atacante podría ir adivinando el token
 * carácter a carácter (ataque de temporización). hash_equals() tarda lo mismo acierte lo que acierte.
 * (Con la red de por medio es muy difícil de explotar, pero comparar secretos con === es un mal hábito y
 * arreglarlo cuesta una función.) Exige que ambos sean texto: hash_equals() lanza un error con otros tipos.
 *
 * @param mixed $submitted Lo que llegó en $_POST['csrf_token'] (o null si no llegó).
 */
function csrf_is_valid(mixed $submitted): bool
{
    if (!is_string($submitted) || $submitted === '') {
        return false;
    }

    // Sin sesión (o sin token en ella) no hay con qué comparar; no se crea uno aquí: un token recién creado
    // jamás podría coincidir con el que trae la petición.
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[session_cookie_name()])) {
        return false;
    }

    start_app_session();
    $expected = $_SESSION[CSRF_FIELD] ?? null;

    return is_string($expected) && $expected !== '' && hash_equals($expected, $submitted);
}

/**
 * Corta la petición con un 403 si el token no es válido. Se llama al inicio de todo procesamiento POST,
 * DESPUÉS de comprobar el origen y la sesión (primero «¿quién pide?») y ANTES de mirar lo que se envía
 * (id, campos): una petición sin token válido no recibe ni siquiera la validación.
 */
function require_csrf(): void
{
    if (csrf_is_valid($_POST[CSRF_FIELD] ?? null)) {
        return;
    }

    // E4: si el cuerpo superó `post_max_size`, PHP descarta todo el POST y el token «desaparece»: el motivo real es el tamaño, no la seguridad.
    if (\App\Http\Peticion::cuerpoExcedido()) {
        \App\Http\Response::plain(413, "El archivo es demasiado grande: el máximo para un comprobante es de 5 MB.\n", ['Cache-Control: no-store']);
    }

    \App\Http\Response::plain(403, "Token de seguridad no válido o caducado. Vuelve a la página anterior, recárgala e inténtalo de nuevo.\n", ['Cache-Control: no-store']);
}

/** El campo oculto con el token, listo para pintar dentro de un <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_FIELD . '" value="' . escape(csrf_token()) . '">';
}
