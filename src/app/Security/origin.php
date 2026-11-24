<?php

declare(strict_types=1);

/**
 * Comprobación del origen de una petición (capa extra contra CSRF, no la sustituye).
 *
 * Funciones puras: reciben texto y devuelven un resultado. No leen $_SERVER ni el
 * entorno; eso lo hace la página que las usa.
 */

/**
 * Reduce una URL a su origen normalizado: esquema://host[:puerto], en minúsculas y sin
 * el puerto por defecto (80 en http, 443 en https). Devuelve null si no es un origen válido.
 *
 * parse_url() es permisivo ("http://localhost:80a" da el puerto 80 y un espacio final se
 * ignora), así que además se exige que el texto original empiece exactamente por
 * esquema://host[:puerto] y que lo que sigue sea lo permitido:
 *  - $originOnly = true (cabecera Origin): nada más. Un Origin real nunca lleva ruta.
 *  - $originOnly = false (Referer, APP_ORIGIN): nada, o una ruta/query/fragmento que se descarta.
 * Se rechazan esquemas que no sean http/https, usuario y contraseña ("host@evil.com") y
 * valores como "null" o "javascript:...".
 *
 * Ejemplos:
 *     normalize_origin('HTTP://LOCALHOST:8080');                 // 'http://localhost:8080'
 *     normalize_origin('https://sitio.com:443');                 // 'https://sitio.com'
 *     normalize_origin('http://localhost:8080/index.php?a=1');   // 'http://localhost:8080'
 *     normalize_origin('http://localhost:8080/x', true);         // null (un Origin no lleva ruta)
 *     normalize_origin('http://localhost:8080.evil.com');        // null
 *     normalize_origin('null');                                  // null
 *
 * @param string $url Origen o URL completa.
 * @param bool $originOnly true para exigir que no haya nada después del puerto.
 * @return string|null El origen normalizado, o null si no es válido.
 */
function normalize_origin(string $url, bool $originOnly = false): ?string
{
    $parts = @parse_url($url);

    if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $port = $parts['port'] ?? null;

    // El texto original debe empezar exactamente por lo que se parseó...
    $prefix = $parts['scheme'] . '://' . $parts['host'] . ($port !== null ? ':' . $port : '');
    if (strncasecmp($url, $prefix, strlen($prefix)) !== 0) {
        return null;
    }

    // ...y lo que queda detrás debe estar permitido.
    $rest = substr($url, strlen($prefix));
    if ($originOnly) {
        if ($rest !== '') {
            return null;
        }
    } elseif ($rest !== '' && !in_array($rest[0], ['/', '?', '#'], true)) {
        return null;
    }

    $defaultPort = $scheme === 'https' ? 443 : 80;
    $portPart = ($port === null || $port === $defaultPort) ? '' : ':' . $port;

    return $scheme . '://' . strtolower($parts['host']) . $portPart;
}

/**
 * Decide si una petición viene del propio sitio, según las cabeceras Origin y Referer.
 *
 * Reglas (para una acción destructiva se falla cerrado):
 *  1. Si llega Origin, decide él solo: debe ser igual al origen esperado. "null" (iframes
 *     aislados, data:, file://) o cualquier valor mal formado cuenta como origen ajeno.
 *  2. Si no llega Origin pero sí Referer, se compara el origen del Referer (sin la ruta).
 *  3. Si no llega ninguna de las dos, se rechaza.
 * La comparación es de igualdad estricta entre orígenes ya normalizados, nunca de prefijo
 * ni de subcadena ("http://localhost:8080.evil.com" empezaría igual).
 *
 * @param string|null $origin Valor de $_SERVER['HTTP_ORIGIN'] (null si no llegó).
 * @param string|null $referer Valor de $_SERVER['HTTP_REFERER'] (null si no llegó).
 * @param string $expectedOrigin Origen del sitio (APP_ORIGIN), p. ej. "http://localhost:8080".
 * @return bool true si la petición viene del propio sitio.
 * @throws UnexpectedValueException Si $expectedOrigin no es un origen válido (error de configuración).
 */
function is_allowed_origin(?string $origin, ?string $referer, string $expectedOrigin): bool
{
    $expected = normalize_origin($expectedOrigin);

    if ($expected === null) {
        throw new UnexpectedValueException('APP_ORIGIN no es un origen válido: ' . $expectedOrigin);
    }

    if ($origin !== null) {
        return normalize_origin($origin, true) === $expected;
    }

    if ($referer !== null) {
        return normalize_origin($referer) === $expected;
    }

    return false;
}
