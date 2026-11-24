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
same('un hash viejo se rehashea al entrar', 3, $auth->authenticate('vieja@correo.com', 'secreta123')?->id);
same('el hash nuevo es válido y más fuerte', true, $fake->newHash !== null && password_verify('secreta123', $fake->newHash) && !password_needs_rehash($fake->newHash, PASSWORD_DEFAULT));
same('el hash de la seed del admin corresponde a la clave documentada', true, (static function (): bool {
    preg_match("/'(\\\$2y\\\$[^']+)'/", (string) file_get_contents(dirname(__DIR__, 2) . '/database/02-seed.sql'), $m);
    return isset($m[1]) && password_verify('Admin#2026dev', $m[1]);
})());

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
same('para un campo de texto', '1950000,50', D::paraCampo('1950000.50'));
same('para un campo de texto sin decimales', '-300', D::paraCampo('-300.00'));
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
$activa = ['activa' => true];
same('un gasto en una cuenta activa con una categoría de gasto propia', RM::OK, RM::evaluar($activa, $cat(5, 'gasto'), 5, 'gasto'));
same('con una categoría predeterminada', RM::OK, RM::evaluar($activa, $cat(null, 'ingreso'), 5, 'ingreso'));
same('una cuenta que no es del usuario', RM::CUENTA_AJENA, RM::evaluar(null, $cat(null, 'gasto'), 5, 'gasto'));
same('una categoría que no existe', RM::CATEGORIA_AJENA, RM::evaluar($activa, null, 5, 'gasto'));
same('una categoría de otro usuario', RM::CATEGORIA_AJENA, RM::evaluar($activa, $cat(9, 'gasto'), 5, 'gasto'));
same('el tipo no coincide con el de la categoría', RM::TIPO_DISCORDANTE, RM::evaluar($activa, $cat(null, 'ingreso'), 5, 'gasto'));
same('una cuenta inactiva', RM::CUENTA_INACTIVA, RM::evaluar(['activa' => false], $cat(null, 'gasto'), 5, 'gasto'));
same('lo ajeno se detecta antes que lo discordante (no se revela que existe)', RM::CATEGORIA_AJENA, RM::evaluar($activa, $cat(9, 'ingreso'), 5, 'gasto'));

section('Filtros del listado');
use App\Movimientos\Filtros as F;
same('sin parámetros no hay filtros', ['desde' => '', 'hasta' => '', 'cuenta' => 0, 'categoria' => 0, 'tipo' => ''], F::desde([]));
same('filtros válidos', ['desde' => '2026-11-01', 'hasta' => '2026-11-30', 'cuenta' => 3, 'categoria' => 8, 'tipo' => 'gasto'], F::desde(['desde' => '2026-11-01', 'hasta' => '2026-11-30', 'cuenta' => '3', 'categoria' => '8', 'tipo' => 'gasto']));
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

finish();
