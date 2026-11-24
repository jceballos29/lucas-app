<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// Provisional: hasta migrar el login real, la portada es la pantalla de inicio de sesión.
// Después: sin sesión → login.php; admin → admin/index.php; usuario → panel.php.
App\Http\Response::redirect('/login.php');
