<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// U10: «Seguir conectado». Con una sesión válida, solo la renueva (require_login() actualiza la marca de actividad) y responde 204 sin cuerpo;
// sin ella, redirige al login como cualquier página protegida (app.js lo interpreta como «caducó»).
require_login();
http_response_code(204);
exit;
