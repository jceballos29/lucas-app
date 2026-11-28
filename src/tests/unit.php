<?php

declare(strict_types=1);

/**
 * Pruebas unitarias de la lógica pura del esqueleto (escape, origen, sesión, CSRF): sin BD ni servidor web.
 *
 *     docker compose exec php php tests/unit.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/harness.php';

section('escape()');
same('escapa HTML', '&lt;b&gt;&quot;x&quot;&#039;', escape('<b>"x"\''));

section('normalize_origin()');
same('minúsculas', 'http://localhost:8090', normalize_origin('HTTP://LOCALHOST:8090'));
same('descarta la ruta', 'http://localhost:8090', normalize_origin('http://localhost:8090/index.php?a=1'));
same('Origin con ruta no vale', null, normalize_origin('http://localhost:8090/x', true));
same('dominio engañoso', null, normalize_origin('http://localhost:8090.evil.com'));
same('"null" no vale', null, normalize_origin('null'));

section('session_is_expired()');
same('sin marca = caducada', true, session_is_expired(null, 1000));
same('reciente = viva', false, session_is_expired(900, 1000));
same('pasado el límite', true, session_is_expired(1000, 1000 + SESSION_IDLE_TIMEOUT));
same('marca del futuro', true, session_is_expired(2000, 1000));

section('format_utc_datetime()');
same('UTC a Bogotá', '2026-11-19 09:08:36', format_utc_datetime('2026-11-19 14:08:36', 'America/Bogota'));
same('fecha inválida se devuelve igual', 'nada', format_utc_datetime('nada'));

section('Ui: componentes (escape y seguridad)');
same('el texto del botón se escapa', true, str_contains((string) App\View\Ui::button('<b>x</b>'), '&lt;b&gt;x&lt;/b&gt;'));
same('un Html anidado no se escapa dos veces', '<span class="badge badge--neutral"><b>x</b></span>', (string) App\View\Ui::badge(new App\View\Html('<b>x</b>')));
same('un texto plano dentro de listItem se escapa', true, str_contains((string) App\View\Ui::listItem('<i>a</i>'), '&lt;i&gt;a&lt;/i&gt;'));
same('icono desconocido lanza excepción', true, (static function (): bool {
    try {
        App\View\Ui::icon('no-existe');
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
})());
same('variante de botón no válida lanza excepción', true, (static function (): bool {
    try {
        App\View\Ui::button('x', ['variant' => 'rojo']);
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
})());
same('un atributo con nombre malicioso se rechaza', true, (static function (): bool {
    try {
        App\View\Ui::button('x', ['attrs' => ['onclick="x"' => 'y']]);
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
})());
same('el valor de un atributo se escapa', true, str_contains((string) App\View\Ui::button('x', ['attrs' => ['data-a' => '"><script>']]), 'data-a="&quot;&gt;&lt;script&gt;"'));
same('los botones solo con icono llevan nombre accesible', true, str_contains((string) App\View\Ui::iconButton('edit', 'Editar Bus'), 'aria-label="Editar Bus"'));

section('Ui: dinero y progreso');
same('ingreso con signo y miles', '<span class="money money--in">+$1.800.000</span>', (string) App\View\Ui::money(1800000, 'in'));
same('gasto con signo menos tipográfico', '<span class="money money--out">−$18.000</span>', (string) App\View\Ui::money(18000, 'out'));
same('saldo negativo lleva signo', true, str_contains((string) App\View\Ui::money(-500), '−$500'));
same('pesos() da texto plano', '$2.450.000', App\View\Ui::pesos(2450000));
same('tono de presupuesto: en orden', 'ok', App\View\Ui::budgetTone(79));
same('tono de presupuesto: aviso al 80 %', 'warn', App\View\Ui::budgetTone(80));
same('tono de presupuesto: excedido al 100 %', 'over', App\View\Ui::budgetTone(100));
$bar = (string) App\View\Ui::progress(125, 'Ocio', 'auto');
same('la barra no pasa de 100 %', true, str_contains($bar, 'data-v="100"'));
same('pero aria-valuenow conserva el valor real', true, str_contains($bar, 'aria-valuenow="125"'));
same('el tono automático marca excedido', true, str_contains($bar, 'progress--over'));

section('Ui: campos de formulario');
$field = (string) App\View\Ui::field(['id' => 'correo', 'label' => 'Correo', 'error' => 'Falta el correo', 'hint' => 'Ayuda']);
same('la etiqueta apunta al control', true, str_contains($field, '<label class="field__label" for="correo">'));
same('un error marca aria-invalid', true, str_contains($field, 'aria-invalid="true"'));
same('el control apunta a la ayuda y al error', true, str_contains($field, 'aria-describedby="h-correo e-correo"'));
same('la contraseña nunca se vuelve a pintar', false, str_contains((string) App\View\Ui::field(['id' => 'c', 'label' => 'C', 'type' => 'password', 'value' => 'secreta']), 'secreta'));
same('el campo de contraseña trae su botón de mostrar', true, str_contains((string) App\View\Ui::field(['id' => 'c', 'label' => 'C', 'type' => 'password']), 'data-toggle-password="c"'));
$select = (string) App\View\Ui::select(['id' => 'f', 'label' => 'F', 'blank' => 'Todas', 'options' => ['a' => 'Uno', 'b' => 'Dos'], 'selected' => 'b']);
same('el select marca la opción elegida', true, str_contains($select, '<option value="b" selected>Dos</option>'));
same('el select trae la opción en blanco', true, str_contains($select, '<option value="">Todas</option>'));

section('Ui: gráficos y tablas');
$donut = (string) App\View\Ui::donut([['label' => 'A', 'value' => 60], ['label' => 'B', 'value' => 40]], 'Gráfico');
same('el donut dibuja una porción por dato', 2, substr_count($donut, 'donut__slice '));
same('la segunda porción arranca donde acaba la primera', true, str_contains($donut, 'stroke-dashoffset="-35"'));
$bars = (string) App\View\Ui::bars([['label' => 'a', 'value' => 50], ['label' => 'b', 'value' => 100]], 'Barras');
same('la barra mayor mide 100 %', true, str_contains($bars, 'data-h="100%"'));
same('la barra menor es proporcional', true, str_contains($bars, 'data-h="50%"'));
same('solo la última barra es la actual', 1, substr_count($bars, 'is-current'));
$table = (string) App\View\Ui::table(['caption' => 'T', 'columns' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B', 'kind' => 'actions']], 'rows' => [['a' => '<x>', 'b' => App\View\Ui::badge('ok')]]]);
same('las celdas de texto se escapan', true, str_contains($table, '&lt;x&gt;'));
same('las celdas Html pasan tal cual', true, str_contains($table, '<span class="badge'));
same('las celdas llevan su etiqueta para el móvil', true, str_contains($table, 'data-label="A"'));
same('la columna de acciones no lleva etiqueta', false, str_contains($table, 'data-label="B"'));

section('RegistroValidator');
$validator = new App\Validation\RegistroValidator();
$ok = ['nombre' => '  Ana   María  ', 'correo' => ' ANA@Correo.COM ', 'clave' => 'secreta123', 'clave2' => 'secreta123'];
$result = $validator->validate($ok);
same('un registro válido no da errores', [], $result['errors']);
same('el nombre se limpia de espacios', 'Ana María', $result['data']['nombre']);
same('el correo se pasa a minúsculas y se recorta', 'ana@correo.com', $result['data']['correo']);
same('nombre de 1 carácter', true, isset($validator->validate(['nombre' => 'A'] + $ok)['errors']['nombre']));
same('nombre de 81 caracteres', true, isset($validator->validate(['nombre' => str_repeat('a', 81)] + $ok)['errors']['nombre']));
same('nombre de 80 caracteres', false, isset($validator->validate(['nombre' => str_repeat('a', 80)] + $ok)['errors']['nombre']));
same('correo sin arroba', true, isset($validator->validate(['correo' => 'ana.correo.com'] + $ok)['errors']['correo']));
same('correo de más de 120 caracteres', true, isset($validator->validate(['correo' => str_repeat('a', 115) . '@b.com'] + $ok)['errors']['correo']));
same('contraseña de 7 caracteres', true, isset($validator->validate(['clave' => '1234567', 'clave2' => '1234567'] + $ok)['errors']['clave']));
same('contraseña de 8 caracteres', [], $validator->validate(['clave' => '12345678', 'clave2' => '12345678'] + $ok)['errors']);
same('contraseña de más de 72 bytes', true, isset($validator->validate(['clave' => str_repeat('a', 73), 'clave2' => str_repeat('a', 73)] + $ok)['errors']['clave']));
same('contraseñas distintas', true, isset($validator->validate(['clave2' => 'otra-distinta'] + $ok)['errors']['clave2']));
same('un campo en forma de arreglo cuenta como vacío', true, isset($validator->validate(['nombre' => ['x']] + $ok)['errors']['nombre']));
same('el validador ignora un campo «rol»', ['nombre', 'correo', 'clave'], array_keys($validator->validate($ok + ['rol' => 'admin'])['data']));

section('LimitadorIntentos (reglas puras)');
same('por debajo de los límites', false, App\Auth\LimitadorIntentos::excede(4, 19));
same('5 por correo bloquea', true, App\Auth\LimitadorIntentos::excede(5, 0));
same('20 por IP bloquea', true, App\Auth\LimitadorIntentos::excede(0, 20));
same('con par: 5 del par bloquean', true, App\Auth\LimitadorIntentos::excede(5, 5, 5));
same('con par: 49 por correo no bloquean si el par está bajo', false, App\Auth\LimitadorIntentos::excede(49, 5, 4));
same('con par: 50 por correo (IP rotatorias) bloquean', true, App\Auth\LimitadorIntentos::excede(50, 0, 0));
same('con par: 20 por IP bloquean', true, App\Auth\LimitadorIntentos::excede(0, 20, 0));
same('login y clave cuentan por par; registro no', [true, true, false], [App\Auth\LimitadorIntentos::usaPar('login'), App\Auth\LimitadorIntentos::usaPar('clave'), App\Auth\LimitadorIntentos::usaPar('registro')]);
same('la clave del par no lleva el correo ni la IP en claro y depende de ambos', true, !str_contains(App\Auth\LimitadorIntentos::clavePar('ana@correo.com', '1.2.3.4'), 'ana') && App\Auth\LimitadorIntentos::clavePar('ana@correo.com', '1.2.3.4') !== App\Auth\LimitadorIntentos::clavePar('ana@correo.com', '1.2.3.5'));
same('la clave del correo no lleva el correo en claro', false, str_contains(App\Auth\LimitadorIntentos::claveCorreo('ana@correo.com'), 'ana'));
same('la clave del correo ignora mayúsculas y espacios', App\Auth\LimitadorIntentos::claveCorreo('ana@correo.com'), App\Auth\LimitadorIntentos::claveCorreo(' ANA@correo.com '));

section('Autenticador (con un origen de datos falso)');
$fake = new class implements App\Auth\CredentialSource {
    /** @var array<string, App\Domain\Usuario> */
    public array $users = [];
    public ?string $newHash = null;
    public function findByEmail(string $correo): ?App\Domain\Usuario
    {
        return $this->users[$correo] ?? null;
    }
    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->newHash = $hash;
    }
};
$fake->users['ana@correo.com'] = new App\Domain\Usuario(1, 'Ana', 'ana@correo.com', password_hash('secreta123', PASSWORD_DEFAULT), 'COP', 'usuario', true);
$fake->users['baja@correo.com'] = new App\Domain\Usuario(2, 'Baja', 'baja@correo.com', password_hash('secreta123', PASSWORD_DEFAULT), 'COP', 'usuario', false);
$fake->users['vieja@correo.com'] = new App\Domain\Usuario(3, 'Vieja', 'vieja@correo.com', password_hash('secreta123', PASSWORD_BCRYPT, ['cost' => 4]), 'COP', 'usuario', true);
$auth = new App\Auth\Autenticador($fake);
same('credenciales correctas', 1, $auth->authenticate('ana@correo.com', 'secreta123')?->id);
same('el correo no distingue mayúsculas ni espacios', 1, $auth->authenticate(' ANA@correo.com ', 'secreta123')?->id);
same('contraseña incorrecta', null, $auth->authenticate('ana@correo.com', 'mala'));
same('correo inexistente', null, $auth->authenticate('nadie@correo.com', 'secreta123'));
same('usuario inactivo con la clave correcta no entra', null, $auth->authenticate('baja@correo.com', 'secreta123'));
same('una clave correcta no vuelve a hashear si el hash está al día', null, $fake->newHash);
$viejo = $auth->authenticate('vieja@correo.com', 'secreta123');
same('un hash viejo se rehashea al entrar', 3, $viejo?->id);
same('y el usuario devuelto lleva ya el hash NUEVO (la huella de sesión debe coincidir con la BD)', $fake->newHash, $viejo?->contrasenaHash);
same('el hash nuevo es válido y más fuerte', true, $fake->newHash !== null && password_verify('secreta123', $fake->newHash) && !password_needs_rehash($fake->newHash, PASSWORD_DEFAULT));
same('el hash del admin de desarrollo (database/dev/admin.sql) corresponde a la clave documentada', true, (static function (): bool {
    preg_match("/'(\\\$2y\\\$[^']+)'/", (string) file_get_contents(dirname(__DIR__, 2) . '/database/dev/admin.sql'), $m);
    return isset($m[1]) && password_verify('Admin#2026dev', $m[1]);
})());
same('AdminSemilla::HASH_PUBLICO es el hash de database/dev/admin.sql', true, str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/database/dev/admin.sql'), \App\Security\AdminSemilla::HASH_PUBLICO));
same('02-seed.sql (que también corre en producción) NO crea ningún usuario ni menciona la clave pública (C1)', true, (static function (): bool {
    $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/database/02-seed.sql');
    return !str_contains($seed, 'INTO usuarios') && !str_contains($seed, '$2y$') && !str_contains($seed, 'Admin#2026dev');
})());
same('el admin de desarrollo está fuera del primer nivel de database/ (initdb no lo ejecuta solo)', true, !is_file(dirname(__DIR__, 2) . '/database/admin.sql') && is_file(dirname(__DIR__, 2) . '/database/04-dev-admin.sh'));
same('04-dev-admin.sh solo actúa con APP_ENV=local', true, str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/database/04-dev-admin.sh'), '"${APP_ENV:-}" != "local"'));

section('Verificación de correo (A1)');
$filaU = ['id_usuario' => 7, 'nombre' => 'U', 'correo' => 'u@x.com', 'contrasena_hash' => 'h', 'moneda' => 'COP', 'rol' => 'usuario', 'activo' => 1];
same('Usuario::fromRow: sin correo_verificado_en el correo NO está verificado', false, App\Domain\Usuario::fromRow($filaU)->correoVerificado);
same('Usuario::fromRow: con fecha en correo_verificado_en sí lo está', true, App\Domain\Usuario::fromRow($filaU + ['correo_verificado_en' => '2026-11-27 10:00:00'])->correoVerificado);
same('conContrasenaHash conserva el estado de verificación', true, App\Domain\Usuario::fromRow($filaU + ['correo_verificado_en' => '2026-11-27 10:00:00'])->conContrasenaHash('otro')->correoVerificado);
same('un token de verificación válido son 64 hexadecimales', [true, false, false, false], [
    App\Auth\VerificacionCorreo::formaValida(str_repeat('a1', 32)),
    App\Auth\VerificacionCorreo::formaValida(str_repeat('A1', 32)),
    App\Auth\VerificacionCorreo::formaValida('abc'),
    App\Auth\VerificacionCorreo::formaValida(str_repeat('g', 64)),
]);
same('hasta 3 envíos por hora y enlaces de 48 h', [3, 3600, 48], [App\Auth\VerificacionCorreo::MAX_ENVIOS, App\Auth\VerificacionCorreo::VENTANA_ENVIOS, App\Auth\VerificacionCorreo::VIGENCIA_HORAS]);

section('AdminSemilla (C1)');
use App\Security\AdminSemilla as Semilla;
$semilla = static fn (string $hash = Semilla::HASH_PUBLICO, bool $activo = true, string $rol = 'admin'): App\Domain\Usuario => new App\Domain\Usuario(1, 'Administrador', Semilla::CORREO, $hash, 'COP', $rol, $activo);
same('en producción, el admin activo con la clave pública está expuesto', true, Semilla::expuesto($semilla(), 'production'));
same('con APP_ENV vacío (no definido) también se considera expuesto', true, Semilla::expuesto($semilla(), ''));
same('en local no es un riesgo', false, Semilla::expuesto($semilla(), 'local'));
same('si cambió la contraseña, ya no está expuesto', false, Semilla::expuesto($semilla(password_hash('otra-clave-9', PASSWORD_DEFAULT)), 'production'));
same('si está desactivado, no está expuesto', false, Semilla::expuesto($semilla(Semilla::HASH_PUBLICO, false), 'production'));
same('si ya no es admin, no está expuesto', false, Semilla::expuesto($semilla(Semilla::HASH_PUBLICO, true, 'usuario'), 'production'));
same('si no existe el usuario, no está expuesto', false, Semilla::expuesto(null, 'production'));

section('HuerfanosAdjuntos (M3)');
use App\Support\HuerfanosAdjuntos as Huerf;
$hBase = sys_get_temp_dir() . '/lucas-huerfanos-' . bin2hex(random_bytes(4));
mkdir($hBase . '/2026/11', 0775, true);
$hViejo = '2026/11/' . str_repeat('a', 32) . '.png';
$hUsado = '2026/11/' . str_repeat('b', 32) . '.pdf';
$hNuevo = '2026/11/' . str_repeat('c', 32) . '.jpg';
foreach ([$hViejo, $hUsado, $hNuevo, '2026/11/evil.php', '2026/11/' . str_repeat('d', 32) . '.php', '2026/11/' . str_repeat('E', 32) . '.png'] as $f) {
    file_put_contents($hBase . '/' . $f, 'x');
}
touch($hBase . '/' . $hViejo, time() - 3 * 86400);
touch($hBase . '/' . $hUsado, time() - 3 * 86400);
file_put_contents($hBase . '/.gitkeep', '');
same('enDisco solo lista los nombres canónicos (ni .php, ni mayúsculas, ni archivos sueltos)', [$hViejo, $hUsado, $hNuevo], Huerf::enDisco($hBase));
same('huérfano = en disco, sin fila y de más de 24 h (el usado y el recién subido se respetan)', [$hViejo], Huerf::huerfanos([$hUsado], 86400, $hBase));
same('con edad mínima 0 también cuenta el recién subido', [$hViejo, $hNuevo], Huerf::huerfanos([$hUsado], 0, $hBase));
same('si todos tienen fila, no hay huérfanos', [], Huerf::huerfanos([$hViejo, $hUsado, $hNuevo], 0, $hBase));
same('faltantes: filas que apuntan a un archivo inexistente', ['2026/11/' . str_repeat('f', 32) . '.png'], Huerf::faltantes([$hUsado, '2026/11/' . str_repeat('f', 32) . '.png'], $hBase));
foreach (array_merge(glob($hBase . '/2026/11/*') ?: [], [$hBase . '/.gitkeep']) as $f) {
    @unlink($f);
}
@rmdir($hBase . '/2026/11'); @rmdir($hBase . '/2026'); @rmdir($hBase);

section('ReglasAdmin (RF-33)');
use App\Admin\ReglasAdmin as R;
$u = static fn (int $id, string $rol = 'usuario', bool $activo = true): App\Domain\Usuario => new App\Domain\Usuario($id, "U$id", "u$id@x.com", 'h', 'COP', $rol, $activo);
same('desactivar a otro usuario', R::OK, R::evaluar([1], 1, $u(2), 'desactivar'));
same('activar a un usuario inactivo', R::OK, R::evaluar([1], 1, $u(2, 'usuario', false), 'activar'));
same('activar a uno ya activo no cambia nada', R::SIN_CAMBIO, R::evaluar([1], 1, $u(2), 'activar'));
same('hacer admin a un usuario', R::OK, R::evaluar([1], 1, $u(2), 'hacer_admin'));
same('quitar admin a un usuario normal no cambia nada', R::SIN_CAMBIO, R::evaluar([1], 1, $u(2), 'quitar_admin'));
same('quitar admin a otro admin habiendo más', R::OK, R::evaluar([1, 2], 1, $u(2, 'admin'), 'quitar_admin'));
same('no puede desactivarse a sí mismo', R::PROPIO, R::evaluar([1, 2], 1, $u(1, 'admin'), 'desactivar'));
same('no puede quitarse el rol a sí mismo', R::PROPIO, R::evaluar([1, 2], 1, $u(1, 'admin'), 'quitar_admin'));
same('un objetivo inexistente', R::NO_EXISTE, R::evaluar([1], 1, null, 'desactivar'));
same('quien actúa ya no es admin activo (otro lo desactivó)', R::SIN_PERMISO, R::evaluar([2], 1, $u(3), 'desactivar'));
same('un usuario normal nunca tiene permiso', R::SIN_PERMISO, R::evaluar([2], 5, $u(3), 'hacer_admin'));
// ULTIMO_ADMIN es una red de seguridad: con las reglas anteriores (el actor es un admin activo distinto del objetivo)
// no se alcanza nunca, y la propiedad de abajo lo comprueba de forma general.
same('PROPIEDAD: tras cualquier acción permitida siempre queda un admin activo', 0, (static function () use ($u): int {
    $violaciones = 0;
    foreach ([[1], [2], [1, 2], [1, 3], [2, 3], [1, 2, 3]] as $admins) {
        foreach ([1, 2, 3] as $actor) {
            foreach ([1, 2, 3, 4] as $objetivoId) {
                foreach (R::ACCIONES as $accion) {
                    $esAdmin = in_array($objetivoId, $admins, true);
                    $objetivo = $u($objetivoId, $esAdmin ? 'admin' : 'usuario', true);
                    if (R::evaluar($admins, $actor, $objetivo, $accion) !== R::OK) {
                        continue;
                    }
                    $despues = $admins;
                    if (in_array($accion, ['desactivar', 'quitar_admin'], true)) {
                        $despues = array_values(array_diff($admins, [$objetivoId]));
                    } elseif ($accion === 'hacer_admin') {
                        $despues[] = $objetivoId;
                    }
                    $violaciones += $despues === [] ? 1 : 0;
                }
            }
        }
    }
    return $violaciones;
})());
same('una acción desconocida lanza excepción', true, (static function () use ($u): bool {
    try {
        R::evaluar([1], 1, $u(2), 'borrar');
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
})());

section('Fecha');
same('mes corto', 'Dic', App\View\Fecha::mesCorto(12));
same('mes corto de enero', 'Ene', App\View\Fecha::mesCorto(1));
same('mes y año', 'noviembre 2026', App\View\Fecha::mesAnio(new DateTimeImmutable('2026-11-24')));
same('día en zona local', '19 nov', App\View\Fecha::dia('2026-11-19 14:08:36'));
same('fecha numérica en zona local (la medianoche UTC aún es el día anterior en Bogotá)', '18/11/2026', App\View\Fecha::numerica('2026-11-19 03:00:00'));
same('una fecha inválida se devuelve igual', 'nada', App\View\Fecha::dia('nada'));

section('Dinero::parse()');
use App\Support\Dinero as D;
same('entero simple', '1950000.00', D::parse('1950000'));
same('miles con punto', '1950000.00', D::parse('1.950.000'));
same('miles con punto y decimales con coma', '1950000.50', D::parse('1.950.000,5'));
same('con símbolo y espacios', '80000.00', D::parse('$ 80.000'));
same('espacios como separador', '1250000.00', D::parse('1 250 000'));
same('negativo con guion', '-500.50', D::parse('-500,5'));
same('negativo con signo menos tipográfico', '-500.00', D::parse('−500'));
same('«-0» no es negativo', '0.00', D::parse('-0'));
same('punto decimal de 1 o 2 dígitos', '12.50', D::parse('12.5'));
same('«1.234» es mil doscientos treinta y cuatro', '1234.00', D::parse('1.234'));
same('ceros a la izquierda', '1.00', D::parse('0001'));
same('el máximo de DECIMAL(12,2)', '9999999999.99', D::parse('9.999.999.999,99'));
same('un peso más allá del máximo', null, D::parse('10.000.000.000'));
same('tres decimales', null, D::parse('1234.567'));
same('formato inglés no se acepta', null, D::parse('1,234.56'));
same('miles mal puestos', null, D::parse('1.2.3'));
same('letras', null, D::parse('abc'));
same('vacío', null, D::parse(''));
same('doble signo', null, D::parse('--5'));
same('inyección', null, D::parse("5'; DROP TABLE x;--"));

section('Dinero: centavos, suma y formato');
same('a centavos', 123450, D::aCentavos('1234.50'));
same('a centavos negativo', -8000, D::aCentavos('-80.00'));
same('a centavos sin decimales (como a veces llega)', 500, D::aCentavos('5'));
same('de centavos', '-0.05', D::deCentavos(-5));
same('0,10 + 0,20 es exactamente 0,30 (con float daría 0.30000000000000004)', '0.30', D::sumar(['0.10', '0.20']));
same('suma de varios con negativos', '120000.15', D::sumar(['100000.00', '50000.50', '-20000.25', '-10000.10']));
same('suma vacía', '0.00', D::sumar([]));
same('formato en pesos', '$1.950.000', D::formatear('1950000.00'));
same('formato negativo', '−$80.000', D::formatear('-80000.00'));
same('formato con decimales solo si hay', '$1.250,50', D::formatear('1250.50'));
same('formato de cero', '$0', D::formatear('0.00'));
same('para un campo de texto', '1.950.000,50', D::paraCampo('1950000.50'));
same('para un campo de texto sin decimales', '-300', D::paraCampo('-300.00'));
same('para un campo de texto negativo con miles', '-1.500.000', D::paraCampo('-1500000.00'));
same('parse(paraCampo(x)) devuelve x', '1950000.50', D::parse(D::paraCampo('1950000.50')));

section('CuentaValidator');
$cv = new App\Validation\CuentaValidator();
$r = $cv->validate(['nombre' => '  Bancolombia   ahorros ', 'tipo' => 'bancaria', 'saldo_inicial' => '1.500.000,50']);
same('una cuenta válida no da errores', [], $r['errors']);
same('el nombre se limpia', 'Bancolombia ahorros', $r['data']['nombre']);
same('el saldo se normaliza', '1500000.50', $r['data']['saldo_inicial']);
same('el saldo vacío es 0', '0.00', $cv->validate(['nombre' => 'Efectivo', 'tipo' => 'efectivo', 'saldo_inicial' => ''])['data']['saldo_inicial']);
same('nombre de 1 carácter', true, isset($cv->validate(['nombre' => 'A', 'tipo' => 'efectivo'])['errors']['nombre']));
same('nombre de 61 caracteres', true, isset($cv->validate(['nombre' => str_repeat('a', 61), 'tipo' => 'efectivo'])['errors']['nombre']));
same('tipo desconocido', true, isset($cv->validate(['nombre' => 'Caja', 'tipo' => 'cripto'])['errors']['tipo']));
same('saldo inválido', true, isset($cv->validate(['nombre' => 'Caja', 'tipo' => 'efectivo', 'saldo_inicial' => 'mucho'])['errors']['saldo_inicial']));
same('saldo negativo en una cuenta normal', true, isset($cv->validate(['nombre' => 'Caja', 'tipo' => 'ahorro', 'saldo_inicial' => '-5'])['errors']['saldo_inicial']));
same('saldo negativo en una tarjeta de crédito', [], $cv->validate(['nombre' => 'Visa', 'tipo' => 'tarjeta_credito', 'saldo_inicial' => '-500.000'])['errors']);
same('campos con forma de arreglo cuentan como vacíos', true, isset($cv->validate(['nombre' => ['x'], 'tipo' => ['y']])['errors']['nombre']));

section('CategoriaValidator');
$kv = new App\Validation\CategoriaValidator();
$r = $kv->validate(['nombre' => ' Mascotas ', 'tipo' => 'gasto', 'color' => '#a1b2c3'], true);
same('una categoría válida no da errores', [], $r['errors']);
same('el color se pasa a mayúsculas', '#A1B2C3', $r['data']['color']);
same('sin color se usa el gris por defecto', '#8DA0AB', $kv->validate(['nombre' => 'Mascotas', 'tipo' => 'gasto'], true)['data']['color']);
same('color inválido', true, isset($kv->validate(['nombre' => 'Mascotas', 'tipo' => 'gasto', 'color' => 'rojo'], true)['errors']['color']));
same('color con código malicioso', true, isset($kv->validate(['nombre' => 'Mascotas', 'tipo' => 'gasto', 'color' => '#FFF;x:y'], true)['errors']['color']));
same('al crear, el tipo es obligatorio', true, isset($kv->validate(['nombre' => 'Mascotas', 'tipo' => 'otro'], true)['errors']['tipo']));
same('al editar, el tipo se ignora', [], $kv->validate(['nombre' => 'Mascotas', 'tipo' => 'otro'], false)['errors']);
same('nombre de 1 carácter', true, isset($kv->validate(['nombre' => 'A', 'tipo' => 'gasto'], true)['errors']['nombre']));
same('nombre de 51 caracteres', true, isset($kv->validate(['nombre' => str_repeat('a', 51), 'tipo' => 'gasto'], true)['errors']['nombre']));

section('MovimientoValidator');
$mv = new App\Validation\MovimientoValidator();
$base = ['tipo' => 'gasto', 'monto' => '18.000', 'fecha' => '2026-11-03', 'id_cuenta' => '4', 'id_categoria' => '7', 'descripcion' => '  Almuerzo   con compañeros '];
$r = $mv->validate($base);
same('un movimiento válido no da errores', [], $r['errors']);
same('el monto se normaliza', '18000.00', $r['data']['monto']);
same('la descripción se limpia', 'Almuerzo con compañeros', $r['data']['descripcion']);
same('los ids pasan a enteros', [4, 7], [$r['data']['id_cuenta'], $r['data']['id_categoria']]);
same('una descripción vacía es null', null, $mv->validate(['descripcion' => '   '] + $base)['data']['descripcion']);
same('tipo inválido', true, isset($mv->validate(['tipo' => 'transferencia'] + $base)['errors']['tipo']));
same('monto cero', true, isset($mv->validate(['monto' => '0'] + $base)['errors']['monto']));
same('monto negativo', true, isset($mv->validate(['monto' => '-5.000'] + $base)['errors']['monto']));
same('monto que no es número', true, isset($mv->validate(['monto' => 'mucho'] + $base)['errors']['monto']));
same('monto con 3 decimales', true, isset($mv->validate(['monto' => '10,123'] + $base)['errors']['monto']));
same('monto con 2 decimales', '1250.50', $mv->validate(['monto' => '1.250,50'] + $base)['data']['monto']);
same('monto que no cabe en DECIMAL(12,2)', true, isset($mv->validate(['monto' => '10.000.000.000'] + $base)['errors']['monto']));
same('fecha imposible (31 de febrero)', true, isset($mv->validate(['fecha' => '2026-02-31'] + $base)['errors']['fecha']));
same('fecha con otro formato', true, isset($mv->validate(['fecha' => '03/11/2026'] + $base)['errors']['fecha']));
same('fecha de un año absurdo', true, isset($mv->validate(['fecha' => '1850-01-01'] + $base)['errors']['fecha']));
same('fecha vacía', true, isset($mv->validate(['fecha' => ''] + $base)['errors']['fecha']));
same('29 de febrero de un año bisiesto', [], $mv->validate(['fecha' => '2028-02-29'] + $base)['errors']);
same('cuenta sin elegir', true, isset($mv->validate(['id_cuenta' => ''] + $base)['errors']['id_cuenta']));
same('cuenta con un id no numérico', true, isset($mv->validate(['id_cuenta' => '1 OR 1=1'] + $base)['errors']['id_cuenta']));
same('categoría con id negativo', true, isset($mv->validate(['id_categoria' => '-3'] + $base)['errors']['id_categoria']));
same('categoría con un arreglo', true, isset($mv->validate(['id_categoria' => ['1']] + $base)['errors']['id_categoria']));
same('descripción de 151 caracteres', true, isset($mv->validate(['descripcion' => str_repeat('a', 151)] + $base)['errors']['descripcion']));
same('descripción de 150 caracteres', [], $mv->validate(['descripcion' => str_repeat('a', 150)] + $base)['errors']);

section('ReglasMovimiento (D8)');
use App\Movimientos\ReglasMovimiento as RM;
$cat = static fn (?int $dueño, string $tipo): App\Domain\Categoria => new App\Domain\Categoria(1, $dueño, 'X', $tipo, '#000000');
$activa = ['activa' => true, 'tipo' => 'efectivo'];
$tarjetaActiva = ['activa' => true, 'tipo' => 'tarjeta_credito'];
same('un gasto en una cuenta activa con una categoría de gasto propia', RM::OK, RM::evaluar($activa, $cat(5, 'gasto'), 5, 'gasto', '50000.00', '18000.00'));
same('un gasto igual al saldo disponible deja la cuenta en cero (es válido)', RM::OK, RM::evaluar($activa, $cat(5, 'gasto'), 5, 'gasto', '18000.00', '18000.00'));
same('un gasto mayor que el saldo disponible en una cuenta normal se rechaza', RM::SALDO_INSUFICIENTE, RM::evaluar($activa, $cat(5, 'gasto'), 5, 'gasto', '18000.00', '18000.01'));
same('un gasto en una tarjeta de crédito sí puede dejar el saldo en negativo', RM::OK, RM::evaluar($tarjetaActiva, $cat(5, 'gasto'), 5, 'gasto', '0.00', '500000.00'));
same('con una categoría predeterminada', RM::OK, RM::evaluar($activa, $cat(null, 'ingreso'), 5, 'ingreso'));
same('una cuenta que no es del usuario', RM::CUENTA_AJENA, RM::evaluar(null, $cat(null, 'gasto'), 5, 'gasto'));
same('una categoría que no existe', RM::CATEGORIA_AJENA, RM::evaluar($activa, null, 5, 'gasto'));
same('una categoría de otro usuario', RM::CATEGORIA_AJENA, RM::evaluar($activa, $cat(9, 'gasto'), 5, 'gasto'));
same('el tipo no coincide con el de la categoría', RM::TIPO_DISCORDANTE, RM::evaluar($activa, $cat(null, 'ingreso'), 5, 'gasto'));
same('una cuenta inactiva', RM::CUENTA_INACTIVA, RM::evaluar(['activa' => false], $cat(null, 'gasto'), 5, 'gasto'));
same('lo ajeno se detecta antes que lo discordante (no se revela que existe)', RM::CATEGORIA_AJENA, RM::evaluar($activa, $cat(9, 'ingreso'), 5, 'gasto'));

section('Filtros del listado');
use App\Movimientos\Filtros as F;
same('sin parámetros no hay filtros', ['desde' => '', 'hasta' => '', 'cuenta' => 0, 'categoria' => 0, 'tipo' => '', 'etiqueta' => ''], F::desde([]));
same('filtros válidos', ['desde' => '2026-11-01', 'hasta' => '2026-11-30', 'cuenta' => 3, 'categoria' => 8, 'tipo' => 'gasto', 'etiqueta' => ''], F::desde(['desde' => '2026-11-01', 'hasta' => '2026-11-30', 'cuenta' => '3', 'categoria' => '8', 'tipo' => 'gasto']));
same('una fecha inválida se ignora', '', F::desde(['desde' => '2026-13-45'])['desde']);
same('un tipo desconocido se ignora', '', F::desde(['tipo' => 'otro'])['tipo']);
same('un id no numérico se ignora', 0, F::desde(['cuenta' => '1; DROP TABLE'])['cuenta']);
same('un id gigante se ignora (no desborda)', 0, F::desde(['cuenta' => str_repeat('9', 30)])['cuenta']);
same('parámetros con forma de arreglo se ignoran', 0, F::desde(['cuenta' => ['1'], 'categoria' => ['2']])['cuenta']);
same('la query omite lo vacío', 'tipo=gasto', F::query(F::desde(['tipo' => 'gasto', 'desde' => 'mal'])));
same('la query añade la página solo si pasa de 1', ['tipo=gasto', 'tipo=gasto&pagina=3'], [F::query(F::desde(['tipo' => 'gasto']), 1), F::query(F::desde(['tipo' => 'gasto']), 3)]);
same('hayAlguno', [false, true], [F::hayAlguno(F::desde([])), F::hayAlguno(F::desde(['cuenta' => '2']))]);

section('Ui::money con montos exactos y Fecha::simple');
same('un monto en cadena de ingreso', '<span class="money money--in">+$1.250,50</span>', (string) App\View\Ui::money('1250.50', 'in'));
same('un monto en cadena de gasto', '<span class="money money--out">−$18.000</span>', (string) App\View\Ui::money('18000.00', 'out'));
same('un monto en cadena negativo, neutro', '<span class="money money--plain">−$300.000</span>', (string) App\View\Ui::money('-300000.00'));
same('un entero sigue funcionando', '<span class="money money--plain">$5.000</span>', (string) App\View\Ui::money(5000));
same('fecha simple (sin convertir zona horaria)', '03/11/2026', App\View\Fecha::simple('2026-11-03'));
same('fecha simple inválida se devuelve igual', 'nada', App\View\Fecha::simple('nada'));

section('Ui::pagination con ventana');
$pag = static fn (int $actual, int $total): string => (string) App\View\Ui::pagination($actual, $total, '?p=%d');
same('pocas páginas: todas', 3, substr_count($pag(1, 3), 'aria-label="Página'));
same('500 páginas no dibujan 500 enlaces (1, 2 vecinas a cada lado y la última)', 7, substr_count($pag(250, 500), 'aria-label="Página'));
same('con huecos marcados', 2, substr_count($pag(250, 500), 'pagination__gap'));
same('al inicio solo hay un hueco', 1, substr_count($pag(1, 500), 'pagination__gap'));
same('la actual está marcada', true, str_contains($pag(250, 500), 'href="?p=250" aria-current="page"'));
same('siempre está la última', true, str_contains($pag(1, 500), 'href="?p=500"'));
same('una sola página', 1, substr_count($pag(1, 1), 'aria-label="Página'));

section('Ui::select: error y atributos por opción');
$sel = (string) App\View\Ui::select(['id' => 'c', 'label' => 'C', 'options' => ['5' => 'Gasto', '6' => 'Ingreso'], 'optionAttrs' => ['5' => ['data-tipo' => 'gasto']], 'selected' => '6', 'error' => 'Elige']);
same('la opción lleva su atributo', true, str_contains($sel, '<option value="5" data-tipo="gasto">Gasto</option>'));
same('la seleccionada queda marcada', true, str_contains($sel, '<option value="6" selected>Ingreso</option>'));
same('muestra el error y lo enlaza', true, str_contains($sel, 'id="e-c"') && str_contains($sel, 'aria-describedby="e-c"') && str_contains($sel, 'aria-invalid="true"'));

section('Mes');
use App\Support\Mes as M;
$hoy = new DateTimeImmutable('2026-11-24');
same('sin parámetros es el mes actual', ['mes' => 11, 'anio' => 2026], M::desde([], $hoy));
same('un mes válido', ['mes' => 3, 'anio' => 2027], M::desde(['mes' => '3', 'anio' => '2027'], $hoy));
same('mes 13 cae al actual', ['mes' => 11, 'anio' => 2026], M::desde(['mes' => '13', 'anio' => '2027'], $hoy));
same('mes 0 cae al actual', ['mes' => 11, 'anio' => 2026], M::desde(['mes' => '0', 'anio' => '2027'], $hoy));
same('un año absurdo cae al actual', ['mes' => 11, 'anio' => 2026], M::desde(['mes' => '3', 'anio' => '1850'], $hoy));
same('valores con forma de arreglo caen al actual', ['mes' => 11, 'anio' => 2026], M::desde(['mes' => ['3'], 'anio' => ['2027']], $hoy));
same('texto que no es número cae al actual', ['mes' => 11, 'anio' => 2026], M::desde(['mes' => 'marzo', 'anio' => '2027'], $hoy));
same('mes anterior', ['mes' => 10, 'anio' => 2026], M::anterior(11, 2026));
same('el anterior de enero es diciembre del año anterior', ['mes' => 12, 'anio' => 2025], M::anterior(1, 2026));
same('mes siguiente', ['mes' => 12, 'anio' => 2026], M::siguiente(11, 2026));
same('el siguiente de diciembre es enero del año siguiente', ['mes' => 1, 'anio' => 2027], M::siguiente(12, 2026));
same('no se sale del rango de años', ['mes' => 1, 'anio' => 2100], M::siguiente(12, 2100));
same('límites del mes', ['2026-11-01', '2026-12-01'], M::limites(11, 2026));
same('los límites de diciembre llegan a enero', ['2026-12-01', '2027-01-01'], M::limites(12, 2026));
same('febrero bisiesto', ['2028-02-01', '2028-03-01'], M::limites(2, 2028));
same('etiqueta', 'Noviembre 2026', M::etiqueta(11, 2026));
same('el mes de una fecha', ['mes' => 3, 'anio' => 2027], M::deFecha('2027-03-15'));
same('una fecha inválida no tiene mes', null, M::deFecha('mañana'));

section('Progreso de un presupuesto (RF-16, RF-17)');
use App\Support\Progreso as P;
$pp = static fn (string $g, string $l): array => P::presupuesto($g, $l);
same('sin gastar', ['percent' => 0, 'estado' => 'ok', 'tone' => 'ok', 'restante' => '400000.00'], $pp('0', '400000'));
same('79,99 % sigue en orden', ['ok', 79], [$pp('79.99', '100')['estado'], $pp('79.99', '100')['percent']]);
same('exactamente 80 % pide aviso', ['cerca', 'warn', 80], [$pp('80.00', '100')['estado'], $pp('80.00', '100')['tone'], $pp('80.00', '100')['percent']]);
same('un centavo antes del 80 % no avisa', 'ok', $pp('319999.99', '400000')['estado']);
same('320.000 de 400.000 es el 80 %', 'cerca', $pp('320000', '400000')['estado']);
same('99,99 % es cerca del límite', ['cerca', 99], [$pp('99.99', '100')['estado'], $pp('99.99', '100')['percent']]);
same('exactamente el límite', ['limite', 'over', 100], [$pp('100.00', '100')['estado'], $pp('100.00', '100')['tone'], $pp('100.00', '100')['percent']]);
same('un centavo por encima: excedido, aunque el porcentaje entero siga en 100', ['excedido', 'over', 100], [$pp('100.01', '100')['estado'], $pp('100.01', '100')['tone'], $pp('100.01', '100')['percent']]);
same('150 % excedido', ['excedido', 150], [$pp('150', '100')['estado'], $pp('150', '100')['percent']]);
same('lo que queda (negativo si se pasó)', ['70.00', '-50.00'], [$pp('30', '100')['restante'], $pp('150', '100')['restante']]);
same('con decimales exactos (0,10 de 0,30 son 33 %)', 33, $pp('0.10', '0.30')['percent']);
same('un límite de cero no divide por cero', 0, $pp('50', '0')['percent']);
same('montos enormes no desbordan', 'ok', $pp('1000000000.00', '9999999999.99')['estado']);

section('Progreso de una meta (RF-20)');
same('sin aportes', ['percent' => 0, 'faltante' => '3000000.00', 'lograda' => false], P::meta('0', '3000000'));
same('a la mitad', [50, '1500000.00', false], array_values(P::meta('1500000', '3000000')));
same('un centavo antes del objetivo no está lograda', [99, '0.01', false], array_values(P::meta('2999999.99', '3000000')));
same('justo en el objetivo', [100, '0.00', true], array_values(P::meta('3000000', '3000000')));
same('pasarse del objetivo: faltante nunca es negativo', [150, '0.00', true], array_values(P::meta('4500000', '3000000')));

section('ReglasPresupuesto');
use App\Presupuestos\ReglasPresupuesto as RP;
same('una categoría de gasto predeterminada', RP::OK, RP::evaluar($cat(null, 'gasto'), 5));
same('una categoría de gasto propia', RP::OK, RP::evaluar($cat(5, 'gasto'), 5));
same('una categoría de ingreso no sirve', RP::NO_ES_GASTO, RP::evaluar($cat(null, 'ingreso'), 5));
same('una categoría de otro usuario', RP::CATEGORIA_AJENA, RP::evaluar($cat(9, 'gasto'), 5));
same('una categoría inexistente', RP::CATEGORIA_AJENA, RP::evaluar(null, 5));
same('lo ajeno se detecta antes que el tipo', RP::CATEGORIA_AJENA, RP::evaluar($cat(9, 'ingreso'), 5));

section('ReglasAporte (D8)');
use App\Metas\ReglasAporte as RA;
$cuentaOk = ['activa' => true];
$metaOk = ['estado' => 'en_curso'];
same('un aporte válido', RA::OK, RA::evaluar($cuentaOk, $metaOk, '500000.00', '100000.00'));
same('un aporte igual al saldo es válido (deja la cuenta en cero)', RA::OK, RA::evaluar($cuentaOk, $metaOk, '100000.00', '100000.00'));
same('un centavo más que el saldo se rechaza', RA::SALDO_INSUFICIENTE, RA::evaluar($cuentaOk, $metaOk, '100000.00', '100000.01'));
same('una cuenta con saldo negativo no puede aportar', RA::SALDO_INSUFICIENTE, RA::evaluar($cuentaOk, $metaOk, '-5.00', '1.00'));
same('una cuenta de otro usuario', RA::CUENTA_AJENA, RA::evaluar(null, $metaOk, '500.00', '1.00'));
same('una meta de otro usuario', RA::META_AJENA, RA::evaluar($cuentaOk, null, '500.00', '1.00'));
same('una meta lograda ya no admite aportes', RA::META_CERRADA, RA::evaluar($cuentaOk, ['estado' => 'lograda'], '500.00', '1.00'));
same('una meta cancelada tampoco', RA::META_CERRADA, RA::evaluar($cuentaOk, ['estado' => 'cancelada'], '500.00', '1.00'));
same('una cuenta inactiva', RA::CUENTA_INACTIVA, RA::evaluar(['activa' => false], $metaOk, '500.00', '1.00'));
same('lo ajeno se detecta antes que el saldo', RA::CUENTA_AJENA, RA::evaluar(null, $metaOk, '0.00', '999.00'));

section('PresupuestoValidator');
$pv = new App\Validation\PresupuestoValidator();
$pb = ['id_categoria' => '3', 'mes' => '11', 'anio' => '2026', 'monto_limite' => '400.000'];
same('un presupuesto válido', [], $pv->validate($pb)['errors']);
same('el límite se normaliza', '400000.00', $pv->validate($pb)['data']['monto_limite']);
same('sin categoría', true, isset($pv->validate(['id_categoria' => ''] + $pb)['errors']['id_categoria']));
same('mes 13', true, isset($pv->validate(['mes' => '13'] + $pb)['errors']['mes']));
same('año fuera de rango', true, isset($pv->validate(['anio' => '1999'] + $pb)['errors']['mes']));
same('límite cero', true, isset($pv->validate(['monto_limite' => '0'] + $pb)['errors']['monto_limite']));
same('límite negativo', true, isset($pv->validate(['monto_limite' => '-1'] + $pb)['errors']['monto_limite']));
same('límite que no es número', true, isset($pv->validate(['monto_limite' => 'x'] + $pb)['errors']['monto_limite']));
same('ids con forma de arreglo', true, isset($pv->validate(['id_categoria' => ['3']] + $pb)['errors']['id_categoria']));

section('MetaValidator');
$mv2 = new App\Validation\MetaValidator();
$hoy = '2026-11-24';
$mb = ['nombre' => '  Viaje   a Cartagena ', 'monto_objetivo' => '3.000.000', 'fecha_limite' => '2027-06-30'];
$rm = $mv2->validate($mb, $hoy);
same('una meta válida', [], $rm['errors']);
same('el nombre se limpia y el objetivo se normaliza', ['Viaje a Cartagena', '3000000.00', '2027-06-30'], [$rm['data']['nombre'], $rm['data']['monto_objetivo'], $rm['data']['fecha_limite']]);
same('la fecha límite es opcional', [null, []], [$mv2->validate(['fecha_limite' => ''] + $mb, $hoy)['data']['fecha_limite'], $mv2->validate(['fecha_limite' => ''] + $mb, $hoy)['errors']]);
same('hoy mismo es válido', [], $mv2->validate(['fecha_limite' => '2026-11-24'] + $mb, $hoy)['errors']);
same('una fecha pasada se rechaza', true, isset($mv2->validate(['fecha_limite' => '2026-11-23'] + $mb, $hoy)['errors']['fecha_limite']));
same('al editar, conservar una fecha ya vencida no se reprocha', [], $mv2->validate(['fecha_limite' => '2026-01-10'] + $mb, $hoy, '2026-01-10')['errors']);
same('pero cambiarla a otra pasada sí', true, isset($mv2->validate(['fecha_limite' => '2026-02-10'] + $mb, $hoy, '2026-01-10')['errors']['fecha_limite']));
same('una fecha imposible', true, isset($mv2->validate(['fecha_limite' => '2027-02-31'] + $mb, $hoy)['errors']['fecha_limite']));
same('nombre de 1 carácter', true, isset($mv2->validate(['nombre' => 'A'] + $mb, $hoy)['errors']['nombre']));
same('nombre de 81 caracteres', true, isset($mv2->validate(['nombre' => str_repeat('a', 81)] + $mb, $hoy)['errors']['nombre']));
same('objetivo cero', true, isset($mv2->validate(['monto_objetivo' => '0'] + $mb, $hoy)['errors']['monto_objetivo']));
same('objetivo inválido', true, isset($mv2->validate(['monto_objetivo' => 'mucho'] + $mb, $hoy)['errors']['monto_objetivo']));

section('AporteValidator');
$av = new App\Validation\AporteValidator();
$ab = ['id_cuenta' => '4', 'monto' => '100.000', 'fecha' => '2026-11-24'];
same('un aporte válido', [], $av->validate($ab)['errors']);
same('el monto se normaliza', '100000.00', $av->validate($ab)['data']['monto']);
same('sin cuenta', true, isset($av->validate(['id_cuenta' => ''] + $ab)['errors']['id_cuenta']));
same('monto cero', true, isset($av->validate(['monto' => '0'] + $ab)['errors']['monto']));
same('monto negativo', true, isset($av->validate(['monto' => '-5'] + $ab)['errors']['monto']));
same('fecha inválida', true, isset($av->validate(['fecha' => '2026-13-01'] + $ab)['errors']['fecha']));
same('fecha vacía', true, isset($av->validate(['fecha' => ''] + $ab)['errors']['fecha']));

section('Fecha::mesNombre');
same('nombre del mes', ['enero', 'diciembre'], [App\View\Fecha::mesNombre(1), App\View\Fecha::mesNombre(12)]);

section('Csv: inyección de fórmulas (RF-23)');
use App\Export\Csv as C;
same('una fórmula con = se neutraliza', "'=1+1", C::texto('=1+1'));
same('una con + se neutraliza', "'+cmd", C::texto('+cmd'));
same('una con - se neutraliza', "'-2", C::texto('-2'));
same('una con @ se neutraliza', "'@SUM(A1)", C::texto('@SUM(A1)'));
same('un HYPERLINK malicioso queda como texto (y entrecomillado por las comillas)', "\"'=HYPERLINK(\"\"http://evil\"\")\"", C::texto('=HYPERLINK("http://evil")'));
same('con espacios antes del = también', "'  =x", C::texto('  =x'));
same('con un espacio duro (NBSP) antes del = también', "'\u{00A0}=x", C::texto("\u{00A0}=x"));
same('un tabulador inicial se neutraliza (y se entrecomilla)', "\"'\t=x\"", C::texto("\t=x"));
same('un retorno de carro inicial se neutraliza (y se entrecomilla)', "\"'\r=x\"", C::texto("\r=x"));
same('un guion solo también', "'-", C::texto('-'));
same('un texto normal no se toca', 'Almuerzo con compañeros', C::texto('Almuerzo con compañeros'));
same('las tildes y signos de apertura no se tocan', '¡Hola! ¿Qué tal?', C::texto('¡Hola! ¿Qué tal?'));
same('un = en medio no es peligroso', 'x=1', C::texto('x=1'));
same('un texto vacío', '', C::texto(''));
same('un - en medio no es peligroso', 'bus - metro', C::texto('bus - metro'));

section('Csv: formato');
same('un texto con el separador se entrecomilla', '"a;b"', C::texto('a;b'));
same('las comillas se duplican', '"di ""hola"""', C::texto('di "hola"'));
same('un salto de línea se entrecomilla', "\"a\nb\"", C::texto("a\nb"));
same('una fecha ISO queda igual', '2026-11-03', C::celda('2026-11-03'));
same('una fila termina en CRLF y usa «;»', "a;b;c\r\n", C::fila(['a', 'b', 'c']));
same('el monto usa coma decimal', '18000,50', C::monto('18000.50'));
same('un entero con decimales cero', '1500000,00', C::monto('1500000.00'));
same('el BOM es el de UTF-8', "\xEF\xBB\xBF", C::BOM);

section('Dinero::abreviado (ejes de gráficos)');
same('cero', '$0', D::abreviado('0'));
same('pesos sueltos', '$500', D::abreviado('500.00'));
same('mil', '$1 mil', D::abreviado('1000'));
same('cientos de miles', '$850 mil', D::abreviado('850000'));
same('999.499 sigue en miles', '$999 mil', D::abreviado('999499'));
same('999.500 ya es un millón (no «1000 mil»)', '$1 M', D::abreviado('999500'));
same('un millón y pico con coma decimal', '$1,1 M', D::abreviado('1120000'));
same('1,6 millones', '$1,6 M', D::abreviado('1640000'));
same('1,95 millones se redondea a 2', '$2 M', D::abreviado('1950000'));
same('1.949.999 se redondea a 1,9', '$1,9 M', D::abreviado('1949999'));
same('varios millones', '$12,3 M', D::abreviado('12345678'));
same('un negativo', '−$1,1 M', D::abreviado('-1120000'));
same('un negativo pequeño', '−$500', D::abreviado('-500'));

section('Fecha::diaMes');
same('día y mes de una fecha sin hora', '03 nov', App\View\Fecha::diaMes('2026-11-03'));
same('enero', '15 ene', App\View\Fecha::diaMes('2027-01-15'));
same('una fecha inválida se devuelve igual', 'nada', App\View\Fecha::diaMes('nada'));
same('un mes imposible se devuelve igual', '2026-13-03', App\View\Fecha::diaMes('2026-13-03'));

section('Reportes\Categorias::agrupar');
use App\Reportes\Categorias as K;
$fila = static fn (string $n, string $t): array => ['nombre' => $n, 'total' => $t];
$g3 = K::agrupar([$fila('A', '500.00'), $fila('B', '300.00'), $fila('C', '200.00')]);
same('tres categorías van sueltas', ['A', 'B', 'C'], array_column($g3['items'], 'label'));
same('con sus porcentajes', [50.0, 30.0, 20.0], array_column($g3['items'], 'percent'));
same('y el total', '1000.00', $g3['total']);
same('cinco categorías (4 + 1) también van sueltas: agrupar una sola en «Otros» no ahorra nada', 5, count(K::agrupar([$fila('A', '5'), $fila('B', '4'), $fila('C', '3'), $fila('D', '2'), $fila('E', '1')])['items']));
$g7 = K::agrupar([$fila('A', '400'), $fila('B', '300'), $fila('C', '100'), $fila('D', '80'), $fila('E', '60'), $fila('F', '40'), $fila('G', '20')]);
same('siete categorías: las 4 mayores y «Otros»', ['A', 'B', 'C', 'D', 'Otros'], array_column($g7['items'], 'label'));
same('«Otros» suma el resto exacto', '120.00', $g7['items'][4]['total']);
same('los porcentajes suman 100', 100.0, round(array_sum(array_column($g7['items'], 'percent')), 0));
same('el 33,3 % de un tercio', 33.3, K::agrupar([$fila('A', '1'), $fila('B', '1'), $fila('C', '1')])['items'][0]['percent']);
same('sin gastos', ['items' => [], 'total' => '0.00'], K::agrupar([]));
same('con total cero no divide por cero', 0.0, K::agrupar([$fila('A', '0.00')])['items'][0]['percent']);

section('Mail\Message: construcción de correos (RF-04)');
use App\Mail\Message as Msg;
$fecha = 'Tue, 24 Nov 2026 10:00:00 -0500';
$msg = Msg::build('lucas@ejemplo.com', 'Lucas', 'ana@correo.com', 'Restablece tu contraseña', "Hola María,\n.línea con punto\nFin", $fecha, 'abc123@ejemplo.com');
[$cabeceras, $cuerpo] = explode("\r\n\r\n", $msg, 2);
same('lleva From, To y Date', true, str_contains($cabeceras, 'From: Lucas <lucas@ejemplo.com>') && str_contains($cabeceras, 'To: <ana@correo.com>') && str_contains($cabeceras, "Date: $fecha"));
same('el asunto con tildes va codificado (RFC 2047)', 'Subject: =?UTF-8?B?' . base64_encode('Restablece tu contraseña') . '?=', explode("\r\n", $cabeceras)[3]);
same('declara UTF-8 y base64', true, str_contains($cabeceras, 'charset=UTF-8') && str_contains($cabeceras, 'Content-Transfer-Encoding: base64'));
same('el cuerpo decodifica exactamente al texto original (con tildes y líneas que empiezan con punto)', "Hola María,\n.línea con punto\nFin", rtrim(base64_decode(str_replace("\r\n", '', $cuerpo)), "\n"));
same('ninguna línea del cuerpo pasa de 76 caracteres', true, (static function () use ($cuerpo): bool {
    foreach (explode("\r\n", $cuerpo) as $linea) {
        if (strlen($linea) > 76) {
            return false;
        }
    }
    return true;
})());
same('un asunto ASCII no se codifica', 'Hola', Msg::encodeHeader('Hola'));
same('el asunto con eñe sí', true, str_starts_with(Msg::encodeHeader('Ñandú'), '=?UTF-8?B?'));
foreach ([
    'un salto de línea en el destinatario (Bcc inyectado)' => ['lucas@ejemplo.com', 'Lucas', "ana@correo.com\r\nBcc: espia@evil.com", 'Asunto'],
    'un salto de línea en el asunto' => ['lucas@ejemplo.com', 'Lucas', 'ana@correo.com', "Asunto\r\nBcc: espia@evil.com"],
    'un salto de línea en el remitente' => ["lucas@ejemplo.com\nBcc: x@y.com", 'Lucas', 'ana@correo.com', 'Asunto'],
    'un salto de línea en el nombre del remitente' => ['lucas@ejemplo.com', "Lucas\r\nBcc: x@y.com", 'ana@correo.com', 'Asunto'],
    'un carácter nulo en el asunto' => ['lucas@ejemplo.com', 'Lucas', 'ana@correo.com', "As\0unto"],
] as $caso => [$de, $nombre, $para, $asunto]) {
    same("se rechaza $caso", true, (static function () use ($de, $nombre, $para, $asunto, $fecha): bool {
        try {
            Msg::build($de, $nombre, $para, $asunto, 'x', $fecha, 'id@x.com');
        } catch (App\Mail\MailException) {
            return true;
        }
        return false;
    })());
}
same('un destinatario que no es un correo se rechaza', true, (static function () use ($fecha): bool {
    try {
        Msg::build('lucas@ejemplo.com', 'Lucas', 'no-es-un-correo', 'Asunto', 'x', $fecha, 'id@x.com');
    } catch (App\Mail\MailException) {
        return true;
    }
    return false;
})());
same('un destinatario con < o > (inyección en sobre SMTP) se rechaza', true, (static function () use ($fecha): bool {
    try {
        Msg::build('lucas@ejemplo.com', 'Lucas', '"a><b"@ejemplo.com', 'Asunto', 'x', $fecha, 'id@x.com');
    } catch (App\Mail\MailException) {
        return true;
    }
    return false;
})());
same('Logger::format neutraliza saltos de línea y caracteres de control (CWE-117)', '[test] /ruta falsa: fallo [31m limpio', (static function (): string {
    return App\Logging\Logger::format('test', "/ruta\r\nfalsa", new RuntimeException("fallo\x1b[31m\nlimpio"));
})());
same('dot-stuffing: una línea que empieza con punto lleva otro delante', "uno\r\n..dos\r\n...tres\r\n.\r\n", Msg::forData("uno\n.dos\n..tres"));
same('termina con la línea de un solo punto', true, str_ends_with(Msg::forData("a\nb"), "\r\n.\r\n"));
same('los fines de línea pasan a CRLF', "a\r\nb\r\n.\r\n", Msg::forData("a\nb\n"));
same('un punto en medio de la línea no se toca', "a.b\r\n.\r\n", Msg::forData('a.b'));

section('ClaveValidator, tokens y huella de sesión');
use App\Validation\ClaveValidator as CV;
same('una contraseña válida', [], CV::errores('secreta123', 'secreta123'));
same('muy corta', ['clave'], array_keys(CV::errores('corta', 'corta')));
same('de 8 caracteres exactos', [], CV::errores('12345678', '12345678'));
same('de más de 72 bytes', ['clave'], array_keys(CV::errores(str_repeat('a', 73), str_repeat('a', 73))));
same('72 bytes exactos', [], CV::errores(str_repeat('a', 72), str_repeat('a', 72)));
same('que no coincide', ['clave2'], array_keys(CV::errores('secreta123', 'otra-clave')));
same('con otros nombres de campo', ['clave_nueva2'], array_keys(CV::errores('secreta123', 'x', 'clave_nueva', 'clave_nueva2')));
same('el registro usa las mismas reglas', true, CV::MIN === App\Validation\RegistroValidator::CLAVE_MIN && CV::MAX_BYTES === App\Validation\RegistroValidator::CLAVE_MAX_BYTES);
use App\Auth\RecuperacionClave as RC;
same('un token de 64 hexadecimales tiene forma válida', true, RC::formaValida(str_repeat('ab12', 16)));
same('uno corto no', false, RC::formaValida('abc'));
same('uno con mayúsculas no', false, RC::formaValida(str_repeat('AB12', 16)));
same('uno con caracteres raros no', false, RC::formaValida(str_repeat('ab1;', 16)));
same('uno vacío no', false, RC::formaValida(''));
same('el hash de un token es SHA-256 (64 hexadecimales) y distinto del token', true, RC::hashToken(str_repeat('ab12', 16)) !== str_repeat('ab12', 16) && strlen(RC::hashToken('x')) === 64);
same('la huella de sesión es estable', password_fingerprint('hash-1'), password_fingerprint('hash-1'));
same('y cambia si cambia el hash', true, password_fingerprint('hash-1') !== password_fingerprint('hash-2'));
same('no contiene el hash', false, str_contains(password_fingerprint('hash-secreto'), 'secreto'));

section('PerfilValidator y Usuario');
$pf = new App\Validation\PerfilValidator();
same('datos válidos', [], $pf->validate(['nombre' => ' Ana  María ', 'moneda' => 'USD'])['errors']);
same('el nombre se limpia', 'Ana María', $pf->validate(['nombre' => ' Ana  María ', 'moneda' => 'COP'])['data']['nombre']);
same('nombre corto', true, isset($pf->validate(['nombre' => 'A', 'moneda' => 'COP'])['errors']['nombre']));
same('nombre de 81 caracteres', true, isset($pf->validate(['nombre' => str_repeat('a', 81), 'moneda' => 'COP'])['errors']['nombre']));
same('moneda desconocida', true, isset($pf->validate(['nombre' => 'Ana', 'moneda' => 'XXX'])['errors']['moneda']));
same('moneda con forma de arreglo', true, isset($pf->validate(['nombre' => 'Ana', 'moneda' => ['COP']])['errors']['moneda']));
$u0 = new App\Domain\Usuario(1, 'Ana', 'a@x.com', 'hash-viejo', 'COP', 'usuario', true);
$u1 = $u0->conContrasenaHash('hash-nuevo');
same('conContrasenaHash cambia solo el hash', ['hash-nuevo', 1, 'Ana', 'a@x.com'], [$u1->contrasenaHash, $u1->id, $u1->nombre, $u1->correo]);
same('y no toca el original (inmutable)', 'hash-viejo', $u0->contrasenaHash);

section('Moneda (RF-03)');
same('por defecto «$»', '$1.250', D::formatear('1250.00'));
D::usarMoneda('USD');
same('USD', 'US$1.250', D::formatear('1250.00'));
same('con decimales', 'US$1.250,50', D::formatear('1250.50'));
same('negativo', '−US$80', D::formatear('-80.00'));
same('abreviado', 'US$1,1 M', D::abreviado('1120000'));
same('Ui::money con una cadena', '<span class="money money--in">+US$18.000,50</span>', (string) App\View\Ui::money('18000.50', 'in'));
same('Ui::money con un entero', '<span class="money money--plain">US$5.000</span>', (string) App\View\Ui::money(5000));
D::usarMoneda('EUR');
same('EUR', '€1.250', D::formatear('1250.00'));
D::usarMoneda('MXN');
same('MXN', 'MX$1.250', D::formatear('1250.00'));
D::usarMoneda('XXX');
same('una moneda desconocida cae a «$»', '$1.250', D::formatear('1250.00'));
D::usarMoneda('COP');
same('COP es «$» otra vez', '$1.250', D::formatear('1250.00'));
same('el símbolo actual', '$', D::simbolo());
same('toda moneda del perfil tiene símbolo', true, array_reduce(array_values(D::MONEDAS), static fn (bool $ok, string $s): bool => $ok && $s !== '', true));

section('SqlScript');
use App\Database\SqlScript as SQ;
same('parte en sentencias y quita comentarios', ['CREATE TABLE a (x INT)', "INSERT INTO a VALUES (1)"], SQ::statements("-- comentario\nCREATE TABLE a (x INT);\n\n-- otro\nINSERT INTO a VALUES (1);\n"));
same('ignora lo vacío', [], SQ::statements("-- nada\n\n;\n"));
same('una sentencia sin «;» final', ['SELECT 1'], SQ::statements('SELECT 1'));
same('un «;» dentro de una línea no corta', ["INSERT INTO t VALUES ('a;b')"], SQ::statements("INSERT INTO t VALUES ('a;b');"));
same('las migraciones del proyecto se parten bien', true, count(SQ::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/001-recuperacion-de-clave.sql'))) === 2);

section('TransferenciaValidator y ReglasTransferencia (Fase 8)');
use App\Transferencias\ReglasTransferencia as RT;
$tv = new App\Validation\TransferenciaValidator();
$tb = ['id_cuenta_origen' => '1', 'id_cuenta_destino' => '2', 'monto' => '150.000,50', 'fecha' => '2026-11-25', 'descripcion' => '  Pago   tarjeta '];
$tr = $tv->validate($tb);
same('una transferencia válida no da errores', [], $tr['errors']);
same('el monto y la descripción se normalizan', ['150000.50', 'Pago tarjeta', 1, 2], [$tr['data']['monto'], $tr['data']['descripcion'], $tr['data']['id_cuenta_origen'], $tr['data']['id_cuenta_destino']]);
same('misma cuenta origen y destino se rechaza', true, isset($tv->validate(['id_cuenta_destino' => '1'] + $tb)['errors']['id_cuenta_destino']));
same('sin cuenta de origen se rechaza', true, isset($tv->validate(['id_cuenta_origen' => ''] + $tb)['errors']['id_cuenta_origen']));
same('sin cuenta de destino se rechaza', true, isset($tv->validate(['id_cuenta_destino' => ''] + $tb)['errors']['id_cuenta_destino']));
same('monto cero se rechaza', true, isset($tv->validate(['monto' => '0'] + $tb)['errors']['monto']));
same('monto negativo se rechaza', true, isset($tv->validate(['monto' => '-100'] + $tb)['errors']['monto']));
same('fecha inválida se rechaza', true, isset($tv->validate(['fecha' => '2026-02-31'] + $tb)['errors']['fecha']));
$cActiva = ['nombre' => 'Ahorros', 'tipo' => 'ahorro', 'activa' => true];
$cTarjeta = ['nombre' => 'Visa', 'tipo' => 'tarjeta_credito', 'activa' => true];
$cInactiva = ['nombre' => 'Vieja', 'tipo' => 'efectivo', 'activa' => false];
same('transferencia válida entre dos cuentas activas con saldo', RT::OK, RT::evaluar($cActiva, $cTarjeta, 1, 2, '200000.00', '150000.00'));
same('transferencia por el total del saldo disponible es válida', RT::OK, RT::evaluar($cActiva, $cTarjeta, 1, 2, '150000.00', '150000.00'));
same('un centavo más que el saldo disponible se rechaza', RT::SALDO_INSUFICIENTE, RT::evaluar($cActiva, $cTarjeta, 1, 2, '150000.00', '150000.01'));
same('avance desde tarjeta de crédito sí puede dejarla en negativo', RT::OK, RT::evaluar($cTarjeta, $cActiva, 2, 1, '0.00', '300000.00'));
same('cuenta origen ajena o inexistente', RT::CUENTA_AJENA, RT::evaluar(null, $cActiva, 1, 2, '100.00', '10.00'));
same('cuenta destino ajena o inexistente', RT::CUENTA_AJENA, RT::evaluar($cActiva, null, 1, 2, '100.00', '10.00'));
same('misma cuenta en ReglasTransferencia', RT::MISMA_CUENTA, RT::evaluar($cActiva, $cActiva, 1, 1, '100.00', '10.00'));
same('cuenta origen inactiva', RT::ORIGEN_INACTIVA, RT::evaluar($cInactiva, $cActiva, 1, 2, '100.00', '10.00'));
same('cuenta destino inactiva', RT::DESTINO_INACTIVA, RT::evaluar($cActiva, $cInactiva, 1, 2, '100.00', '10.00'));

section('Frecuencia y RecurrenteValidator (Fase 9)');
use App\Support\Frecuencia as FQ;
same('semanal suma 7 días', '2026-11-10', FQ::siguiente('2026-11-03', 'semanal'));
same('quincenal suma 15 días', '2026-11-18', FQ::siguiente('2026-11-03', 'quincenal'));
same('mensual normal mantiene el día', '2026-12-15', FQ::siguiente('2026-11-15', 'mensual'));
same('mensual en fin de mes (31 de enero -> 28 de febrero en año no bisiesto)', '2026-02-28', FQ::siguiente('2026-01-31', 'mensual'));
same('mensual en fin de mes (31 de enero -> 29 de febrero en año bisiesto)', '2028-02-29', FQ::siguiente('2028-01-31', 'mensual'));
same('anual normal mantiene día y mes', '2027-11-25', FQ::siguiente('2026-11-25', 'anual'));
same('anual desde 29 de febrero bisiesto pasa al 28 de febrero', '2029-02-28', FQ::siguiente('2028-02-29', 'anual'));
same('equivalente mensual de gasto mensual', '120000.00', FQ::equivalenteMensual('120000.00', 'mensual'));
same('equivalente mensual de gasto quincenal (×2)', '240000.00', FQ::equivalenteMensual('120000.00', 'quincenal'));
same('equivalente mensual de gasto anual (÷12)', '10000.00', FQ::equivalenteMensual('120000.00', 'anual'));
$rv = new App\Validation\RecurrenteValidator();
$rb = ['tipo' => 'gasto', 'monto' => '85.000', 'frecuencia' => 'mensual', 'proxima_fecha' => '2026-12-01', 'id_cuenta' => '1', 'id_categoria' => '2', 'descripcion' => '  Internet   hogar ', 'auto_aplicar' => '1'];
$rr = $rv->validate($rb);
same('un recurrente válido no da errores', [], $rr['errors']);
same('datos normalizados en RecurrenteValidator', ['85000.00', 'mensual', 'Internet hogar', true], [$rr['data']['monto'], $rr['data']['frecuencia'], $rr['data']['descripcion'], $rr['data']['auto_aplicar']]);
same('auto_aplicar=0 queda en false', false, $rv->validate(['auto_aplicar' => '0'] + $rb)['data']['auto_aplicar']);
same('frecuencia inválida se rechaza', true, isset($rv->validate(['frecuencia' => 'trimestral'] + $rb)['errors']['frecuencia']));
same('proxima_fecha inválida se rechaza', true, isset($rv->validate(['proxima_fecha' => '2026-02-30'] + $rb)['errors']['proxima_fecha']));

section('ReglasEspacio y EspacioValidator (Fase 10)');
use App\Espacios\ReglasEspacio as RE;
same('invitación dentro de todos los límites', RE::OK, RE::evaluarLimitesInvitacion(0, 0, 0, false));
same('9 enviadas hoy aún permite la décima', RE::OK, RE::evaluarLimitesInvitacion(9, 0, 0, false));
same('10 enviadas hoy: límite por usuario', RE::LIMITE_USUARIO, RE::evaluarLimitesInvitacion(10, 0, 0, false));
same('2 recibidas por esa dirección aún permite la tercera', RE::OK, RE::evaluarLimitesInvitacion(0, 2, 0, false));
same('3 recibidas por esa dirección: límite por destinatario', RE::LIMITE_DESTINO, RE::evaluarLimitesInvitacion(0, 3, 0, false));
same('19 pendientes en el espacio aún permite una más', RE::OK, RE::evaluarLimitesInvitacion(0, 0, 19, false));
same('20 pendientes: límite del espacio', RE::LIMITE_PENDIENTES, RE::evaluarLimitesInvitacion(0, 0, 20, false));
same('con 20 pendientes, reinvitar a una dirección que ya tiene una sí se permite', RE::OK, RE::evaluarLimitesInvitacion(0, 0, 20, true));
same('el límite por usuario manda sobre los demás', RE::LIMITE_USUARIO, RE::evaluarLimitesInvitacion(10, 3, 20, false));
same('los límites son 10 por usuario, 3 por destinatario, 20 pendientes y 24 h', [10, 3, 20, 86400], [RE::INVITACIONES_POR_USUARIO, RE::INVITACIONES_POR_DESTINO, RE::INVITACIONES_PENDIENTES_POR_ESPACIO, RE::VENTANA_INVITACIONES]);
same('en el espacio personal (null) siempre se puede escribir', true, RE::puedeEscribir(null));
same('propietario y editor pueden escribir', [true, true], [RE::puedeEscribir('propietario'), RE::puedeEscribir('editor')]);
same('lector NO puede escribir', false, RE::puedeEscribir('lector'));
same('cambiar rol sin ser propietario se rechaza', RE::SIN_PERMISO, RE::evaluarCambioRol('editor', 2, 'lector', 'editor', [1]));
same('el único propietario no puede quitarse el rol de propietario', RE::ULTIMO_PROPIETARIO, RE::evaluarCambioRol('propietario', 1, 'propietario', 'editor', [1]));
same('habiendo dos propietarios sí se puede degradar a uno', RE::OK, RE::evaluarCambioRol('propietario', 1, 'propietario', 'editor', [1, 2]));
same('el único propietario no puede abandonar el espacio', RE::ULTIMO_PROPIETARIO, RE::evaluarSalidaMiembro(1, 'propietario', 1, 'propietario', [1]));
same('un editor puede abandonar el espacio por sí mismo', RE::OK, RE::evaluarSalidaMiembro(2, 'editor', 2, 'editor', [1]));
same('un editor no puede retirar a otro miembro', RE::SIN_PERMISO, RE::evaluarSalidaMiembro(2, 'editor', 3, 'lector', [1]));
same('un propietario puede retirar a un editor', RE::OK, RE::evaluarSalidaMiembro(1, 'propietario', 2, 'editor', [1]));
$ev = new App\Validation\EspacioValidator();
same('espacio válido', [], $ev->validateEspacio(['nombre' => ' Hogar Familia ', 'moneda' => 'COP'])['errors']);
same('nombre corto en espacio', true, isset($ev->validateEspacio(['nombre' => 'A', 'moneda' => 'COP'])['errors']['nombre']));
same('invitación válida', [], $ev->validateInvitacion(['correo' => 'Pareja@Correo.com ', 'rol' => 'editor'])['errors']);
same('invitación con rol propietario no se permite directamente', true, isset($ev->validateInvitacion(['correo' => 'a@b.com', 'rol' => 'propietario'])['errors']['rol']));

section('Deuda, ReglasPagoDeuda y PlanificadorDeudas: Bola de Nieve vs. Avalancha (Fase 11)');
use App\Domain\Deuda;
use App\Deudas\ReglasPagoDeuda as RPD;
use App\Deudas\PlanificadorDeudas as PD;
use App\Validation\DeudaValidator;
same('parseTasaBps convierte porcentaje mensual a puntos básicos enteros', [185, 200, 0, 50], [
    DeudaValidator::parseTasaBps('1,85'),
    DeudaValidator::parseTasaBps('2 %'),
    DeudaValidator::parseTasaBps('0'),
    DeudaValidator::parseTasaBps('0.5'),
]);
same('calcularInteres de 1.000.000 al 1,85 % M.V. (185 bps) = 18.500,00', '18500.00', Deuda::calcularInteres('1000000.00', 185));
$cuentaDeudaOk = ['nombre' => 'Ahorros', 'tipo' => 'ahorro', 'activa' => true];
$infoDeuda = ['estado' => 'activa', 'saldo_capital' => '1000000.00', 'tasa_mensual_bps' => 200]; // 2% M.V. -> interés = 20.000
$pagoCuota = RPD::evaluar($cuentaDeudaOk, $infoDeuda, '500000.00', '120000.00', 'cuota');
same('en pago de cuota de 120.000 con interés de 20.000: 20.000 van a interés y 100.000 a capital', ['ok', '100000.00', '20000.00', '900000.00'], [
    $pagoCuota['codigo'],
    $pagoCuota['abono_capital'],
    $pagoCuota['pago_interes'],
    $pagoCuota['nuevo_saldo_capital'],
]);
$pagoExtra = RPD::evaluar($cuentaDeudaOk, $infoDeuda, '500000.00', '120000.00', 'capital');
same('en abono extraordinario a capital: 100 % va a capital y 0 a interés', ['ok', '120000.00', '0.00', '880000.00'], [
    $pagoExtra['codigo'],
    $pagoExtra['abono_capital'],
    $pagoExtra['pago_interes'],
    $pagoExtra['nuevo_saldo_capital'],
]);
same('si el abono supera el saldo de la deuda se rechaza', RPD::SUPERA_SALDO_DEUDA, RPD::evaluar($cuentaDeudaOk, $infoDeuda, '5000000.00', '1500000.00', 'capital')['codigo']);
same('si la cuenta no tiene saldo suficiente se rechaza', RPD::SALDO_INSUFICIENTE, RPD::evaluar($cuentaDeudaOk, $infoDeuda, '50000.00', '120000.00', 'cuota')['codigo']);

$dPequenaBarata = new Deuda(1, 1, 'Tienda (pequeña)', null, '400000.00', 50, '100000.00', 10, 'activa'); // 0,5% M.V., saldo 400k
$dGrandeCara = new Deuda(2, 1, 'Tarjeta (cara)', null, '1200000.00', 250, '150000.00', 15, 'activa');    // 2,5% M.V., saldo 1.2M
$comp = PD::comparar([$dPequenaBarata, $dGrandeCara], '100000.00');
same('Bola de Nieve ataca primero la deuda más pequeña', 'Tienda (pequeña)', $comp['bolaNieve']['primeraDeuda']);
same('Avalancha ataca primero la deuda con mayor tasa de interés', 'Tarjeta (cara)', $comp['avalancha']['primeraDeuda']);
same('Avalancha paga menos o igual intereses totales que Bola de Nieve y que solo mínimos', true,
    D::aCentavos($comp['avalancha']['intereses']) <= D::aCentavos($comp['bolaNieve']['intereses'])
    && D::aCentavos($comp['bolaNieve']['intereses']) < D::aCentavos($comp['minimos']['intereses'])
);

section('Asistente503020: distribución entera 50/30/20 (Fase 12)');
use App\Presupuestos\Asistente503020 as A50;
use App\Domain\Categoria as Cat;
$cats50 = [
    new Cat(1, null, 'Alimentación', 'gasto', '#E4572E'),
    new Cat(2, null, 'Vivienda', 'gasto', '#7A5195'),
    new Cat(3, null, 'Entretenimiento', 'gasto', '#EF8354'),
];
$dist = A50::distribuir('3000000.00', $cats50);
same('50 % necesidades, 30 % estilo de vida, 20 % ahorro/deudas de 3.000.000', ['1500000.00', '900000.00', '600000.00'], [
    $dist['necesidades'],
    $dist['estiloVida'],
    $dist['ahorroDeudas'],
]);
same('la suma de las sugerencias por categoría cuadra al centavo con 50 % + 30 %', '2400000.00', D::sumar(array_column($dist['sugerencias'], 'monto')));

section('ImportadorCsv: parseo de texto CSV bancario (Fase 13)');
use App\Movimientos\ImportadorCsv as IC;
$csvSample = "Fecha;Descripción;Monto\n2026-11-20;Supermercado Éxito;-185.000,50\n25/11/2026;Nómina quincenal;+2.500.000";
$resCsv = IC::parsearTexto($csvSample);
same('parsearTexto con cabecera y 2 líneas válidas no da errores', [], $resCsv['errores']);
same('reconoce gasto con signo menos y fecha ISO', ['2026-11-20', 'gasto', '185000.50', 'Supermercado Éxito'], [
    $resCsv['filas'][0]['fecha'],
    $resCsv['filas'][0]['tipo'],
    $resCsv['filas'][0]['monto'],
    $resCsv['filas'][0]['descripcion'],
]);
same('reconoce ingreso con signo + y fecha DD/MM/AAAA', ['2026-11-25', 'ingreso', '2500000.00', 'Nómina quincenal'], [
    $resCsv['filas'][1]['fecha'],
    $resCsv['filas'][1]['tipo'],
    $resCsv['filas'][1]['monto'],
    $resCsv['filas'][1]['descripcion'],
]);
same('una fecha inválida en CSV reporta la línea con error', 1, count(IC::parsearTexto("2026-02-31;Raro;-100")['errores']));

section('MovimientoValidator: etiquetas transversales y división de movimientos (Fase 14)');
$mv14 = new App\Validation\MovimientoValidator();
same('normalizarEtiqueta quita #, pasa a minúsculas y cambia espacios por guiones', [
    'vacaciones-2026',
    'remodelación',
    'impuestos_2026',
    null,
    null,
], [
    App\Validation\MovimientoValidator::normalizarEtiqueta(' #Vacaciones 2026 '),
    App\Validation\MovimientoValidator::normalizarEtiqueta('#Remodelación'),
    App\Validation\MovimientoValidator::normalizarEtiqueta('impuestos_2026'),
    App\Validation\MovimientoValidator::normalizarEtiqueta('invalida/con/slash'),
    App\Validation\MovimientoValidator::normalizarEtiqueta(str_repeat('a', 41)),
]);
$valTag = $mv14->validate([
    'tipo' => 'gasto',
    'monto' => '50.000',
    'fecha' => '2026-11-25',
    'id_cuenta' => '1',
    'id_categoria' => '2',
    'descripcion' => 'Hotel',
    'etiqueta' => '#Viaje Cartagena',
]);
same('validate normaliza la etiqueta válida', [[], 'viaje-cartagena'], [$valTag['errors'], $valTag['data']['etiqueta']]);
$valSplitOk = $mv14->validateSplit([
    'tipo' => 'gasto',
    'monto' => '300.000',
    'fecha' => '2026-11-25',
    'id_cuenta' => '1',
    'descripcion' => 'Supermercado Éxito',
    'etiqueta' => '#hogar-2026',
    'split_categoria' => ['2', '3', '', ''],
    'split_monto' => ['220.000', '80.000', '', ''],
    'split_descripcion' => ['Mercado alimentos', '', '', ''],
]);
same('validateSplit con 2 partes cuya suma coincide con el total', [[], 2, '300000.00', 'hogar-2026', 'Mercado alimentos', 'Supermercado Éxito'], [
    $valSplitOk['errors'],
    count($valSplitOk['partes']),
    $valSplitOk['base']['monto_total'],
    $valSplitOk['base']['etiqueta'],
    $valSplitOk['partes'][0]['descripcion'],
    $valSplitOk['partes'][1]['descripcion'],
]);
$valSplitDescuadre = $mv14->validateSplit([
    'tipo' => 'gasto',
    'monto' => '300.000',
    'fecha' => '2026-11-25',
    'id_cuenta' => '1',
    'split_categoria' => ['2', '3'],
    'split_monto' => ['200.000', '50.000'],
]);
same('validateSplit rechaza cuando la suma de las partes no coincide con el monto total', true, isset($valSplitDescuadre['errors']['split']));
$valSplitUnaSola = $mv14->validateSplit([
    'tipo' => 'gasto',
    'monto' => '200.000',
    'fecha' => '2026-11-25',
    'id_cuenta' => '1',
    'split_categoria' => ['2', ''],
    'split_monto' => ['200.000', ''],
]);
same('validateSplit exige al menos 2 partes', true, isset($valSplitUnaSola['errors']['split']));
same('Filtros::desde incluye y normaliza la etiqueta', 'vacaciones-2026', App\Movimientos\Filtros::desde(['etiqueta' => '#Vacaciones 2026'])['etiqueta']);

section('PlanCuotas y CicloTarjeta: compras a cuotas, fecha de corte y fecha de pago (Fase 15)');
use App\Tarjetas\PlanCuotas as PC;
use App\Tarjetas\CicloTarjeta as CT;
$cuotas3 = PC::generar('100000.00', 3, '2026-11-10', 15);
same('100.000 en 3 cuotas reparte los centavos exactos: 33.333,34 + 33.333,33 + 33.333,33', [
    '33333.34',
    '33333.33',
    '33333.33',
    '100000.00',
], [
    $cuotas3[0]['monto_capital'],
    $cuotas3[1]['monto_capital'],
    $cuotas3[2]['monto_capital'],
    D::sumar(array_column($cuotas3, 'monto_capital')),
]);
same('compra el día 10 con corte el 15: la cuota 1 entra en el corte del mismo mes (2026-11-15)', [
    '2026-11-15',
    '2026-12-15',
    '2027-01-15',
], array_column($cuotas3, 'fecha_cobro'));

$cuotasPostCorte = PC::generar('600000.00', 2, '2026-11-20', 15);
same('compra el día 20 (después del corte del 15): la cuota 1 pasa al corte del mes siguiente (2026-12-15)', [
    '2026-12-15',
    '2027-01-15',
], array_column($cuotasPostCorte, 'fecha_cobro'));

$cuotasFeb = PC::generar('200000.00', 2, '2026-01-10', 31);
same('corte día 31 se ajusta al último día de febrero no bisiesto (28)', ['2026-01-31', '2026-02-28'], array_column($cuotasFeb, 'fecha_cobro'));

same('CicloTarjeta::proximaFecha calcula próximo corte y días restantes', [
    ['fecha' => '2026-11-15', 'dias' => 5],
    ['fecha' => '2026-12-15', 'dias' => 25],
], [
    CT::proximaFecha(15, '2026-11-10'),
    CT::proximaFecha(15, '2026-11-20'),
]);
same('CicloTarjeta::ventanaCicloActual devuelve la ventana entre el corte anterior + 1 día y el próximo corte', [
    '2026-10-16',
    '2026-11-15',
], CT::ventanaCicloActual(15, '2026-11-10'));

$cv15 = new App\Validation\CuentaValidator();
$valTc = $cv15->validate(['nombre' => 'Visa Oro', 'tipo' => 'tarjeta_credito', 'saldo_inicial' => '0', 'dia_corte' => '15', 'dia_pago_limite' => '28']);
same('CuentaValidator acepta dia_corte y dia_pago_limite en tarjeta_credito', [[], 15, 28], [$valTc['errors'], $valTc['data']['dia_corte'], $valTc['data']['dia_pago_limite']]);
same('CuentaValidator ignora dia_corte en cuenta de ahorros', null, $cv15->validate(['nombre' => 'Ahorros', 'tipo' => 'ahorro', 'saldo_inicial' => '0', 'dia_corte' => '15'])['data']['dia_corte']);
same('CuentaValidator rechaza dia_corte fuera de 1..31', true, isset($cv15->validate(['nombre' => 'Visa', 'tipo' => 'tarjeta_credito', 'dia_corte' => '35'])['errors']['dia_corte']));
same('MovimientoValidator valida num_cuotas entre 1 y 36', [6, true], [
    $mv14->validate(['tipo' => 'gasto', 'monto' => '100.000', 'fecha' => '2026-11-25', 'id_cuenta' => '1', 'id_categoria' => '2', 'num_cuotas' => '6'])['data']['num_cuotas'],
    isset($mv14->validate(['tipo' => 'gasto', 'monto' => '100.000', 'fecha' => '2026-11-25', 'id_cuenta' => '1', 'id_categoria' => '2', 'num_cuotas' => '48'])['errors']['num_cuotas']),
]);

section('Intereses en compras a cuotas con tarjeta de crédito (Fase 15b)');
same('por defecto MovimientoValidator marca con_intereses = true', [true, false], [
    $mv14->validate(['tipo' => 'gasto', 'monto' => '100.000', 'fecha' => '2026-11-25', 'id_cuenta' => '1', 'id_categoria' => '2', 'num_cuotas' => '6'])['data']['con_intereses'],
    $mv14->validate(['tipo' => 'gasto', 'monto' => '100.000', 'fecha' => '2026-11-25', 'id_cuenta' => '1', 'id_categoria' => '2', 'num_cuotas' => '6', 'con_intereses' => '0'])['data']['con_intereses'],
]);
// 300.000 a 3 cuotas con tasa 2 % M.V. (200 bps):
// Cuota 1: saldo 300.000 -> capital 100.000 + interés 6.000 = 106.000
// Cuota 2: saldo 200.000 -> capital 100.000 + interés 4.000 = 104.000
// Cuota 3: saldo 100.000 -> capital 100.000 + interés 2.000 = 102.000
// Interés total = 12.000
$cuotasConInt = PC::generar('300000.00', 3, '2026-11-10', 15, true, 200);
same('300.000 a 3 cuotas con 2 % M.V. calcula interés sobre saldo pendiente (6.000, 4.000, 2.000)', [
    ['100000.00', '6000.00', '106000.00'],
    ['100000.00', '4000.00', '104000.00'],
    ['100000.00', '2000.00', '102000.00'],
    '12000.00',
], [
    [$cuotasConInt[0]['monto_capital'], $cuotasConInt[0]['monto_interes'], $cuotasConInt[0]['monto_total']],
    [$cuotasConInt[1]['monto_capital'], $cuotasConInt[1]['monto_interes'], $cuotasConInt[1]['monto_total']],
    [$cuotasConInt[2]['monto_capital'], $cuotasConInt[2]['monto_interes'], $cuotasConInt[2]['monto_total']],
    PC::interesTotalCompra('300000.00', 3, true, 200),
]);
$cuotasSinInt = PC::generar('300000.00', 3, '2026-11-10', 15, false, 200);
same('si el usuario indica sin intereses (con_intereses=false), el interés es 0 aunque la tarjeta tenga tasa', [
    '0.00',
    '100000.00',
    '0.00',
], [
    $cuotasSinInt[0]['monto_interes'],
    $cuotasSinInt[0]['monto_total'],
    PC::interesTotalCompra('300000.00', 3, false, 200),
]);
$cuota1Contado = PC::generar('300000.00', 1, '2026-11-10', 15, true, 200);
same('a 1 sola cuota nunca se cobran intereses de diferido', '0.00', $cuota1Contado[0]['monto_interes']);
same('CuentaValidator valida tasa_mensual en tarjeta_credito', [215, true], [
    $cv15->validate(['nombre' => 'Visa', 'tipo' => 'tarjeta_credito', 'tasa_mensual' => '2,15'])['data']['tasa_mensual_bps'],
    isset($cv15->validate(['nombre' => 'Visa', 'tipo' => 'tarjeta_credito', 'tasa_mensual' => '25'])['errors']['tasa_mensual']),
]);

section('PWA: manifest.webmanifest, iconos y Service Worker (Fase 16)');
$publicDir = dirname(__DIR__) . '/public';
$manifestRaw = (string) file_get_contents($publicDir . '/manifest.webmanifest');
$manifestJson = json_decode($manifestRaw, true);
same('manifest.webmanifest es JSON válido con display=standalone y start_url=/panel.php', [
    true,
    'Lucas',
    'standalone',
    '/panel.php',
    '#061A24',
], [
    is_array($manifestJson),
    $manifestJson['short_name'] ?? '',
    $manifestJson['display'] ?? '',
    $manifestJson['start_url'] ?? '',
    $manifestJson['theme_color'] ?? '',
]);
same('todos los iconos declarados en manifest.webmanifest existen en public/', true, (static function () use ($manifestJson, $publicDir): bool {
    foreach ($manifestJson['icons'] ?? [] as $ic) {
        if (!is_file($publicDir . ($ic['src'] ?? ''))) {
            return false;
        }
    }
    return is_file($publicDir . '/icons/apple-touch-icon.png')
        && is_file($publicDir . '/sw.js')
        && is_file($publicDir . '/offline.html');
})());

section('DispositivoSesion y SesionRepository::hashId (Fase 17)');
use App\Support\DispositivoSesion as DS;
use App\Repository\SesionRepository as SR;
same('DispositivoSesion::describir reconoce navegadores y sistemas operativos', [
    'Firefox en Linux',
    'Chrome en Android',
    'Safari en iPhone',
    'Edge en Windows',
    'cURL / CLI',
    'Dispositivo desconocido',
], [
    DS::describir('Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0'),
    DS::describir('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'),
    DS::describir('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'),
    DS::describir('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0'),
    DS::describir('curl/8.5.0'),
    DS::describir('   '),
]);
same('SesionRepository::hashId genera un SHA-256 de 64 hex distinto del session_id en claro', true,
    strlen(SR::hashId('sesion-secreta')) === 64 && SR::hashId('sesion-secreta') !== 'sesion-secreta'
);

section('Multimoneda: Dinero::formatearEn, ConversorMoneda y validadores (Fase 18)');
use App\Support\ConversorMoneda as CM;
use App\Domain\Cuenta as Cta;
D::usarMoneda('COP');
same('Dinero::monedaActual y formatearEn formatean cada moneda con su símbolo sin cambiar la moneda base', [
    'COP',
    'US$500',
    '€1.250,50',
    'MX$900',
    '$1.000',
    'COP',
], [
    D::monedaActual(),
    D::formatearEn('500.00', 'USD'),
    D::formatearEn('1250.50', 'EUR'),
    D::formatearEn('900.00', 'MXN'),
    D::formatearEn('1000.00', null),
    D::monedaActual(),
]);
same('Ui::money respeta la moneda opcional de la cuenta', '<span class="money money--out">−US$120</span>', (string) App\View\Ui::money('120.00', 'out', 'USD'));
same('ConversorMoneda::parseTasaMicros acepta formato colombiano y decimal y rechaza inválidos', [
    4_200_000_000,
    4_200_500_000,
    925_000,
    925_000,
    238,
    1_085_000,
    1_080_000,
    null,
    null,
    null,
], [
    CM::parseTasaMicros('4.200'),
    CM::parseTasaMicros('4.200,50'),
    CM::parseTasaMicros('0,925'),
    CM::parseTasaMicros('0.925'),
    CM::parseTasaMicros('0.000238'),
    CM::parseTasaMicros('1,085'),
    CM::parseTasaMicros('1.08'),
    CM::parseTasaMicros('0'),
    CM::parseTasaMicros('-4200'),
    CM::parseTasaMicros('abc'),
]);
same('ConversorMoneda::formatearTasa formatea limpio sin ceros de más', ['4.200', '4.200,5', '0,925'], [
    CM::formatearTasa(4_200_000_000),
    CM::formatearTasa(4_200_500_000),
    CM::formatearTasa(925_000),
]);
same('ConversorMoneda::convertir aplica tasa directa, inversa, identidad y null sin tasa', [
    '420000.00',
    '100.00',
    '500.00',
    null,
    '-42000.00',
], [
    CM::convertir('100.00', 'USD', 'COP', ['USD:COP' => 4_200_000_000]),
    CM::convertir('420000.00', 'COP', 'USD', ['USD:COP' => 4_200_000_000]),
    CM::convertir('500.00', 'COP', 'COP', []),
    CM::convertir('100.00', 'EUR', 'COP', ['USD:COP' => 4_200_000_000]),
    CM::convertir('-10.00', 'USD', 'COP', ['USD:COP' => 4_200_000_000]),
]);
same('ConversorMoneda::tasaImplicita calcula millonésimas exactas', 4_150_000_000, CM::tasaImplicita('100.00', '415000.00'));

$consCuentas = CM::consolidarCuentas([
    new Cta(1, 1, 'Banco COP', 'bancaria', '1000000.00', true, '1000000.00', null, null, 0, null),
    new Cta(2, 1, 'Cuenta USD', 'ahorro', '500.00', true, '500.00', null, null, 0, 'USD'),
    new Cta(3, 1, 'Cuenta EUR', 'ahorro', '100.00', true, '100.00', null, null, 0, 'EUR'),
    new Cta(4, 1, 'Inactiva USD', 'ahorro', '999.00', false, '999.00', null, null, 0, 'USD'),
], 'COP', ['USD:COP' => 4_000_000_000]);
same('consolidarCuentas suma activas con tasa (1M + 500*4000 = 3M), excluye inactivas y reporta monedas sin tasa', [
    '3000000.00',
    ['EUR'],
    '2000000.00',
    null,
], [
    $consCuentas['total'],
    $consCuentas['sinTasa'],
    $consCuentas['porCuenta'][2],
    $consCuentas['porCuenta'][3],
]);

same('CuentaValidator acepta moneda válida, vacía (hereda perfil) y rechaza inválida', [
    [[], 'USD'],
    [[], null],
    true,
], [
    [$cv15->validate(['nombre' => 'Ahorros USD', 'tipo' => 'ahorro', 'moneda' => 'usd'])['errors'], $cv15->validate(['nombre' => 'Ahorros USD', 'tipo' => 'ahorro', 'moneda' => 'usd'])['data']['moneda']],
    [$cv15->validate(['nombre' => 'Efectivo', 'tipo' => 'efectivo', 'moneda' => ''])['errors'], $cv15->validate(['nombre' => 'Efectivo', 'tipo' => 'efectivo', 'moneda' => ''])['data']['moneda']],
    isset($cv15->validate(['nombre' => 'Rara', 'tipo' => 'efectivo', 'moneda' => 'BTC'])['errors']['moneda']),
]);

$tv18 = new App\Validation\TransferenciaValidator();
$valTrMulti = $tv18->validate([
    'id_cuenta_origen' => '1',
    'id_cuenta_destino' => '2',
    'monto' => '100',
    'monto_destino' => '415.000',
    'tasa_cambio' => '4.150',
    'fecha' => '2026-11-26',
]);
same('TransferenciaValidator valida monto_destino y tasa_cambio opcionales', [[], '415000.00', 4_150_000_000], [
    $valTrMulti['errors'],
    $valTrMulti['data']['monto_destino'],
    $valTrMulti['data']['tasa_micros'],
]);
same('TransferenciaValidator rechaza monto_destino y tasa_cambio inválidos', [true, true], [
    isset($tv18->validate(['id_cuenta_origen' => '1', 'id_cuenta_destino' => '2', 'monto' => '100', 'monto_destino' => '0', 'fecha' => '2026-11-26'])['errors']['monto_destino']),
    isset($tv18->validate(['id_cuenta_origen' => '1', 'id_cuenta_destino' => '2', 'monto' => '100', 'tasa_cambio' => 'xyz', 'fecha' => '2026-11-26'])['errors']['tasa_cambio']),
]);

section('AlmacenAdjuntos: validación por firma binaria, nombres seguros y sin path traversal (Fase 19)');
use App\Support\AlmacenAdjuntos as AA;
$pngMinimo = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$pdfMinimo = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
same('detectarMimePorFirma reconoce JPG, PNG, WebP y PDF y rechaza PHP/HTML/SVG', [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
    null,
    null,
    null,
], [
    AA::detectarMimePorFirma("\xFF\xD8\xFF\xE0\x00\x10JFIF"),
    AA::detectarMimePorFirma((string) $pngMinimo),
    AA::detectarMimePorFirma("RIFF\x24\x00\x00\x00WEBPVP8 "),
    AA::detectarMimePorFirma($pdfMinimo),
    AA::detectarMimePorFirma('<?php echo 1; ?>'),
    AA::detectarMimePorFirma('<html><body>xss</body></html>'),
    AA::detectarMimePorFirma('<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
]);
same('limpiarNombreOriginal quita rutas y caracteres peligrosos', [
    'Factura Luz 2026.pdf',
    'passwd',
    'comprobante',
], [
    AA::limpiarNombreOriginal('../../Factura "Luz" 2026.pdf'),
    AA::limpiarNombreOriginal('/etc/passwd'),
    AA::limpiarNombreOriginal('...'),
]);
same('nombreSeguroDescarga fuerza la extensión canónica del MIME real', 'factura-enero.pdf', AA::nombreSeguroDescarga('factura enero.php.exe', 'application/pdf'));
$rutaValida = '2026/11/' . str_repeat('a', 32) . '.pdf';
same('rutaAbsoluta acepta patrón YYYY/MM/<32-hex>.<ext> y bloquea path traversal o .php', [
    true,
    null,
    null,
    null,
], [
    AA::rutaAbsoluta($rutaValida, '/tmp/adj') === '/tmp/adj/' . $rutaValida,
    AA::rutaAbsoluta('../2026/11/' . str_repeat('a', 32) . '.pdf', '/tmp/adj'),
    AA::rutaAbsoluta('2026/11/' . str_repeat('a', 32) . '.php', '/tmp/adj'),
    AA::rutaAbsoluta('archivo.jpg', '/tmp/adj'),
]);
$tmpAdjDir = sys_get_temp_dir() . '/lucas_adj_unit_' . bin2hex(random_bytes(4));
$tmpPng = sys_get_temp_dir() . '/lucas_png_' . bin2hex(random_bytes(4));
$tmpPhp = sys_get_temp_dir() . '/lucas_php_' . bin2hex(random_bytes(4));
file_put_contents($tmpPng, (string) $pngMinimo);
file_put_contents($tmpPhp, '<?php echo "hola"; ?>');
$valPng = AA::validarSubida(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmpPng, 'size' => strlen((string) $pngMinimo), 'name' => 'recibo.png']);
$valPhpFalso = AA::validarSubida(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmpPhp, 'size' => 21, 'name' => 'foto_falsa.jpg']);
$valGrande = AA::validarSubida(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'size' => 0, 'name' => 'enorme.pdf']);
$valVacio = AA::validarSubida(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0, 'name' => '']);
same('validarSubida acepta PNG real, rechaza PHP disfrazado de JPG, rechaza >5MB y acepta vacío', [
    [true, false, 'image/png', 'png'],
    false,
    false,
    [true, true],
], [
    [$valPng['ok'], $valPng['vacio'], $valPng['mime'], $valPng['ext']],
    $valPhpFalso['ok'],
    $valGrande['ok'],
    [$valVacio['ok'], $valVacio['vacio']],
]);
$guardadoUnit = AA::guardarDesdeContenido($pdfMinimo, 'application/pdf', 'Recibo Marzo.pdf', $tmpAdjDir);
$absGuardado = AA::rutaAbsoluta($guardadoUnit['ruta'], $tmpAdjDir);
same('guardarDesdeContenido escribe el archivo fuera de public/ y eliminar lo borra del disco', [true, true, false], (static function () use ($guardadoUnit, $absGuardado, $tmpAdjDir): array {
    $existeAntes = $absGuardado !== null && is_file($absGuardado);
    AA::eliminar($guardadoUnit['ruta'], $tmpAdjDir);
    $existeDespues = $absGuardado !== null && is_file($absGuardado);
    return [$guardadoUnit['mime'] === 'application/pdf', $existeAntes, $existeDespues];
})());
@unlink($tmpPng);
@unlink($tmpPhp);

finish();
