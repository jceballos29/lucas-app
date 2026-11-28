<?php

declare(strict_types=1);

/**
 * Pruebas de integración: repositorios y servicios contra una BASE DE DATOS TEMPORAL (D6). Crea `<base>_test_<azar>`
 * con database/01-schema.sql, 02-seed.sql y dev/admin.sql, corre las pruebas y la borra al terminar. Nunca toca la base de la aplicación.
 *
 * Necesita el usuario de migraciones (puede crear bases `<base>_test%`), por eso se lanza con bin/as-migrator:
 *
 *     bin/as-migrator php tests/integration.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/harness.php';

use App\Auth\Autenticador;
use App\Auth\LimitadorIntentos;
use App\Config\Environment;
use App\Container;
use App\Database\Connection;
use App\Database\SqlScript;
use App\Database\Transaction;
use App\Support\Dinero;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// ---- Base temporal ---------------------------------------------------------------------------------------------

$dbName = Environment::get('DB_DATABASE') . '_test_' . bin2hex(random_bytes(4));

if (preg_match('/^[a-z0-9_]+_test_[a-f0-9]{8}$/', $dbName) !== 1) {
    fwrite(STDERR, "Nombre de base no válido: $dbName\n");
    exit(2);
}

$admin = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Environment::get('DB_HOST'), Environment::get('DB_PORT')),
    Environment::get('DB_USERNAME'),
    Environment::get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

try {
    $admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
} catch (PDOException $e) {
    fwrite(STDERR, "No se pudo crear la BD temporal ({$e->getMessage()}).\nLánzalo con bin/as-migrator, y si la base ya existía antes del permiso de pruebas, ejecuta:\n  docker compose exec db bash /docker-entrypoint-initdb.d/03-users.sh\n");
    exit(2);
}

register_shutdown_function(static function () use ($admin, $dbName): void {
    $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
});

putenv("DB_DATABASE=$dbName");
$pdo = Connection::create();

// 'dev/admin.sql': el administrador de DESARROLLO ya no está en 02-seed.sql (C1); la base de pruebas lo carga a propósito.
foreach (['01-schema.sql', '02-seed.sql', 'dev/admin.sql'] as $file) {
    foreach (SqlScript::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/' . $file)) as $statement) {
        $pdo->exec($statement);
    }
}

$container = new Container();
$usuarios = $container->usuarios();

// ---- Seed ------------------------------------------------------------------------------------------------------

section('Seed');
same('hay 8 categorías de gasto predeterminadas', 8, (int) $pdo->query("SELECT COUNT(*) FROM categorias WHERE id_usuario IS NULL AND tipo = 'gasto'")->fetchColumn());
same('hay 4 categorías de ingreso predeterminadas', 4, (int) $pdo->query("SELECT COUNT(*) FROM categorias WHERE id_usuario IS NULL AND tipo = 'ingreso'")->fetchColumn());
same('las tildes llegan bien a la BD', 1, (int) $pdo->query("SELECT COUNT(*) FROM categorias WHERE nombre = 'Alimentación'")->fetchColumn());
$seedAdmin = $usuarios->findByEmail('admin@lucas.local');
same('existe el admin de la seed', true, $seedAdmin !== null);
same('el admin de la seed tiene rol admin y está activo', true, $seedAdmin?->rol === 'admin' && $seedAdmin->activo);
same('hay 1 administrador activo', 1, $usuarios->countActiveAdmins());

// ---- UsuarioRepository -----------------------------------------------------------------------------------------

section('UsuarioRepository');
$id = $usuarios->create('Ana', 'ana@correo.com', password_hash('secreta123', PASSWORD_DEFAULT));
same('create devuelve el id nuevo', true, is_int($id) && $id > 0);
$ana = $usuarios->findByEmail('ana@correo.com');
same('findByEmail encuentra al usuario', 'Ana', $ana?->nombre);
same('el rol por defecto es usuario', 'usuario', $ana?->rol);
same('nace activo y con moneda COP', true, $ana?->activo === true && $ana->moneda === 'COP');
same('findById encuentra al usuario', 'ana@correo.com', $usuarios->findById((int) $id)?->correo);
same('findById de un id inexistente', null, $usuarios->findById(999999));
same('un correo repetido devuelve null (no lanza)', null, $usuarios->create('Otra', 'ana@correo.com', 'x'));
same('el correo repetido no distingue mayúsculas (colación de MySQL)', null, $usuarios->create('Otra', 'ANA@correo.com', 'x'));
same('crear un usuario no cambia los admins', 1, $usuarios->countActiveAdmins());
$usuarios->create('Beto', 'beto@correo.com', 'x', 'admin');
same('un admin nuevo suma a los admins activos', 2, $usuarios->countActiveAdmins());
$pdo->exec("UPDATE usuarios SET activo = 0 WHERE correo = 'beto@correo.com'");
same('un admin inactivo no cuenta', 1, $usuarios->countActiveAdmins());
$usuarios->updatePasswordHash((int) $id, 'nuevo-hash');
same('updatePasswordHash guarda el hash', 'nuevo-hash', $usuarios->findById((int) $id)?->contrasenaHash);
same('un nombre con comilla no rompe la consulta (consultas preparadas)', null, $usuarios->findByEmail("x' OR '1'='1"));

// ---- Autenticador con la BD real -------------------------------------------------------------------------------

section('Autenticador (BD real)');
$auth = new Autenticador($usuarios);
same('el admin de la seed entra con Admin#2026dev', 'admin', $auth->authenticate('admin@lucas.local', 'Admin#2026dev')?->rol);
same('clave incorrecta', null, $auth->authenticate('admin@lucas.local', 'otra'));
$pdo->exec("UPDATE usuarios SET activo = 0 WHERE correo = 'admin@lucas.local'");
same('un usuario desactivado no entra aunque la clave sea correcta', null, $auth->authenticate('admin@lucas.local', 'Admin#2026dev'));
$pdo->exec("UPDATE usuarios SET activo = 1 WHERE correo = 'admin@lucas.local'");
$usuarios->updatePasswordHash((int) $id, password_hash('secreta123', PASSWORD_BCRYPT, ['cost' => 4]));
$auth->authenticate('ana@correo.com', 'secreta123');
same('el hash viejo se reemplaza por uno actual al entrar', false, password_needs_rehash((string) $usuarios->findById((int) $id)?->contrasenaHash, PASSWORD_DEFAULT));

// ---- Limitación de intentos ------------------------------------------------------------------------------------

section('LimitadorIntentos (BD real)');
$limitador = new LimitadorIntentos($container->intentos());
same('al principio no hay bloqueo', false, $limitador->bloqueado('login', 'x@correo.com', '10.0.0.1'));

// M1: en login el bloqueo duro es por par (correo, IP); los fallos de otras IP no dejan fuera al dueño.
for ($i = 0; $i < LimitadorIntentos::MAX_POR_PAR - 1; $i++) {
    $limitador->registrar('login', 'x@correo.com', '10.0.0.1');
}
same('4 fallos del mismo par aún no bloquean', false, $limitador->bloqueado('login', 'x@correo.com', '10.0.0.1'));
$limitador->registrar('login', 'X@correo.com', '10.0.0.1');
same('el 5.º fallo bloquea ese par (sin distinguir mayúsculas)', true, $limitador->bloqueado('login', 'x@correo.com', '10.0.0.1'));
same('M1: el dueño, desde OTRA IP, puede entrar', false, $limitador->bloqueado('login', 'x@correo.com', '10.0.0.99'));
same('otro correo desde la misma IP no se ve afectado', false, $limitador->bloqueado('login', 'y@correo.com', '10.0.0.1'));
same('los fallos de login no cuentan para el registro', false, $limitador->bloqueado('registro', 'x@correo.com', '10.0.0.1'));
$limitador->perdonar('login', 'x@correo.com', '10.0.0.1');
same('perdonar libera el par', false, $limitador->bloqueado('login', 'x@correo.com', '10.0.0.1'));

for ($i = 0; $i < LimitadorIntentos::MAX_POR_CORREO_ROTANDO_IP - 1; $i++) {
    $limitador->registrar('login', 'rot@correo.com', '172.16.' . intdiv($i, 4) . '.' . (1 + $i % 4));
}
same('49 fallos con IP rotatorias (4 por IP) aún no bloquean el correo', false, $limitador->bloqueado('login', 'rot@correo.com', '172.31.0.1'));
$limitador->registrar('login', 'rot@correo.com', '172.31.9.9');
same('el 50.º fallo con el mismo correo (rotando IP) bloquea el correo', true, $limitador->bloqueado('login', 'rot@correo.com', '172.31.0.1'));
$limitador->perdonar('login', 'rot@correo.com', '172.31.9.9');
same('un acceso correcto perdona el contador por correo', false, $limitador->bloqueado('login', 'rot@correo.com', '172.31.0.1'));

// El registro mantiene el límite clásico por correo (5): no es adivinar contraseñas, sino evitar el abuso del correo.
for ($i = 0; $i < LimitadorIntentos::MAX_POR_CORREO; $i++) {
    $limitador->registrar('registro', 'w@correo.com', '10.0.1.' . (10 + $i));
}
same('registro: 5 intentos con el mismo correo bloquean aunque cambie la IP', true, $limitador->bloqueado('registro', 'w@correo.com', '10.0.1.99'));
$limitador->perdonar('registro', 'w@correo.com');

for ($i = 0; $i < LimitadorIntentos::MAX_POR_IP; $i++) {
    $limitador->registrar('registro', "z$i@correo.com", '10.9.9.9');
}
same('20 intentos desde una IP la bloquean aunque los correos sean distintos', true, $limitador->bloqueado('registro', 'nuevo@correo.com', '10.9.9.9'));

$pdo->exec('UPDATE intentos_acceso SET creado_en = NOW() - INTERVAL 16 MINUTE');
same('los intentos de hace más de 15 minutos ya no cuentan', false, $limitador->bloqueado('registro', 'nuevo@correo.com', '10.9.9.9'));
$container->intentos()->purgeOlderThan(900);
same('purgeOlderThan borra los viejos', 0, (int) $pdo->query('SELECT COUNT(*) FROM intentos_acceso')->fetchColumn());
same('la tabla no guarda correos en claro', 0, (int) $pdo->query("SELECT COUNT(*) FROM intentos_acceso WHERE clave LIKE '%@%'")->fetchColumn());

// ---- Búsqueda y paginación de usuarios (RF-30) ------------------------------------------------------------------

section('UsuarioRepository: búsqueda');
$usuarios->create('Marta 100% Ríos', 'marta@correo.com', 'x');
$usuarios->create('Pedro_Gómez', 'pedro@correo.com', 'x');
$usuarios->create('Pedroxgómez', 'otro.pedro@correo.com', 'x');
same('sin texto lista a todos', $usuarios->countSearch(''), count($usuarios->search('', 100, 0)));
same('busca por parte del nombre (sin distinguir mayúsculas)', ['Ana'], array_column($usuarios->search('ANA', 10, 0), 'nombre'));
same('busca por parte del correo', ['beto@correo.com'], array_column($usuarios->search('beto@', 10, 0), 'correo'));
same('el % del texto buscado no es un comodín', ['Marta 100% Ríos'], array_column($usuarios->search('100%', 10, 0), 'nombre'));
same('el % solo no devuelve todo', 1, $usuarios->countSearch('%'));
same('el _ del texto buscado no es un comodín', ['Pedro_Gómez'], array_column($usuarios->search('o_g', 10, 0), 'nombre'));
same('una búsqueda sin resultados', [], $usuarios->search('zzzzzz', 10, 0));
same('la lista no expone el hash', false, array_key_exists('contrasena_hash', $usuarios->search('', 1, 0)[0]));
same('la paginación respeta límite y desplazamiento', [2, 1], [count($usuarios->search('', 2, 0)), count($usuarios->search('', 2, $usuarios->countSearch('') - 1))]);
same('un texto con comillas no rompe la consulta', 0, $usuarios->countSearch("'; DROP TABLE usuarios; --"));

// ---- Métricas del dashboard (RF-28, RF-29, RNF-21) ---------------------------------------------------------------

section('AdminStatsRepository');
$stats = $container->adminStats();
$t = $stats->totals();
same('usuarios totales', (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn(), $t['usuarios']);
same('usuarios activos', (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE activo = 1')->fetchColumn(), $t['activos']);
same('todos nacieron este mes', $t['usuarios'], $t['nuevos_mes']);
$pdo->exec("UPDATE usuarios SET creado_en = NOW() - INTERVAL 40 DAY WHERE correo = 'ana@correo.com'");
same('un usuario de hace 40 días no cuenta como nuevo del mes', $t['usuarios'] - 1, $stats->totals()['nuevos_mes']);
same('administradores activos', $usuarios->countActiveAdmins(), $t['admins_activos']);
same('sin cuentas ni movimientos', [0, 0], [$t['cuentas'], $t['movimientos']]);
$pdo->exec("INSERT INTO cuentas (id_usuario, nombre, tipo) VALUES ($id, 'Efectivo', 'efectivo')");
$pdo->exec("INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha, descripcion) SELECT $id, id_cuenta, (SELECT MIN(id_categoria) FROM categorias), 'gasto', 98765.43, CURDATE(), 'SECRETO-NO-EXPONER' FROM cuentas LIMIT 1");
$t2 = $stats->totals();
same('cuenta cuentas y movimientos', [1, 1], [$t2['cuentas'], $t2['movimientos']]);
same('las métricas son solo números (nada de montos ni descripciones)', true, array_reduce($t2, static fn (bool $ok, mixed $v): bool => $ok && is_int($v), true));
same('los últimos registros no traen datos de movimientos', ['id', 'nombre', 'correo', 'activo', 'creado_en'], array_keys($stats->latestUsers(1)[0]));

$meses = $stats->registrationsByMonth(12);
same('siempre 12 meses', 12, count($meses));
same('el último mes es el actual', (int) gmdate('n'), $meses[11]['month']);
same('el mes de hace 11 meses es el primero', (int) gmdate('n', strtotime('first day of this month -11 months')), $meses[0]['month']);
same('los registros de este mes se cuentan', $t['usuarios'] - 1, $meses[11]['value']);
same('el de hace 40 días cae en el mes anterior o en este', $t['usuarios'], array_sum(array_column($meses, 'value')));
$pdo->exec("UPDATE usuarios SET creado_en = NOW() - INTERVAL 5 YEAR WHERE correo = 'marta@correo.com'");
same('un registro de hace años queda fuera de la gráfica', $t['usuarios'] - 1, array_sum(array_column($stats->registrationsByMonth(12), 'value')));
same('el orden de los últimos registros es del más nuevo al más viejo', true, (static function () use ($stats): bool {
    $dates = array_column($stats->latestUsers(50), 'creado_en');
    $sorted = $dates;
    rsort($sorted);
    return $dates === $sorted;
})());

// ---- Gestión de usuarios con RF-33 dentro de una transacción -----------------------------------------------------

section('GestionUsuarios (RF-31 a RF-33)');
$gestion = $container->gestionUsuarios();
$adminId = (int) $seedAdmin?->id;
$betoId = (int) $usuarios->findByEmail('beto@correo.com')?->id; // admin, inactivo desde antes
$martaId = (int) $usuarios->findByEmail('marta@correo.com')?->id;

same('desactivar a un usuario', ['ok', 'Marta 100% Ríos'], array_values($gestion->aplicar($adminId, $martaId, 'desactivar')));
same('queda inactivo en la BD', false, $usuarios->findById($martaId)?->activo);
same('el mismo cambio otra vez no hace nada', 'sin_cambio', $gestion->aplicar($adminId, $martaId, 'desactivar')['codigo']);
same('activarlo de nuevo', 'ok', $gestion->aplicar($adminId, $martaId, 'activar')['codigo']);
same('hacer admin a un usuario', 'ok', $gestion->aplicar($adminId, $martaId, 'hacer_admin')['codigo']);
same('ahora hay 2 admins activos', 2, $usuarios->countActiveAdmins());
same('un admin no puede desactivarse a sí mismo', 'propio', $gestion->aplicar($adminId, $adminId, 'desactivar')['codigo']);
same('ni quitarse el rol', 'propio', $gestion->aplicar($adminId, $adminId, 'quitar_admin')['codigo']);
same('y sigue siendo admin activo', true, $usuarios->findById($adminId)?->esAdmin() && $usuarios->findById($adminId)->activo);
same('un usuario inexistente', 'no_existe', $gestion->aplicar($adminId, 999999, 'desactivar')['codigo']);
same('un usuario normal no tiene permiso', 'sin_permiso', $gestion->aplicar((int) $id, $martaId, 'quitar_admin')['codigo']);
same('y no se aplicó el cambio', 'admin', $usuarios->findById($martaId)?->rol);

// Dos administradores que se desactivan a la vez: el segundo ya no es admin activo cuando le toca y se le rechaza.
same('el admin 1 desactiva al admin 2', 'ok', $gestion->aplicar($adminId, $martaId, 'desactivar')['codigo']);
same('el admin 2 (ya inactivo) intenta desactivar al admin 1: rechazado', 'sin_permiso', $gestion->aplicar($martaId, $adminId, 'desactivar')['codigo']);
same('queda 1 admin activo', 1, $usuarios->countActiveAdmins());

// El bloqueo es real: mientras otra transacción tiene bloqueados a los admins activos, una segunda conexión espera
// (aquí con un tiempo de espera de 1 s para no bloquear la prueba) en vez de leer un estado a medias.
$otra = Connection::create();
$otra->exec('SET SESSION innodb_lock_wait_timeout = 1');
$usuarios->transaction(function () use ($usuarios, $otra): void {
    $usuarios->lockActiveAdminIds();
    $espera = null;
    try {
        $otra->beginTransaction();
        $otra->query("SELECT id_usuario FROM usuarios WHERE rol = 'admin' AND activo = 1 FOR UPDATE")->fetchAll();
    } catch (PDOException $e) {
        $espera = (int) ($e->errorInfo[1] ?? 0);
    }
    $otra->rollBack();
    same('una segunda conexión no puede bloquear a los admins a la vez (timeout 1205)', 1205, $espera);
});
same('al terminar la transacción el bloqueo se libera', true, (static function () use ($otra): bool {
    $otra->beginTransaction();
    $ids = $otra->query("SELECT id_usuario FROM usuarios WHERE rol = 'admin' AND activo = 1 FOR UPDATE")->fetchAll();
    $otra->rollBack();
    return count($ids) === 1;
})());
same('una excepción dentro de la transacción deshace el cambio (Marta era admin)', 'admin', (static function () use ($usuarios, $martaId): string {
    try {
        $usuarios->transaction(function () use ($usuarios, $martaId): void {
            $usuarios->setRol($martaId, 'usuario');
            throw new RuntimeException('falla a mitad');
        });
    } catch (RuntimeException) {
    }
    return (string) $usuarios->findById($martaId)?->rol;
})());

// ---- Cuentas (RF-05 a RF-07) ------------------------------------------------------------------------------------

section('CuentaRepository');
$cuentas = $container->cuentas();
$carla = (int) $usuarios->create('Carla', 'carla@correo.com', 'x');
$david = (int) $usuarios->create('David', 'david@correo.com', 'x');
$cid = $cuentas->create($carla, 'Efectivo', 'efectivo', '100000.00');
$c = $cuentas->findOwned($carla, $cid);
same('la cuenta nueva se encuentra', 'Efectivo', $c?->nombre);
same('nace activa', true, $c?->activa);
same('sin movimientos el saldo es el inicial', '100000.00', $c?->saldo);

$catGasto = (int) $pdo->query("SELECT MIN(id_categoria) FROM categorias WHERE tipo = 'gasto'")->fetchColumn();
$catIngreso = (int) $pdo->query("SELECT MIN(id_categoria) FROM categorias WHERE tipo = 'ingreso'")->fetchColumn();
$pdo->exec("INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES
    ($carla, $cid, $catIngreso, 'ingreso', 50000.50, CURDATE()),
    ($carla, $cid, $catGasto, 'gasto', 20000.25, CURDATE())");
$pdo->exec("INSERT INTO metas_ahorro (id_usuario, nombre, monto_objetivo) VALUES ($carla, 'Viaje', 1000000)");
$metaId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO aportes_meta (id_meta, id_cuenta, monto, fecha) VALUES ($metaId, $cid, 10000.10, CURDATE())");
same('saldo = inicial + ingresos − gastos − aportes, exacto al centavo', '120000.15', $cuentas->findOwned($carla, $cid)?->saldo);
same('el saldo de una cuenta no se mezcla con el de otra', '0.00', (static function () use ($cuentas, $carla): string {
    $otra = $cuentas->create($carla, 'Vacía', 'ahorro', '0');
    return (string) $cuentas->findOwned($carla, $otra)?->saldo;
})());
$tarjeta = $cuentas->create($carla, 'Visa', 'tarjeta_credito', '-500000.00');
$pdo->exec("INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES ($carla, $tarjeta, $catGasto, 'gasto', 80000.00, CURDATE())");
same('una tarjeta con deuda inicial baja más al gastar', '-580000.00', $cuentas->findOwned($carla, $tarjeta)?->saldo);
same('la lista del usuario trae sus 3 cuentas', 3, count($cuentas->listByUser($carla)));
same('otro usuario no ve ninguna', 0, count($cuentas->listByUser($david)));

section('CuentaRepository: propiedad (RNF-04)');
same('una cuenta ajena no se encuentra', null, $cuentas->findOwned($david, $cid));
same('una cuenta ajena no se edita', false, $cuentas->update($david, $cid, 'Robada', 'efectivo', '0'));
same('una cuenta ajena no se desactiva', false, $cuentas->setActiva($david, $cid, false));
same('y no cambió nada', ['Efectivo', true], [$cuentas->findOwned($carla, $cid)?->nombre, $cuentas->findOwned($carla, $cid)?->activa]);
same('el nombre repetido se detecta por usuario', [true, false], [$cuentas->nameTaken($carla, 'efectivo'), $cuentas->nameTaken($david, 'Efectivo')]);
same('al editar, la propia cuenta no cuenta como repetida', false, $cuentas->nameTaken($carla, 'Efectivo', $cid));
same('editar guarda los cambios', true, $cuentas->update($carla, $cid, 'Caja', 'bancaria', '250000.50'));
same('y el saldo se recalcula con el nuevo saldo inicial', ['Caja', 'bancaria', '270000.65'], [$cuentas->findOwned($carla, $cid)?->nombre, $cuentas->findOwned($carla, $cid)?->tipo, $cuentas->findOwned($carla, $cid)?->saldo]);
same('desactivar conserva el historial', [true, 2], [$cuentas->setActiva($carla, $cid, false), (int) $pdo->query("SELECT COUNT(*) FROM transacciones WHERE id_cuenta = $cid")->fetchColumn()]);
same('las cuentas inactivas van al final de la lista', false, $cuentas->listByUser($carla)[2]->activa);
same('el saldo de una cuenta inactiva se sigue calculando', '270000.65', $cuentas->findOwned($carla, $cid)?->saldo);

// ---- Categorías (RF-08, RF-09, RF-34) ----------------------------------------------------------------------------

section('CategoriaRepository');
$categorias = $container->categorias();
same('las predeterminadas son 12', 12, count($categorias->listDefaults()));
same('el usuario ve las 12 predeterminadas', 12, count($categorias->listVisible($carla)));
$mascotas = $categorias->create($carla, 'Mascotas', 'gasto', '#A1B2C3');
same('una categoría propia se suma a las visibles', 13, count($categorias->listVisible($carla)));
same('y no la ve otro usuario', 12, count($categorias->listVisible($david)));
same('ni aparece entre las predeterminadas', 12, count($categorias->listDefaults()));
same('los gastos van antes que los ingresos', 'gasto', $categorias->listVisible($carla)[0]->tipo);
$m = $categorias->findById($mascotas);
same('findById trae el dueño y el color', [$carla, '#A1B2C3', false], [$m?->idUsuario, $m?->color, $m?->esPredeterminada()]);
same('una predeterminada no tiene dueño', true, $categorias->findById($catGasto)?->esPredeterminada());
same('findById de un id inexistente', null, $categorias->findById(999999));

section('CategoriaRepository: nombres repetidos');
same('no se puede repetir una predeterminada (sin distinguir mayúsculas ni tildes)', true, $categorias->nameTaken($carla, 'alimentacion', 'gasto'));
same('el mismo nombre en el otro tipo es otra categoría', false, $categorias->nameTaken($carla, 'Alimentación', 'ingreso'));
same('no se puede repetir una categoría propia', true, $categorias->nameTaken($carla, 'MASCOTAS', 'gasto'));
same('la propia categoría no cuenta al editarla', false, $categorias->nameTaken($carla, 'Mascotas', 'gasto', $mascotas));
same('la categoría de otro usuario no estorba', false, $categorias->nameTaken($david, 'Mascotas', 'gasto'));
same('para el administrador solo cuentan las predeterminadas', [false, true], [$categorias->nameTaken(null, 'Mascotas', 'gasto'), $categorias->nameTaken(null, 'Salud', 'gasto')]);

section('CategoriaRepository: editar y borrar');
$defaultId = $catGasto;
same('un usuario no edita una predeterminada', false, $categorias->update($carla, $defaultId, 'Hackeada', '#000000'));
same('un usuario no edita la de otro', false, $categorias->update($david, $mascotas, 'Hackeada', '#000000'));
same('el administrador no edita una categoría propia de un usuario', false, $categorias->update(null, $mascotas, 'Hackeada', '#000000'));
same('el dueño la edita', true, $categorias->update($carla, $mascotas, 'Mascotas y más', '#112233'));
same('y el TIPO no cambia', ['Mascotas y más', 'gasto'], [$categorias->findById($mascotas)?->nombre, $categorias->findById($mascotas)?->tipo]);
same('el administrador edita una predeterminada', true, $categorias->update(null, $defaultId, 'Alimentación y mercado', '#E4572E'));
$categorias->update(null, $defaultId, 'Alimentación', '#E4572E');
same('un usuario no borra una predeterminada', 'no_existe', $categorias->delete($carla, $defaultId));
same('un usuario no borra la de otro', 'no_existe', $categorias->delete($david, $mascotas));
same('y siguen existiendo', [true, true], [$categorias->findById($defaultId) !== null, $categorias->findById($mascotas) !== null]);
$pdo->exec("INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES ($carla, $cid, $mascotas, 'gasto', 5000, CURDATE())");
same('una categoría con movimientos NO se borra (D4)', 'en_uso', $categorias->delete($carla, $mascotas));
same('y sigue existiendo', true, $categorias->findById($mascotas) !== null);
$pdo->exec("DELETE FROM transacciones WHERE id_categoria = $mascotas");
$pdo->exec("INSERT INTO presupuestos (id_usuario, id_categoria, mes, anio, monto_limite) VALUES ($carla, $mascotas, 11, 2026, 100000)");
same('una categoría con presupuestos NO se borra (D4)', 'en_uso', $categorias->delete($carla, $mascotas));
$pdo->exec("DELETE FROM presupuestos WHERE id_categoria = $mascotas");
same('sin movimientos ni presupuestos se borra', 'ok', $categorias->delete($carla, $mascotas));
same('y desaparece', null, $categorias->findById($mascotas));
same('borrarla otra vez avisa que no existe', 'no_existe', $categorias->delete($carla, $mascotas));
$nueva = $categorias->create(null, 'Mascotas globales', 'gasto', '#445566');
same('el administrador crea una predeterminada (sin dueño)', true, $categorias->findById($nueva)?->esPredeterminada());
same('y todos la ven', [13, 13], [count($categorias->listVisible($carla)), count($categorias->listVisible($david))]);
same('el administrador la borra', 'ok', $categorias->delete(null, $nueva));
same('el administrador no borra una categoría propia de un usuario', 'no_existe', (static function () use ($categorias, $carla): string {
    $propia = $categorias->create($carla, 'Solo mía', 'gasto', '#000000');
    return $categorias->delete(null, $propia);
})());

// ---- Movimientos (RF-10 a RF-14, D8) -----------------------------------------------------------------------------

section('GestionMovimientos: alta y reglas de D8');
$gestion2 = $container->gestionMovimientos();
$movs = $container->movimientos();
$elena = (int) $usuarios->create('Elena', 'elena@correo.com', 'x');
$fabio = (int) $usuarios->create('Fabio', 'fabio@correo.com', 'x');
$efectivo = $cuentas->create($elena, 'Efectivo', 'efectivo', '100000.00');
$banco = $cuentas->create($elena, 'Banco', 'bancaria', '50000.00');
$tarjetaElena = $cuentas->create($elena, 'Tarjeta Elena', 'tarjeta_credito', '0.00');
$ajena = $cuentas->create($fabio, 'Cuenta de Fabio', 'efectivo', '500.00');
$catGasto = (int) $pdo->query("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'gasto' ORDER BY id_categoria LIMIT 1")->fetchColumn();
$catIngreso = (int) $pdo->query("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'ingreso' ORDER BY id_categoria LIMIT 1")->fetchColumn();
$miGasto = $categorias->create($elena, 'Mi gasto', 'gasto', '#111111');
$gastoDeFabio = $categorias->create($fabio, 'Gasto de Fabio', 'gasto', '#222222');
$dato = static fn (array $over = []): array => $over + ['tipo' => 'gasto', 'monto' => '18000.50', 'fecha' => '2026-11-03', 'id_cuenta' => $efectivo, 'id_categoria' => $catGasto, 'descripcion' => 'Almuerzo'];
$saldo = static fn (int $uid, int $cid): string => (string) $cuentas->findOwned($uid, $cid)?->saldo;
$cuenta = static fn (int $uid): int => (int) $pdo->query("SELECT COUNT(*) FROM transacciones WHERE id_usuario = $uid")->fetchColumn();

$r = $gestion2->guardar($elena, null, $dato());
same('un gasto válido se guarda', 'ok', $r['codigo']);
same('y devuelve su id y el nombre de la cuenta', [true, 'Efectivo'], [is_int($r['id']) && $r['id'] > 0, $r['cuenta']]);
$gastoId = (int) $r['id'];
same('el saldo baja exacto al centavo (RF-14)', '81999.50', $saldo($elena, $efectivo));
same('un ingreso con categoría de ingreso sube el saldo', ['ok', '131999.50'], (static function () use ($gestion2, $dato, $catIngreso, $saldo, $elena, $efectivo): array {
    $x = $gestion2->guardar($elena, null, $dato(['tipo' => 'ingreso', 'monto' => '50000', 'id_categoria' => $catIngreso, 'descripcion' => null]));
    return [$x['codigo'], $saldo($elena, $efectivo)];
})());
same('una categoría propia sirve', 'ok', $gestion2->guardar($elena, null, $dato(['id_categoria' => $miGasto, 'monto' => '1']))['codigo']);

$antes = $cuenta($elena);
same('un gasto con categoría de ingreso: tipo discordante', 'tipo_discordante', $gestion2->guardar($elena, null, $dato(['id_categoria' => $catIngreso]))['codigo']);
same('un ingreso con categoría de gasto: tipo discordante', 'tipo_discordante', $gestion2->guardar($elena, null, $dato(['tipo' => 'ingreso']))['codigo']);
same('una cuenta de otro usuario', 'cuenta_ajena', $gestion2->guardar($elena, null, $dato(['id_cuenta' => $ajena]))['codigo']);
same('una cuenta que no existe', 'cuenta_ajena', $gestion2->guardar($elena, null, $dato(['id_cuenta' => 999999]))['codigo']);
same('una categoría de otro usuario', 'categoria_ajena', $gestion2->guardar($elena, null, $dato(['id_categoria' => $gastoDeFabio]))['codigo']);
same('una categoría que no existe', 'categoria_ajena', $gestion2->guardar($elena, null, $dato(['id_categoria' => 999999]))['codigo']);
$cuentas->setActiva($elena, $banco, false);
$inactiva = $gestion2->guardar($elena, null, $dato(['id_cuenta' => $banco]));
same('una cuenta inactiva no acepta movimientos', ['cuenta_inactiva', 'Banco'], [$inactiva['codigo'], $inactiva['cuenta']]);
same('un gasto mayor que el saldo disponible en una cuenta normal se rechaza', 'saldo_insuficiente', $gestion2->guardar($elena, null, $dato(['id_cuenta' => $efectivo, 'monto' => '999999.00']))['codigo']);
same('nada de lo rechazado se guardó', $antes, $cuenta($elena));
$cuentas->setActiva($elena, $banco, true);
same('al reactivarla sí', 'ok', $gestion2->guardar($elena, null, $dato(['id_cuenta' => $banco, 'monto' => '2500']))['codigo']);
same('una tarjeta de crédito sí acepta gastos que la dejan en negativo', ['ok', '-80000.00'], [
    $gestion2->guardar($elena, null, $dato(['id_cuenta' => $tarjetaElena, 'monto' => '80000.00']))['codigo'],
    $saldo($elena, $tarjetaElena),
]);
$pdo->exec("DELETE FROM transacciones WHERE id_cuenta = $tarjetaElena");

section('GestionMovimientos: edición y borrado');
$saldoAntesEditar = $saldo($elena, $efectivo);
same('editar el monto: el gasto de 18.000,50 pasa a 20.500', 'ok', $gestion2->guardar($elena, $gastoId, $dato(['monto' => '20500']))['codigo']);
same('el saldo baja exactamente la diferencia (20.500 − 18.000,50 = 2.499,50)', Dinero::sumar([$saldoAntesEditar, '-2499.50']), $saldo($elena, $efectivo));
$gastoDeFabioId = (int) $gestion2->guardar($fabio, null, ['tipo' => 'gasto', 'monto' => '100', 'fecha' => '2026-11-03', 'id_cuenta' => $ajena, 'id_categoria' => $catGasto, 'descripcion' => null])['id'];
same('editar el movimiento de otro usuario: no existe', 'no_existe', $gestion2->guardar($elena, $gastoDeFabioId, $dato())['codigo']);
same('y no se tocó', '100.00', $movs->findOwned($fabio, $gastoDeFabioId)?->monto);
same('editar con tipo discordante no cambia nada', ['tipo_discordante', '20500.00'], (static function () use ($gestion2, $dato, $gastoId, $movs, $elena, $catIngreso): array {
    $x = $gestion2->guardar($elena, $gastoId, $dato(['id_categoria' => $catIngreso, 'monto' => '1']));
    return [$x['codigo'], (string) $movs->findOwned($elena, $gastoId)?->monto];
})());
$saldoEfectivoAntes = $saldo($elena, $efectivo);
$saldoBancoAntes = $saldo($elena, $banco);
same('mover un gasto a otra cuenta actualiza el saldo de las dos', 'ok', $gestion2->guardar($elena, $gastoId, $dato(['id_cuenta' => $banco, 'monto' => '20500']))['codigo']);
same('Efectivo recupera lo gastado', Dinero::sumar([$saldoEfectivoAntes, '20500.00']), $saldo($elena, $efectivo));
same('Banco lo pierde', Dinero::sumar([$saldoBancoAntes, '-20500.00']), $saldo($elena, $banco));
$saldoAntesBorrar = $saldo($elena, $banco);
same('borrar un movimiento ajeno no hace nada', false, $movs->delete($elena, $gastoDeFabioId));
same('y sigue existiendo', true, $movs->findOwned($fabio, $gastoDeFabioId) !== null);
same('borrar el propio funciona', true, $movs->delete($elena, $gastoId));
same('y el saldo se recupera (RF-14)', Dinero::sumar([$saldoAntesBorrar, '20500.00']), $saldo($elena, $banco));
same('borrarlo otra vez da false', false, $movs->delete($elena, $gastoId));

section('GestionMovimientos: el bloqueo de la cuenta');
$otra2 = Connection::create();
$otra2->exec('SET SESSION innodb_lock_wait_timeout = 1');
Transaction::run($container->pdo(), function () use ($cuentas, $elena, $efectivo, $otra2): void {
    $cuentas->lockForWrite($elena, $efectivo);
    $codigo = null;
    try {
        $otra2->prepare('UPDATE cuentas SET activa = 0 WHERE id_cuenta = ?')->execute([$efectivo]);
    } catch (PDOException $e) {
        $codigo = (int) ($e->errorInfo[1] ?? 0);
    }
    same('mientras se guarda un movimiento nadie puede desactivar la cuenta (timeout 1205)', 1205, $codigo);
});
$otra2->prepare('UPDATE cuentas SET activa = 0 WHERE id_cuenta = ?')->execute([$efectivo]);
same('al terminar la transacción ya se puede desactivar', false, $cuentas->findOwned($elena, $efectivo)?->activa);
$otra2->prepare('UPDATE cuentas SET activa = 1 WHERE id_cuenta = ?')->execute([$efectivo]);
same('lockForWrite de una cuenta ajena no encuentra nada', null, $cuentas->lockForWrite($fabio, $efectivo));

section('MovimientoRepository: listado, filtros y paginación');
$pdo->exec("DELETE FROM transacciones WHERE id_usuario = $elena");
$sin = ['desde' => '', 'hasta' => '', 'cuenta' => 0, 'categoria' => 0, 'tipo' => ''];
for ($i = 1; $i <= 25; $i++) {
    $dia = sprintf('2026-10-%02d', $i);
    $esIngreso = $i % 5 === 0;
    $gestion2->guardar($elena, null, $dato([
        'tipo' => $esIngreso ? 'ingreso' : 'gasto',
        'id_categoria' => $esIngreso ? $catIngreso : ($i % 2 === 0 ? $miGasto : $catGasto),
        'id_cuenta' => $i <= 15 ? $efectivo : $banco,
        'fecha' => $dia,
        'monto' => '0.10',
        'descripcion' => "Mov $i",
    ]));
}
same('25 movimientos guardados', 25, $movs->count($elena, $sin));
same('la primera página trae 20', 20, count($movs->search($elena, $sin, 20, 0)));
same('la segunda trae los 5 restantes', 5, count($movs->search($elena, $sin, 20, 20)));
same('el orden es del más reciente al más antiguo', ['Mov 25', 'Mov 24'], array_slice(array_map(static fn ($m) => $m->descripcion, $movs->search($elena, $sin, 20, 0)), 0, 2));
same('a igual fecha manda el más nuevo (id mayor)', true, (static function () use ($gestion2, $dato, $movs, $elena, $sin): bool {
    $gestion2->guardar($elena, null, $dato(['fecha' => '2026-10-25', 'descripcion' => 'Segundo del 25']));
    return $movs->search($elena, $sin, 1, 0)[0]->descripcion === 'Segundo del 25';
})());
$pdo->exec("DELETE FROM transacciones WHERE descripcion = 'Segundo del 25'");
same('trae los nombres de cuenta y categoría', ['Banco', 'Salario'], (static function () use ($movs, $elena, $sin): array {
    $m = $movs->search($elena, $sin, 1, 0)[0];
    return [$m->cuentaNombre, $m->categoriaNombre === 'Salario' ? 'Salario' : $m->categoriaNombre];
})());
same('filtro por tipo', 5, $movs->count($elena, ['tipo' => 'ingreso'] + $sin));
same('filtro por cuenta', 15, $movs->count($elena, ['cuenta' => $efectivo] + $sin));
same('filtro por categoría (los gastos pares que no son múltiplos de 5)', 10, $movs->count($elena, ['categoria' => $miGasto] + $sin));
same('filtro «desde» (incluye el día)', 6, $movs->count($elena, ['desde' => '2026-10-20'] + $sin));
same('filtro «hasta» (incluye el día)', 5, $movs->count($elena, ['hasta' => '2026-10-05'] + $sin));
same('rango de fechas', 3, $movs->count($elena, ['desde' => '2026-10-10', 'hasta' => '2026-10-12'] + $sin));
same('filtros combinados: ingresos en Efectivo (días 5, 10 y 15)', 3, $movs->count($elena, ['tipo' => 'ingreso', 'cuenta' => $efectivo] + $sin));
same('un filtro sin resultados', 0, $movs->count($elena, ['desde' => '2030-01-01'] + $sin));
same('los totales usan TODOS los movimientos filtrados, no solo la página', ['0.50', '2.00'], array_values($movs->totals($elena, $sin)));
same('0,10 × 20 gastos = 2,00 exacto (con float daría 2.0000000000000004)', '2.00', $movs->totals($elena, ['tipo' => 'gasto'] + $sin)['gastos']);
same('los totales respetan los filtros', '0.10', $movs->totals($elena, ['desde' => '2026-10-25'] + $sin)['ingresos']);
same('filtrar por la cuenta de otra persona no trae nada', 0, $movs->count($fabio, ['cuenta' => $efectivo] + $sin));
same('con la misma categoría, cada usuario ve solo lo suyo', [25 - 5 - 10, 1], [$movs->count($elena, ['categoria' => $catGasto] + $sin), $movs->count($fabio, ['categoria' => $catGasto] + $sin)]);
same('findOwned encuentra el propio', true, $movs->findOwned($elena, $movs->search($elena, $sin, 1, 0)[0]->id) !== null);
same('y no el ajeno', null, $movs->findOwned($elena, $gastoDeFabioId));
same('el índice (usuario, fecha) sirve para ordenar sin ordenar aparte', true, (static function () use ($pdo, $elena): bool {
    $plan = $pdo->query("EXPLAIN SELECT t.id_transaccion FROM transacciones t WHERE t.id_usuario = $elena ORDER BY t.fecha DESC, t.id_transaccion DESC LIMIT 20")->fetch();
    return str_contains((string) ($plan['key'] ?? ''), 'idx_usuario_fecha') || str_contains((string) ($plan['key'] ?? ''), 'id_usuario');
})());

// ---- Presupuestos (RF-15 a RF-17) ---------------------------------------------------------------------------------

section('PresupuestoRepository: gastado, mes y propiedad');
$presupuestos = $container->presupuestos();
$gina = (int) $usuarios->create('Gina', 'gina@correo.com', 'x');
$hugo = (int) $usuarios->create('Hugo', 'hugo@correo.com', 'x');
$gE = $cuentas->create($gina, 'Efectivo', 'efectivo', '1000000.00');
$gA = $cuentas->create($gina, 'Ahorros', 'ahorro', '50000.00');
$hE = $cuentas->create($hugo, 'Efectivo de Hugo', 'efectivo', '1000000.00');
$gastos = $pdo->query("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'gasto' ORDER BY nombre LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
[$catA, $catB, $catC] = array_map('intval', $gastos);
$mov = static function (int $uid, int $cuentaId, int $catId, string $tipo, string $monto, string $fecha) use ($pdo): void {
    $pdo->prepare('INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES (?, ?, ?, ?, ?, ?)')->execute([$uid, $cuentaId, $catId, $tipo, $monto, $fecha]);
};
$pid = $presupuestos->create($gina, $catA, 11, 2026, '400000.00');
same('un presupuesto nuevo devuelve su id', true, is_int($pid) && $pid > 0);
same('el mismo presupuesto (usuario, categoría, mes, año) devuelve null', null, $presupuestos->create($gina, $catA, 11, 2026, '1.00'));
same('la misma categoría en otro mes sí', true, $presupuestos->create($gina, $catA, 12, 2026, '1.00') !== null);
same('y en otro año', true, $presupuestos->create($gina, $catA, 11, 2027, '1.00') !== null);
same('otro usuario puede tener el suyo', true, $presupuestos->create($hugo, $catA, 11, 2026, '1.00') !== null);
same('sin gastos, gastado es 0', '0.00', $presupuestos->listByMonth($gina, 11, 2026)[0]->gastado);

$mov($gina, $gE, $catA, 'gasto', '100000.00', '2026-11-01');   // primer día del mes
$mov($gina, $gE, $catA, 'gasto', '219999.99', '2026-11-30');   // último día del mes
$mov($gina, $gE, $catA, 'gasto', '5000.00', '2026-10-31');     // mes anterior: no cuenta
$mov($gina, $gE, $catA, 'gasto', '5000.00', '2026-12-01');     // mes siguiente: no cuenta
$mov($gina, $gE, $catB, 'gasto', '77777.00', '2026-11-15');    // otra categoría: no cuenta
$mov($hugo, $hE, $catA, 'gasto', '88888.00', '2026-11-15');    // otro usuario: no cuenta
$pdo->exec("INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES ($gina, $gE, $catA, 'ingreso', 55555.00, '2026-11-10')"); // un ingreso no es gasto
$p = $presupuestos->listByMonth($gina, 11, 2026)[0];
same('gastado suma solo los gastos de la categoría en ese mes (incluye el primer y el último día)', '319999.99', $p->gastado);
same('con 319.999,99 de 400.000 sigue en orden', ['ok', 79], [$p->progreso()['estado'], $p->progreso()['percent']]);
$mov($gina, $gE, $catA, 'gasto', '0.01', '2026-11-20');
$p = $presupuestos->findOwned($gina, (int) $pid);
same('un centavo más: 320.000,00 = 80 % exacto, pide aviso', ['320000.00', 'cerca', 80], [$p?->gastado, $p?->progreso()['estado'], $p?->progreso()['percent']]);
$mov($gina, $gE, $catA, 'gasto', '80000.00', '2026-11-21');
same('exactamente el límite', 'limite', $presupuestos->findOwned($gina, (int) $pid)?->progreso()['estado']);
$mov($gina, $gE, $catA, 'gasto', '0.01', '2026-11-22');
same('un centavo por encima: excedido', ['excedido', '-0.01'], [$presupuestos->findOwned($gina, (int) $pid)?->progreso()['estado'], $presupuestos->findOwned($gina, (int) $pid)?->progreso()['restante']]);
same('el presupuesto de otro año no ve nada', '0.00', $presupuestos->listByMonth($gina, 11, 2027)[0]->gastado);
same('el de diciembre ve solo el gasto del 1 de diciembre', '5000.00', $presupuestos->listByMonth($gina, 12, 2026)[0]->gastado);

$c3 = $presupuestos->create($gina, $catB, 11, 2026, '100000.00');
$presupuestos->create($gina, $catC, 11, 2026, '100000.00');
same('listByMonth trae los 3 presupuestos del mes, por nombre de categoría', 3, count($presupuestos->listByMonth($gina, 11, 2026)));
same('y trae el nombre y el color de la categoría', true, $presupuestos->listByMonth($gina, 11, 2026)[0]->categoriaNombre !== '');
same('otro usuario solo ve el suyo', 1, count($presupuestos->listByMonth($hugo, 11, 2026)));
$alertas = $presupuestos->alertas($gina, 11, 2026);
same('alertas: solo los que llegaron al 80 % (77.777 de 100.000 no)', 1, count($alertas));
same('el de la categoría A está excedido', 'excedido', $alertas[0]->progreso()['estado']);
$mov($gina, $gE, $catB, 'gasto', '2223.00', '2026-11-16');
same('alertas: al 80 % exacto entra (77.777 + 2.223 = 80.000)', 2, count($presupuestos->alertas($gina, 11, 2026)));
same('y se ordenan del más gastado al menos', true, (static function () use ($presupuestos, $gina): bool {
    $porcentajes = array_map(static fn ($x) => $x->progreso()['percent'], $presupuestos->alertas($gina, 11, 2026));
    return $porcentajes === [100, 80];
})());
same('findForCategory encuentra el del mes', true, $presupuestos->findForCategory($gina, $catA, 11, 2026) !== null);
same('y no el de un mes sin presupuesto', null, $presupuestos->findForCategory($gina, $catA, 5, 2026));
same('ni el de otro usuario', null, $presupuestos->findForCategory($hugo, $catB, 11, 2026));
same('0,10 + 0,10 + 0,10 = 0,30 exacto', '0.30', (static function () use ($presupuestos, $gina, $catC, $mov, $gE): string {
    foreach ([1, 2, 3] as $_) {
        $mov($gina, $gE, $catC, 'gasto', '0.10', '2026-11-05');
    }
    foreach ($presupuestos->listByMonth($gina, 11, 2026) as $p) {
        if ($p->idCategoria === $catC) {
            return $p->gastado;
        }
    }
    return 'no encontrado';
})());
same('un presupuesto ajeno no se encuentra', null, $presupuestos->findOwned($hugo, (int) $pid));
same('ni se edita', false, $presupuestos->updateLimit($hugo, (int) $pid, '1.00'));
same('ni se elimina', false, $presupuestos->delete($hugo, (int) $pid));
same('el dueño cambia el límite', ['500000.00', 'cerca'], (static function () use ($presupuestos, $gina, $pid): array {
    $presupuestos->updateLimit($gina, (int) $pid, '500000.00');
    $p = $presupuestos->findOwned($gina, (int) $pid);
    return [$p?->montoLimite, $p?->progreso()['estado']];
})());
same('el dueño lo elimina', [true, null], [$presupuestos->delete($gina, (int) $c3), $presupuestos->findOwned($gina, (int) $c3)]);
same('los movimientos del presupuesto eliminado siguen ahí', true, (int) $pdo->query("SELECT COUNT(*) FROM transacciones WHERE id_usuario = $gina AND id_categoria = $catB")->fetchColumn() > 0);

// ---- Metas y aportes (RF-18 a RF-20, D8) ---------------------------------------------------------------------------

section('GestionMetas: aportes y reglas de D8');
$metas = $container->metas();
$aportes = $container->aportes();
$gm = $container->gestionMetas();
$meta = $metas->create($gina, 'Viaje', '300000.00', '2027-06-30');
$otraMeta = $metas->create($hugo, 'Meta de Hugo', '1000.00', null);
$ap = static fn (int $cuentaId, string $monto): array => ['id_cuenta' => $cuentaId, 'monto' => $monto, 'fecha' => '2026-11-24'];
$saldoG = static fn (int $cid): string => (string) $cuentas->findOwned($gina, $cid)?->saldo;
$ahorrado = static fn (int $mid): string => (string) $metas->findOwned($gina, $mid)?->ahorrado;
$antesE = $saldoG($gE);
$gananciaE = Dinero::aCentavos($antesE);

$r = $gm->aportar($gina, $meta, $ap($gE, '100000.00'));
same('un aporte válido', ['ok', false, 'Efectivo'], [$r['codigo'], $r['lograda'], $r['cuenta']]);
same('el saldo de la cuenta baja exacto (los aportes cuentan en el saldo)', Dinero::sumar([$antesE, '-100000.00']), $saldoG($gE));
same('la meta suma el aporte y sigue en curso', ['100000.00', 'en_curso'], [$ahorrado($meta), $metas->findOwned($gina, $meta)?->estado]);
same('RF-20: 33 % y faltan 200.000', [33, '200000.00'], [$metas->findOwned($gina, $meta)?->progreso()['percent'], $metas->findOwned($gina, $meta)?->progreso()['faltante']]);

$antes = (int) $pdo->query('SELECT COUNT(*) FROM aportes_meta')->fetchColumn();
$r = $gm->aportar($gina, $meta, $ap($gA, '60000.00'));
same('un aporte mayor que el saldo (50.000) se rechaza', ['saldo_insuficiente', 'Ahorros', '50000.00'], [$r['codigo'], $r['cuenta'], $r['saldo']]);
same('y no se guardó nada', $antes, (int) $pdo->query('SELECT COUNT(*) FROM aportes_meta')->fetchColumn());
same('un centavo más que el saldo también se rechaza', 'saldo_insuficiente', $gm->aportar($gina, $meta, $ap($gA, '50000.01'))['codigo']);
same('un aporte IGUAL al saldo se acepta y deja la cuenta en cero', ['ok', '0.00'], [$gm->aportar($gina, $meta, $ap($gA, '50000.00'))['codigo'], $saldoG($gA)]);
same('con la cuenta en cero ya no se puede aportar nada', 'saldo_insuficiente', $gm->aportar($gina, $meta, $ap($gA, '0.01'))['codigo']);
same('un primer aporte pequeño pasa', 'ok', $gm->aportar($gina, $meta, $ap($gE, '1.00'))['codigo']);
$resto = $saldoG($gE);
same('y uno por un centavo más de lo que queda se rechaza (los aportes anteriores ya cuentan en el saldo)', 'saldo_insuficiente', $gm->aportar($gina, $meta, $ap($gE, Dinero::sumar([$resto, '0.01'])))['codigo']);

$cuentas->setActiva($gina, $gA, false);
same('una cuenta inactiva no puede aportar', 'cuenta_inactiva', $gm->aportar($gina, $meta, $ap($gA, '1.00'))['codigo']);
$cuentas->setActiva($gina, $gA, true);
same('una cuenta de otro usuario', 'cuenta_ajena', $gm->aportar($gina, $meta, $ap($hE, '1.00'))['codigo']);
same('una cuenta inexistente', 'cuenta_ajena', $gm->aportar($gina, $meta, $ap(999999, '1.00'))['codigo']);
same('una meta de otro usuario', 'meta_ajena', $gm->aportar($gina, $otraMeta, $ap($gE, '1.00'))['codigo']);
same('una meta inexistente', 'meta_ajena', $gm->aportar($gina, 999999, $ap($gE, '1.00'))['codigo']);
same('el otro usuario no ve los aportes de mi meta', [], $aportes->listByMeta($hugo, $meta));
same('el dueño ve el historial, el más reciente primero', true, count($aportes->listByMeta($gina, $meta)) >= 3);

section('GestionMetas: lograr la meta en la misma transacción');
$faltante = Dinero::sumar(['300000.00', '-' . $ahorrado($meta)]);
$r = $gm->aportar($gina, $meta, $ap($gE, Dinero::sumar([$faltante, '-0.01'])));
same('un centavo antes del objetivo: sigue en curso', [false, 'en_curso'], [$r['lograda'], $metas->findOwned($gina, $meta)?->estado]);
$r = $gm->aportar($gina, $meta, $ap($gE, '0.01'));
same('el aporte que completa el objetivo marca la meta como lograda', [true, 'lograda'], [$r['lograda'], $metas->findOwned($gina, $meta)?->estado]);
same('y el total es exactamente el objetivo', '300000.00', $ahorrado($meta));
same('una meta lograda ya no admite aportes', 'meta_cerrada', $gm->aportar($gina, $meta, $ap($gE, '1.00'))['codigo']);
$chica = $metas->create($gina, 'Chica', '100.00', null);
same('pasarse del objetivo también la logra', [true, 'lograda', 0], (static function () use ($gm, $gina, $chica, $ap, $gE, $metas): array {
    $r = $gm->aportar($gina, $chica, $ap($gE, '150.00'));
    return [$r['lograda'], $metas->findOwned($gina, $chica)?->estado, (int) $metas->findOwned($gina, $chica)?->progreso()['faltante']];
})());

section('GestionMetas: editar, cancelar y eliminar');
$m2 = $metas->create($gina, 'Computador', '3000000.00', '2027-01-01');
$gm->aportar($gina, $m2, $ap($gE, '1000.00'));
same('editar cambia nombre, objetivo y fecha', 'ok', $gm->editar($gina, $m2, ['nombre' => 'Portátil', 'monto_objetivo' => '5000.00', 'fecha_limite' => null])['codigo']);
same('y queda guardado', ['Portátil', '5000.00', null], [$metas->findOwned($gina, $m2)?->nombre, $metas->findOwned($gina, $m2)?->montoObjetivo, $metas->findOwned($gina, $m2)?->fechaLimite]);
$r = $gm->editar($gina, $m2, ['nombre' => 'Portátil', 'monto_objetivo' => '1000.00', 'fecha_limite' => null]);
same('bajar el objetivo hasta lo ya ahorrado la deja lograda', [true, 'lograda'], [$r['lograda'], $metas->findOwned($gina, $m2)?->estado]);
same('una meta lograda ya no se edita', 'meta_cerrada', $gm->editar($gina, $m2, ['nombre' => 'X', 'monto_objetivo' => '9.00', 'fecha_limite' => null])['codigo']);
same('editar una meta ajena: no existe', 'no_existe', $gm->editar($hugo, $m2, ['nombre' => 'X', 'monto_objetivo' => '9.00', 'fecha_limite' => null])['codigo']);

$m3 = $metas->create($gina, 'Fondo', '900000.00', null);
$saldoAntes = $saldoG($gE);
$gm->aportar($gina, $m3, $ap($gE, '120.50'));
$gm->aportar($gina, $m3, $ap($gE, '30.25'));
same('con aportes la cuenta tiene menos', Dinero::sumar([$saldoAntes, '-150.75']), $saldoG($gE));
same('no se puede eliminar una meta con aportes', 'tiene_aportes', $gm->eliminar($gina, $m3));
same('cancelar una meta ajena: no existe', 'no_existe', $gm->cancelar($hugo, $m3)['codigo']);
$c = $gm->cancelar($gina, $m3);
same('cancelar devuelve el dinero aportado', ['ok', '150.75'], [$c['codigo'], $c['devuelto']]);
same('el saldo de la cuenta se recupera exacto', $saldoAntes, $saldoG($gE));
same('los aportes se borran y la meta queda cancelada', [0, 'cancelada', '0.00'], [count($aportes->listByMeta($gina, $m3)), $metas->findOwned($gina, $m3)?->estado, $ahorrado($m3)]);
same('cancelar otra vez: ya cerrada', 'meta_cerrada', $gm->cancelar($gina, $m3)['codigo']);
same('una meta cancelada no admite aportes', 'meta_cerrada', $gm->aportar($gina, $m3, $ap($gE, '1.00'))['codigo']);
same('una meta lograda no se cancela (el dinero ya cumplió su fin)', 'meta_cerrada', $gm->cancelar($gina, $meta)['codigo']);
same('una meta cancelada sin aportes se puede eliminar', ['ok', null], [$gm->eliminar($gina, $m3), $metas->findOwned($gina, $m3)]);
same('eliminar una meta ajena: no existe', 'no_existe', $gm->eliminar($hugo, $meta));
same('eliminar una meta inexistente: no existe', 'no_existe', $gm->eliminar($gina, 999999));
$vacia = $metas->create($gina, 'Sin aportes', '10.00', null);
same('una meta sin aportes se elimina', 'ok', $gm->eliminar($gina, $vacia));

section('MetaRepository: listado y vencimiento');
$pdo->exec("DELETE FROM aportes_meta WHERE id_meta IN (SELECT id_meta FROM metas_ahorro WHERE id_usuario = $gina)");
$pdo->exec("DELETE FROM metas_ahorro WHERE id_usuario = $gina");
$lejos = $metas->create($gina, 'Lejos', '10.00', '2030-01-01');
$cerca = $metas->create($gina, 'Cerca', '10.00', '2026-12-01');
$sinFecha = $metas->create($gina, 'Sin fecha', '10.00', null);
$hecha = $metas->create($gina, 'Hecha', '10.00', null);
$gm->aportar($gina, $hecha, $ap($gE, '10.00'));
$lista = array_map(static fn ($x) => $x->nombre, $metas->listByUser($gina));
same('en curso primero (la fecha más próxima antes, sin fecha al final) y luego las logradas', ['Cerca', 'Lejos', 'Sin fecha', 'Hecha'], $lista);
same('otro usuario no ve mis metas', 1, count($metas->listByUser($hugo)));
same('una meta en curso con la fecha pasada está vencida', [true, false, false], [$metas->findOwned($gina, $cerca)?->vencida('2026-12-02'), $metas->findOwned($gina, $lejos)?->vencida('2026-12-02'), $metas->findOwned($gina, $sinFecha)?->vencida('2030-01-01')]);
same('una meta lograda nunca está vencida', false, $metas->findOwned($gina, $hecha)?->vencida('2099-01-01'));
same('findOwned de una meta ajena', null, $metas->findOwned($hugo, $lejos));
same('el número de aportes se cuenta', [1, 0], [$metas->findOwned($gina, $hecha)?->numAportes, $metas->findOwned($gina, $lejos)?->numAportes]);

section('Bloqueo de la cuenta entre aportes y movimientos');
$o1 = Connection::create();
$o1->exec('SET SESSION innodb_lock_wait_timeout = 1');
$gmOtra = new App\Metas\GestionMetas($o1, new App\Repository\MetaRepository($o1), new App\Repository\AporteRepository($o1), new App\Repository\CuentaRepository($o1));
Transaction::run($container->pdo(), function () use ($cuentas, $gina, $gE, $gmOtra, $lejos, $ap): void {
    $cuentas->lockForWrite($gina, $gE);
    $codigo = null;
    try {
        $gmOtra->aportar($gina, $lejos, $ap($gE, '1.00'));
    } catch (PDOException $e) {
        $codigo = (int) ($e->errorInfo[1] ?? 0);
    }
    same('mientras alguien tiene la cuenta bloqueada, un aporte espera (timeout 1205)', 1205, $codigo);
});
same('liberada la cuenta, el aporte pasa', 'ok', $gmOtra->aportar($gina, $lejos, $ap($gE, '1.00'))['codigo']);
$movsOtra = new App\Movimientos\GestionMovimientos($o1, new App\Repository\MovimientoRepository($o1), new App\Repository\CuentaRepository($o1), new App\Repository\CategoriaRepository($o1));
$ingresoId = (int) $gestion2->guardar($elena, null, $dato(['tipo' => 'ingreso', 'monto' => '10.00', 'id_categoria' => $catIngreso, 'descripcion' => null]))['id'];
Transaction::run($container->pdo(), function () use ($cuentas, $elena, $efectivo, $movsOtra, $ingresoId): void {
    $cuentas->lockForWrite($elena, $efectivo);
    $codigo = null;
    try {
        $movsOtra->eliminar($elena, $ingresoId);
    } catch (PDOException $e) {
        $codigo = (int) ($e->errorInfo[1] ?? 0);
    }
    same('mientras la cuenta está bloqueada, eliminar un movimiento suyo también espera (timeout 1205)', 1205, $codigo);
});
same('liberada, se elimina', true, $movsOtra->eliminar($elena, $ingresoId));
same('eliminar el movimiento de otro usuario no hace nada', false, $gestion2->eliminar($fabio, $ingresoId));
same('eliminar uno inexistente', false, $gestion2->eliminar($elena, 999999));

// ---- Panel y reportes (RF-21 a RF-23) ------------------------------------------------------------------------------

section('ReporteRepository: totales y gastos por categoría');
$reportes = $container->reportes();
$ines = (int) $usuarios->create('Inés', 'ines@correo.com', 'x');
$jaime = (int) $usuarios->create('Jaime', 'jaime@correo.com', 'x');
$iE = $cuentas->create($ines, 'Efectivo', 'efectivo', '0');
$jE = $cuentas->create($jaime, 'Efectivo de Jaime', 'efectivo', '0');
[$kA, $kB] = array_map('intval', $pdo->query("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'gasto' ORDER BY nombre LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
$kIngreso = (int) $pdo->query("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'ingreso' LIMIT 1")->fetchColumn();
$ins = static function (int $uid, int $cid, int $kid, string $tipo, string $monto, string $fecha) use ($pdo): void {
    $pdo->prepare('INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha) VALUES (?, ?, ?, ?, ?, ?)')->execute([$uid, $cid, $kid, $tipo, $monto, $fecha]);
};
$ins($ines, $iE, $kA, 'gasto', '100.00', '2026-11-01');   // primer día
$ins($ines, $iE, $kA, 'gasto', '50.50', '2026-11-30');    // último día
$ins($ines, $iE, $kB, 'gasto', '25.25', '2026-11-15');
$ins($ines, $iE, $kIngreso, 'ingreso', '1000.00', '2026-11-10');
$ins($ines, $iE, $kA, 'gasto', '999.00', '2026-10-31');   // mes anterior
$ins($ines, $iE, $kA, 'gasto', '888.00', '2026-12-01');   // mes siguiente
$ins($jaime, $jE, $kA, 'gasto', '7777.00', '2026-11-15'); // otro usuario
$ins($jaime, $jE, $kIngreso, 'ingreso', '6666.00', '2026-11-15');
same('totales de noviembre (incluye el primer y el último día, excluye otros meses y otros usuarios)', ['1000.00', '175.75'], array_values($reportes->totalesMes($ines, 11, 2026)));
same('el mes sin movimientos da ceros', ['0.00', '0.00'], array_values($reportes->totalesMes($ines, 8, 2026)));
same('otro usuario ve solo lo suyo', ['6666.00', '7777.00'], array_values($reportes->totalesMes($jaime, 11, 2026)));
$cat = $reportes->gastosPorCategoria($ines, 11, 2026);
same('gastos por categoría: de mayor a menor', [['A', '150.50'], ['B', '25.25']], array_map(static fn (array $c): array => [$c['nombre'] === $categorias->findById($kA)?->nombre ? 'A' : 'B', $c['total']], $cat));
same('un ingreso nunca cuenta como gasto', 2, count($cat));
same('trae el color de la categoría', true, preg_match('/^#[0-9A-F]{6}$/', $cat[0]['color']) === 1);
same('el mes de diciembre solo ve su gasto', [['888.00']], array_map(static fn (array $c): array => [$c['total']], $reportes->gastosPorCategoria($ines, 12, 2026)));
same('otro usuario no ve mis categorías', ['7777.00'], array_column($reportes->gastosPorCategoria($jaime, 11, 2026), 'total'));
same('un mes sin gastos', [], $reportes->gastosPorCategoria($ines, 3, 2026));

section('ReporteRepository: evolución mensual');
$ev = $reportes->evolucion($ines, 11, 2026, 6);
same('6 meses, de junio a noviembre', [[6, 2026], [7, 2026], [8, 2026], [9, 2026], [10, 2026], [11, 2026]], array_map(static fn (array $m): array => [$m['mes'], $m['anio']], $ev));
same('los meses sin movimientos van en cero', ['0.00', '0.00'], [$ev[0]['gastos'], $ev[3]['ingresos']]);
same('octubre solo tiene su gasto del 31', ['0.00', '999.00'], [$ev[4]['ingresos'], $ev[4]['gastos']]);
same('noviembre trae sus totales', ['1000.00', '175.75'], [$ev[5]['ingresos'], $ev[5]['gastos']]);
same('diciembre queda fuera de la ventana', 6, count($ev));
$ev2 = $reportes->evolucion($ines, 2, 2027, 6);
same('la ventana cruza el cambio de año (sep 2026 a feb 2027)', [[9, 2026], [10, 2026], [11, 2026], [12, 2026], [1, 2027], [2, 2027]], array_map(static fn (array $m): array => [$m['mes'], $m['anio']], $ev2));
same('y diciembre trae su gasto', '888.00', $ev2[3]['gastos']);
same('12 meses', 12, count($reportes->evolucion($ines, 11, 2026, 12)));
same('un solo mes', 1, count($reportes->evolucion($ines, 11, 2026, 1)));
same('el tope es 24 meses', 24, count($reportes->evolucion($ines, 11, 2026, 99)));
same('otro usuario no mezcla sus datos', '7777.00', $reportes->evolucion($jaime, 11, 2026, 6)[5]['gastos']);

section('MovimientoRepository::searchAfter (paginación por cursor)');
$sinF = ['desde' => '', 'hasta' => '', 'cuenta' => 0, 'categoria' => 0, 'tipo' => ''];
for ($i = 1; $i <= 23; $i++) {
    $ins($ines, $iE, $kA, 'gasto', '1.00', $i % 4 === 0 ? '2026-09-10' : sprintf('2026-09-%02d', 1 + $i % 9));   // varias con la MISMA fecha
}
$todo = array_map(static fn ($m) => $m->id, $movs->search($ines, $sinF, 500, 0));
$recorrido = [];
$cursor = null;
$lotes = 0;
do {
    $lote = $movs->searchAfter($ines, $sinF, $cursor, 7);
    $lotes++;
    foreach ($lote as $m) {
        $recorrido[] = $m->id;
    }
    $cursor = $lote === [] ? null : ['fecha' => $lote[array_key_last($lote)]->fecha, 'id' => $lote[array_key_last($lote)]->id];
} while ($lote !== [] && count($lote) === 7);
same('recorrer por lotes da exactamente lo mismo que el listado, en el mismo orden', $todo, $recorrido);
same('sin repetidos ni faltantes', [count($todo), count($todo)], [count($recorrido), count(array_unique($recorrido))]);
same('hizo varios lotes', true, $lotes > 1);
same('con filtros solo trae lo filtrado', true, (static function () use ($movs, $ines, $sinF): bool {
    $f = ['tipo' => 'ingreso'] + $sinF;
    $r = $movs->searchAfter($ines, $f, null, 50);
    return count($r) === 1 && $r[0]->esIngreso();
})());
same('un cursor pasado el final no trae nada', [], $movs->searchAfter($ines, $sinF, ['fecha' => '1999-01-01', 'id' => 1], 50));
same('otro usuario no recibe los míos', 2, count($movs->searchAfter($jaime, $sinF, null, 50)));

// ---- Recuperación de contraseña (RF-04) ---------------------------------------------------------------------------------

section('RecuperacionClave: pedir el enlace');
$correos = new class () implements App\Mail\Mailer {
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $enviados = [];
    public bool $fallar = false;
    public function send(string $to, string $subject, string $body): void
    {
        if ($this->fallar) {
            throw new App\Mail\MailException('SMTP caído');
        }
        $this->enviados[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
};
$container->useMailer($correos);
$tokens = $container->restablecimientos();
$recup = new App\Auth\RecuperacionClave($container->pdo(), $usuarios, $tokens, $correos, 'https://lucas.ejemplo.com');
$olga = (int) $usuarios->create('Olga', 'olga@correo.com', password_hash('clave-vieja-1', PASSWORD_DEFAULT));
$cuentaTokens = static fn (int $uid): int => (int) $pdo->query("SELECT COUNT(*) FROM restablecimientos WHERE id_usuario = $uid")->fetchColumn();
$tokenDe = static function (array $mail): string {
    preg_match('/token=([a-f0-9]{64})/', $mail['body'], $m);
    return $m[1] ?? '';
};

$recup->solicitar('olga@correo.com');
same('un correo registrado recibe un mensaje', 1, count($correos->enviados));
$mail = $correos->enviados[0];
same('dirigido a esa persona, con asunto claro', ['olga@correo.com', 'Restablece tu contraseña de Lucas'], [$mail['to'], $mail['subject']]);
$token1 = $tokenDe($mail);
same('con un enlace con token de 64 hexadecimales hacia el origen configurado', true, str_contains($mail['body'], 'https://lucas.ejemplo.com/restablecer.php?token=') && strlen($token1) === 64);
same('el cuerpo saluda por el nombre y avisa de la vigencia', true, str_contains($mail['body'], 'Hola Olga') && str_contains($mail['body'], '30 minutos'));
same('en la BD NO está el token, solo su hash (SHA-256)', [0, 1], [(int) $pdo->query("SELECT COUNT(*) FROM restablecimientos WHERE token_hash = '$token1'")->fetchColumn(), (int) $pdo->query('SELECT COUNT(*) FROM restablecimientos WHERE token_hash = ' . $pdo->quote(hash('sha256', $token1)))->fetchColumn()]);
same('el enlace vale', true, $recup->esValido($token1));
$expira = (int) $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, NOW(), expira_en) FROM restablecimientos WHERE id_usuario = $olga")->fetchColumn();
same('caduca en unos 30 minutos', true, $expira >= 29 && $expira <= 30);

$antes = count($correos->enviados);
$recup->solicitar('nadie@correo.com');
same('un correo que no existe: no se envía nada', $antes, count($correos->enviados));
same('ni se guarda nada', 0, (int) $pdo->query("SELECT COUNT(*) FROM restablecimientos WHERE id_usuario NOT IN ($olga)")->fetchColumn());
$recup->solicitar('  OLGA@Correo.com ');
same('el correo no distingue mayúsculas ni espacios', $antes + 1, count($correos->enviados));
$token2 = $tokenDe($correos->enviados[$antes]);
same('pedir otro enlace anula el anterior', [false, true], [$recup->esValido($token1), $recup->esValido($token2)]);
same('queda un solo enlace pendiente', 1, $cuentaTokens($olga));
$pdo->exec("UPDATE usuarios SET activo = 0 WHERE id_usuario = $olga");
$antes = count($correos->enviados);
$recup->solicitar('olga@correo.com');
same('una cuenta desactivada no recibe enlaces', $antes, count($correos->enviados));
$pdo->exec("UPDATE usuarios SET activo = 1 WHERE id_usuario = $olga");

$correos->fallar = true;
$sinError = true;
try {
    $recup->solicitar('olga@correo.com');
} catch (Throwable) {
    $sinError = false;
}
same('si el servidor de correo falla, no se propaga el error (se registra)', true, $sinError);
$correos->fallar = false;

section('RecuperacionClave: usar el enlace');
$recup->solicitar('olga@correo.com');
$token = $tokenDe($correos->enviados[array_key_last($correos->enviados)]);
same('un token con forma inválida no sirve', [false, false], [$recup->esValido('abc'), $recup->restablecer('abc', 'clave-nueva-1')]);
same('un token con forma válida pero falso no sirve', false, $recup->restablecer(str_repeat('0', 64), 'clave-nueva-1'));
same('restablecer con el token vigente', true, $recup->restablecer($token, 'clave-nueva-1'));
$olgaAhora = $usuarios->findById($olga);
same('la contraseña nueva funciona y la vieja ya no', [true, false], [password_verify('clave-nueva-1', (string) $olgaAhora?->contrasenaHash), password_verify('clave-vieja-1', (string) $olgaAhora?->contrasenaHash)]);
same('el token queda gastado: no se puede usar dos veces', [false, false], [$recup->esValido($token), $recup->restablecer($token, 'otra-clave-99')]);
same('y la contraseña no cambió con el segundo intento', true, password_verify('clave-nueva-1', (string) $usuarios->findById($olga)?->contrasenaHash));
same('el enlace se marcó como usado', 1, (int) $pdo->query("SELECT COUNT(*) FROM restablecimientos WHERE id_usuario = $olga AND usado_en IS NOT NULL")->fetchColumn());

$recup->solicitar('olga@correo.com');
$tokenViejo = $tokenDe($correos->enviados[array_key_last($correos->enviados)]);
$pdo->exec("UPDATE restablecimientos SET expira_en = NOW() - INTERVAL 1 MINUTE WHERE id_usuario = $olga AND usado_en IS NULL");
same('un enlace caducado no sirve', [false, false], [$recup->esValido($tokenViejo), $recup->restablecer($tokenViejo, 'clave-nueva-2')]);
same('y la contraseña sigue igual', true, password_verify('clave-nueva-1', (string) $usuarios->findById($olga)?->contrasenaHash));

$recup->solicitar('olga@correo.com');
$tokenA = $tokenDe($correos->enviados[array_key_last($correos->enviados)]);
$pdo->exec("INSERT INTO restablecimientos (id_usuario, token_hash, expira_en) VALUES ($olga, " . $pdo->quote(hash('sha256', str_repeat('c', 64))) . ', NOW() + INTERVAL 10 MINUTE)');
same('al usar un enlace, los demás pendientes se borran', [true, 1], [$recup->restablecer($tokenA, 'clave-nueva-3'), $cuentaTokens($olga)]);
$recup->solicitar('olga@correo.com');
$tokenB = $tokenDe($correos->enviados[array_key_last($correos->enviados)]);
$pdo->exec("UPDATE usuarios SET activo = 0 WHERE id_usuario = $olga");
same('una cuenta desactivada no puede restablecer, aunque tenga el enlace', [false, true], [$recup->restablecer($tokenB, 'clave-nueva-4'), password_verify('clave-nueva-3', (string) $usuarios->findById($olga)?->contrasenaHash)]);
$pdo->exec("UPDATE usuarios SET activo = 1 WHERE id_usuario = $olga");
same('y el enlace sigue sin gastarse (no se perdió por el intento fallido)', true, $recup->esValido($tokenB));

$o2 = Connection::create();
$o2->exec('SET SESSION innodb_lock_wait_timeout = 1');
$recup2 = new App\Auth\RecuperacionClave($o2, new App\Repository\UsuarioRepository($o2), new App\Repository\RestablecimientoRepository($o2), $correos, 'https://x');
Transaction::run($container->pdo(), function () use ($tokens, $tokenB, $recup2): void {
    $tokens->lockValid(hash('sha256', $tokenB));
    $codigo = null;
    try {
        $recup2->restablecer($tokenB, 'carrera-1234');
    } catch (PDOException $e) {
        $codigo = (int) ($e->errorInfo[1] ?? 0);
    }
    same('dos usos simultáneos del mismo enlace: el segundo espera (timeout 1205), no se pisan', 1205, $codigo);
});

section('Sesión y rehash: la huella debe coincidir con la BD');
$usuarios->updatePasswordHash($olga, password_hash('clave-rehash-1', PASSWORD_BCRYPT, ['cost' => 4]));
$entra = (new Autenticador($usuarios))->authenticate('olga@correo.com', 'clave-rehash-1');
same('tras el rehash, el usuario devuelto lleva el hash que quedó en la BD', (string) $usuarios->findById($olga)?->contrasenaHash, $entra?->contrasenaHash);
same('así la huella de sesión coincide y no se cierra la sesión recién abierta', password_fingerprint((string) $usuarios->findById($olga)?->contrasenaHash), password_fingerprint((string) $entra?->contrasenaHash));

// ---- Perfil y eliminación de la cuenta (RF-03, RNF-20, D5) ----------------------------------------------------------------

section('Perfil: updateProfile');
$usuarios->updateProfile($olga, 'Olga Lucía', 'EUR');
same('se guardan nombre y moneda', ['Olga Lucía', 'EUR'], [$usuarios->findById($olga)?->nombre, $usuarios->findById($olga)?->moneda]);
same('lo demás no cambia', ['olga@correo.com', 'usuario'], [$usuarios->findById($olga)?->correo, $usuarios->findById($olga)?->rol]);

section('EliminarCuenta: borra TODO y solo lo del usuario');
$borrar = $container->eliminarCuenta();
$vic = (int) $usuarios->create('Víctor', 'victor@correo.com', 'x');
$otra = (int) $usuarios->create('Otra persona', 'otra@correo.com', 'x');
$vcE = $cuentas->create($vic, 'Efectivo', 'efectivo', '1000.00');
$vcB = $cuentas->create($vic, 'Banco', 'bancaria', '500.00');
$ocE = $cuentas->create($otra, 'Efectivo de otra', 'efectivo', '700.00');
$vCat = $categorias->create($vic, 'Categoría de Víctor', 'gasto', '#123456');
$oCat = $categorias->create($otra, 'Categoría de otra', 'gasto', '#654321');
$kDef = (int) $pdo->query("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'gasto' LIMIT 1")->fetchColumn();
$ins($vic, $vcE, $vCat, 'gasto', '10.00', '2026-11-01');   // usa su propia categoría
$ins($vic, $vcE, $kDef, 'gasto', '20.00', '2026-11-02');   // usa una predeterminada
$ins($vic, $vcB, $kDef, 'gasto', '30.00', '2026-11-03');
$ins($otra, $ocE, $oCat, 'gasto', '40.00', '2026-11-04');
$presupuestos->create($vic, $vCat, 11, 2026, '100.00');
$presupuestos->create($vic, $kDef, 11, 2026, '200.00');
$presupuestos->create($otra, $kDef, 11, 2026, '300.00');
$mV = $metas->create($vic, 'Meta de Víctor', '5000.00', null);
$mO = $metas->create($otra, 'Meta de otra', '5000.00', null);
$gm->aportar($vic, $mV, ['id_cuenta' => $vcE, 'monto' => '100.00', 'fecha' => '2026-11-05']);
$gm->aportar($vic, $mV, ['id_cuenta' => $vcB, 'monto' => '50.00', 'fecha' => '2026-11-06']);
$gm->aportar($otra, $mO, ['id_cuenta' => $ocE, 'monto' => '60.00', 'fecha' => '2026-11-06']);
$pdo->exec("INSERT INTO restablecimientos (id_usuario, token_hash, expira_en) VALUES ($vic, " . $pdo->quote(str_repeat('d', 64)) . ', NOW() + INTERVAL 10 MINUTE)');
$limVic = new LimitadorIntentos($container->intentos());
$limVic->registrar('login', 'victor@correo.com', '10.1.1.1');
$limVic->registrar('recuperacion', 'victor@correo.com', '10.1.1.1');
$filasDe = static function (int $uid) use ($pdo): array {
    $q = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
    return [
        'usuarios' => $q("SELECT COUNT(*) FROM usuarios WHERE id_usuario = $uid"),
        'cuentas' => $q("SELECT COUNT(*) FROM cuentas WHERE id_usuario = $uid"),
        'categorias' => $q("SELECT COUNT(*) FROM categorias WHERE id_usuario = $uid"),
        'transacciones' => $q("SELECT COUNT(*) FROM transacciones WHERE id_usuario = $uid"),
        'presupuestos' => $q("SELECT COUNT(*) FROM presupuestos WHERE id_usuario = $uid"),
        'metas' => $q("SELECT COUNT(*) FROM metas_ahorro WHERE id_usuario = $uid"),
        'aportes' => $q("SELECT COUNT(*) FROM aportes_meta WHERE id_meta IN (SELECT id_meta FROM metas_ahorro WHERE id_usuario = $uid) OR id_cuenta IN (SELECT id_cuenta FROM cuentas WHERE id_usuario = $uid)"),
        'restablecimientos' => $q("SELECT COUNT(*) FROM restablecimientos WHERE id_usuario = $uid"),
    ];
};
$antesVic = $filasDe($vic);
same('antes: Víctor tiene datos en todas las tablas', true, !in_array(0, $antesVic, true));
$otraAntes = $filasDe($otra);
$catsDef = (int) $pdo->query('SELECT COUNT(*) FROM categorias WHERE id_usuario IS NULL')->fetchColumn();
$huellasAntes = (int) $pdo->query("SELECT COUNT(*) FROM intentos_acceso WHERE clave = '" . LimitadorIntentos::claveCorreo('victor@correo.com') . "'")->fetchColumn();
same('y hay intentos de acceso con el hash de su correo', 2, $huellasAntes);
// (La clave por par es un hash de correo+IP: no se puede localizar sin la IP, pero caduca a los 15 min y se purga sola.)

same('eliminar la cuenta de un usuario normal', 'ok', $borrar->eliminar($vic));
same('NO queda ninguna fila suya en ninguna tabla (RNF-20)', array_fill_keys(array_keys($antesVic), 0), $filasDe($vic));
same('ni rastro de su correo en los intentos de acceso', 0, (int) $pdo->query("SELECT COUNT(*) FROM intentos_acceso WHERE clave = '" . LimitadorIntentos::claveCorreo('victor@correo.com') . "'")->fetchColumn());
same('los datos de otra persona quedan intactos', $otraAntes, $filasDe($otra));
same('y las categorías predeterminadas también', $catsDef, (int) $pdo->query('SELECT COUNT(*) FROM categorias WHERE id_usuario IS NULL')->fetchColumn());
same('el saldo de la otra persona sigue intacto (700 − 40 de gasto − 60 de aporte)', '600.00', (string) $cuentas->findOwned($otra, $ocE)?->saldo);
same('eliminarla otra vez: ya no existe', 'no_existe', $borrar->eliminar($vic));
same('un usuario que no existe', 'no_existe', $borrar->eliminar(999999));
$adminAntes = $usuarios->findById($adminId);
same('un administrador no puede eliminar su propia cuenta', 'es_admin', $borrar->eliminar($adminId));
same('y su cuenta sigue ahí', $adminAntes?->correo, $usuarios->findById($adminId)?->correo);
same('eliminar a quien no tiene datos también funciona', ['ok', 0], (static function () use ($usuarios, $borrar, $filasDe): array {
    $vacio = (int) $usuarios->create('Vacío', 'vacio@correo.com', 'x');
    return [$borrar->eliminar($vacio), $filasDe($vacio)['usuarios']];
})());

section('Migraciones');
same('las migraciones son idempotentes: aplicar de nuevo la 001 no falla ni cambia nada', true, (static function () use ($pdo): bool {
    foreach (SqlScript::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/001-recuperacion-de-clave.sql')) as $s) {
        $pdo->exec($s);
    }
    return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'restablecimientos'")->fetchColumn() === 1;
})());
same('intentos_acceso admite los tipos nuevos', true, (static function () use ($pdo): bool {
    $pdo->exec("INSERT INTO intentos_acceso (tipo, clave) VALUES ('recuperacion', 'x'), ('clave', 'y')");
    return true;
})());

section('GestionTransferencias: transferencias entre cuentas y pago de tarjetas (Fase 8)');
$gt = $container->gestionTransferencias();
$trRepo = $container->transferencias();
$tUser = (int) $usuarios->create('Tomás', 'tomas@correo.com', 'x');
$tOtro = (int) $usuarios->create('Otro', 'otro.tomas@correo.com', 'x');
$tAhorros = $cuentas->create($tUser, 'Ahorros', 'ahorro', '1000000.00');
$tEfectivo = $cuentas->create($tUser, 'Efectivo', 'efectivo', '50000.00');
$tVisa = $cuentas->create($tUser, 'Visa', 'tarjeta_credito', '-400000.00');
$tAjena = $cuentas->create($tOtro, 'Ajena', 'efectivo', '500000.00');

$resT1 = $gt->guardar($tUser, null, [
    'id_cuenta_origen' => $tAhorros,
    'id_cuenta_destino' => $tEfectivo,
    'monto' => '200000.00',
    'fecha' => '2026-11-25',
    'descripcion' => 'Retiro cajero',
]);
same('transferencia válida de Ahorros a Efectivo', 'ok', $resT1['codigo']);
same('Ahorros baja 200.000 y Efectivo sube 200.000', ['800000.00', '250000.00'], [
    (string) $cuentas->findOwned($tUser, $tAhorros)?->saldo,
    (string) $cuentas->findOwned($tUser, $tEfectivo)?->saldo,
]);

$resT2 = $gt->guardar($tUser, null, [
    'id_cuenta_origen' => $tAhorros,
    'id_cuenta_destino' => $tVisa,
    'monto' => '150000.00',
    'fecha' => '2026-11-25',
    'descripcion' => 'Abono tarjeta Visa',
]);
same('abono a tarjeta de crédito desde Ahorros', 'ok', $resT2['codigo']);
same('Ahorros baja a 650.000 y la deuda de Visa baja de -400.000 a -250.000', ['650000.00', '-250000.00'], [
    (string) $cuentas->findOwned($tUser, $tAhorros)?->saldo,
    (string) $cuentas->findOwned($tUser, $tVisa)?->saldo,
]);
same('las transferencias NO cuentan como ingresos ni gastos en los reportes del mes', ['0.00', '0.00'], array_values($reportes->totalesMes($tUser, 11, 2026)));
same('superar el saldo de la cuenta de origen se rechaza', 'saldo_insuficiente', $gt->guardar($tUser, null, [
    'id_cuenta_origen' => $tEfectivo,
    'id_cuenta_destino' => $tAhorros,
    'monto' => '900000.00',
    'fecha' => '2026-11-25',
    'descripcion' => null,
])['codigo']);
same('usar una cuenta ajena se rechaza como cuenta_ajena', 'cuenta_ajena', $gt->guardar($tUser, null, [
    'id_cuenta_origen' => $tAhorros,
    'id_cuenta_destino' => $tAjena,
    'monto' => '1000.00',
    'fecha' => '2026-11-25',
    'descripcion' => null,
])['codigo']);

$t1Id = (int) $resT1['id'];
same('editar transferencia de 200.000 a 100.000 recalcula ambas cuentas', 'ok', $gt->guardar($tUser, $t1Id, [
    'id_cuenta_origen' => $tAhorros,
    'id_cuenta_destino' => $tEfectivo,
    'monto' => '100000.00',
    'fecha' => '2026-11-25',
    'descripcion' => 'Retiro menor',
])['codigo']);
same('saldos tras editar: Ahorros 750.000, Efectivo 150.000', ['750000.00', '150000.00'], [
    (string) $cuentas->findOwned($tUser, $tAhorros)?->saldo,
    (string) $cuentas->findOwned($tUser, $tEfectivo)?->saldo,
]);
same('eliminar transferencia devuelve el dinero a origen', 'ok', $gt->eliminar($tUser, $t1Id)['codigo']);
same('saldos tras eliminar: Ahorros 850.000, Efectivo 50.000', ['850000.00', '50000.00'], [
    (string) $cuentas->findOwned($tUser, $tAhorros)?->saldo,
    (string) $cuentas->findOwned($tUser, $tEfectivo)?->saldo,
]);

section('GestionRecurrentes: movimientos recurrentes y gastos fijos (Fase 9)');
$gr = $container->gestionRecurrentes();
$recRepo = $container->recurrentes();
$rUser = (int) $usuarios->create('Rosa', 'rosa@correo.com', 'x');
$rBanco = $cuentas->create($rUser, 'Banco Rosa', 'bancaria', '500000.00');
$rCatGasto = (int) $pdo->query("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'gasto' LIMIT 1")->fetchColumn();
$rCatIngreso = (int) $pdo->query("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'ingreso' LIMIT 1")->fetchColumn();

$resR1 = $gr->guardar($rUser, null, [
    'tipo' => 'gasto',
    'monto' => '120000.00',
    'frecuencia' => 'mensual',
    'proxima_fecha' => '2026-10-05',
    'id_cuenta' => $rBanco,
    'id_categoria' => $rCatGasto,
    'descripcion' => 'Internet Fibra',
    'auto_aplicar' => true,
]);
same('crear programación recurrente automática', 'ok', $resR1['codigo']);
$r1Id = (int) $resR1['id'];
same('procesarVencidos al 2026-11-10 aplica octubre y noviembre y avanza a diciembre', ['aplicados' => 2, 'retenidosPorSaldo' => 0], $gr->procesarVencidos($rUser, '2026-11-10'));
same('la próxima fecha quedó en 2026-12-05', '2026-12-05', $recRepo->findOwned($rUser, $r1Id)?->proximaFecha);
same('el saldo de Banco Rosa bajó 240.000 (de 500.000 a 260.000)', '260000.00', (string) $cuentas->findOwned($rUser, $rBanco)?->saldo);

$resR2 = $gr->guardar($rUser, null, [
    'tipo' => 'gasto',
    'monto' => '300000.00',
    'frecuencia' => 'mensual',
    'proxima_fecha' => '2026-11-10',
    'id_cuenta' => $rBanco,
    'id_categoria' => $rCatGasto,
    'descripcion' => 'Arriendo',
    'auto_aplicar' => true,
]);
$r2Id = (int) $resR2['id'];
same('si el gasto recurrente automático supera el saldo disponible (260.000), se retiene sin dejar la cuenta en negativo', ['aplicados' => 0, 'retenidosPorSaldo' => 1], $gr->procesarVencidos($rUser, '2026-11-10'));
same('y el saldo sigue en 260.000', '260000.00', (string) $cuentas->findOwned($rUser, $rBanco)?->saldo);
same('saltarPeriodo avanza la próxima fecha sin tocar el saldo', ['2026-12-10', '260000.00'], [
    $gr->saltarPeriodo($rUser, $r2Id),
    (string) $cuentas->findOwned($rUser, $rBanco)?->saldo,
]);

section('GestionEspacios: espacios compartidos y cuenta familiar (Fase 10)');
$ge = $container->gestionEspacios();
$espRepo = $container->espacios();
$f1 = (int) $usuarios->create('Ana Familia', 'ana.fam@correo.com', 'x');
$usuarios->marcarCorreoVerificado($f1);
$f2 = (int) $usuarios->create('Luis Familia', 'luis.fam@correo.com', 'x');
$usuarios->marcarCorreoVerificado($f2);
$f3 = (int) $usuarios->create('Intruso', 'intruso.fam@correo.com', 'x');

$espId = $ge->crear($f1, 'Hogar Ana y Luis', 'COP');
same('el creador nace como propietario del espacio', 'propietario', $espRepo->findForMember($f1, $espId)?->rolUsuario);
same('otro usuario aún no es miembro', null, $espRepo->findForMember($f2, $espId));

$inv = $ge->invitar($f1, 'Ana Familia', $espId, 'luis.fam@correo.com', 'editor');
same('invitar a Luis devuelve ok y token', ['ok', true], [$inv['codigo'], is_string($inv['token']) && strlen($inv['token']) === 64]);
same('Luis ve la invitación pendiente para su correo', 1, count($espRepo->listInvitationsForEmail('luis.fam@correo.com')));
$acepta = $ge->aceptarInvitacion($f2, 'luis.fam@correo.com', null, $inv['token']);
same('Luis acepta la invitación con el token', ['ok', $espId], [$acepta['codigo'], $acepta['id_espacio']]);
same('Luis ahora es miembro con rol editor', 'editor', $espRepo->findForMember($f2, $espId)?->rolUsuario);

// Cuenta y movimientos dentro del espacio compartido vs espacio personal
$cPersAna = $cuentas->create($f1, 'Personal Ana', 'ahorro', '500000.00');
$cFamiliar = $cuentas->create($f1, 'Fondo Hogar', 'bancaria', '1000000.00', $espId);
same('en el espacio personal de Ana solo sale su cuenta personal', ['Personal Ana'], array_map(static fn ($c) => $c->nombre, $cuentas->listByUser($f1)));
same('en el espacio compartido tanto Ana como Luis ven la cuenta familiar', ['Fondo Hogar', 'Fondo Hogar'], [
    $cuentas->listByUser($f1, $espId)[0]->nombre,
    $cuentas->listByUser($f2, $espId)[0]->nombre,
]);
same('el intruso no es miembro del espacio', null, $espRepo->findForMember($f3, $espId));

$movFam = $gestion2->guardar($f2, null, [
    'tipo' => 'gasto',
    'monto' => '150000.00',
    'fecha' => '2026-11-25',
    'id_cuenta' => $cFamiliar,
    'id_categoria' => $rCatGasto,
    'descripcion' => 'Mercado semanal',
], $espId);
same('Luis (editor) registra un gasto en la cuenta familiar', 'ok', $movFam['codigo']);
same('Ana ve el saldo actualizado de la cuenta familiar (850.000) y quién registró el movimiento', ['850000.00', 'Luis Familia'], [
    (string) $cuentas->findOwned($f1, $cFamiliar, $espId)?->saldo,
    $movs->search($f1, $sin, 10, 0, $espId)[0]->autorNombre,
]);
same('la cuenta personal de Ana sigue intacta en 500.000', '500000.00', (string) $cuentas->findOwned($f1, $cPersAna)?->saldo);
same('Ana no puede salir siendo la única propietaria', 'ultimo_propietario', $ge->retirarMiembro($f1, $espId, $f1));
same('si Ana elimina su cuenta de usuario, Luis es promovido a propietario y el espacio familiar sobrevive', ['ok', 'propietario', '850000.00'], [
    $borrar->eliminar($f1),
    $espRepo->findForMember($f2, $espId)?->rolUsuario,
    (string) $cuentas->findOwned($f2, $cFamiliar, $espId)?->saldo,
]);

section('Invitaciones: límites anti-abuso (A2)');
$lo = (int) $usuarios->create('Lola Limites', 'lola.lim@correo.com', 'x');
$usuarios->marcarCorreoVerificado($lo);
$eL = $ge->crear($lo, 'Espacio límites', 'COP');
$res = [];
for ($i = 1; $i <= 10; $i++) {
    $res[] = $ge->invitar($lo, 'Lola Limites', $eL, "inv$i@ejemplo.org", 'lector')['codigo'];
}
same('las primeras 10 invitaciones del día salen', array_fill(0, 10, 'ok'), $res);
same('la 11.ª la rechaza el límite por usuario (y no crea invitación ni envía correo)', ['limite_usuario', 10], [
    $ge->invitar($lo, 'Lola Limites', $eL, 'inv11@ejemplo.org', 'lector')['codigo'],
    $espRepo->contarInvitacionesPendientes($eL),
]);
$ge->eliminarEspacio($lo, $eL);
$eL2 = $ge->crear($lo, 'Otro espacio', 'COP');
same('borrar el espacio y crear otro NO reinicia el contador del usuario', 'limite_usuario', $ge->invitar($lo, 'Lola Limites', $eL2, 'inv12@ejemplo.org', 'lector')['codigo']);

$d1 = (int) $usuarios->create('Dani Destino', 'dani.dest@correo.com', 'x');
$usuarios->marcarCorreoVerificado($d1);
$d2 = (int) $usuarios->create('Eli Destino', 'eli.dest@correo.com', 'x');
$usuarios->marcarCorreoVerificado($d2);
$eD1 = $ge->crear($d1, 'Espacio de Dani', 'COP');
$eD2 = $ge->crear($d2, 'Espacio de Eli', 'COP');
$res = [];
for ($i = 1; $i <= 3; $i++) {
    $res[] = $ge->invitar($d1, 'Dani Destino', $eD1, 'victima.spam@ejemplo.org', 'lector')['codigo'];
}
same('una misma dirección puede recibir 3 invitaciones al día (reinvitar sustituye la anterior)', [['ok', 'ok', 'ok'], 1], [$res, $espRepo->contarInvitacionesPendientes($eD1)]);
same('la 4.ª al mismo correo la rechaza el límite por destinatario', 'limite_destino', $ge->invitar($d1, 'Dani Destino', $eD1, 'victima.spam@ejemplo.org', 'lector')['codigo']);
same('y también si la envía OTRO usuario (el límite es por dirección, no por invitador)', 'limite_destino', $ge->invitar($d2, 'Eli Destino', $eD2, 'victima.spam@ejemplo.org', 'lector')['codigo']);
same('mayúsculas en el correo no esquivan el límite', 'limite_destino', $ge->invitar($d2, 'Eli Destino', $eD2, 'VICTIMA.Spam@Ejemplo.org', 'lector')['codigo']);
same('otra dirección distinta sí puede invitarse', 'ok', $ge->invitar($d2, 'Eli Destino', $eD2, 'otra.persona@ejemplo.org', 'lector')['codigo']);

$p1 = (int) $usuarios->create('Pepa Pendientes', 'pepa.pend@correo.com', 'x');
$usuarios->marcarCorreoVerificado($p1);
$eP = $ge->crear($p1, 'Espacio de Pepa', 'COP');
for ($i = 0; $i < 20; $i++) {
    $espRepo->createInvitation($eP, "p$i@ejemplo.org", 'lector', hash('sha256', "token-pendiente-$i"), 172800);
}
same('con 20 invitaciones vigentes, una nueva dirección se rechaza', ['limite_pendientes', 20], [
    $ge->invitar($p1, 'Pepa Pendientes', $eP, 'p-nueva@ejemplo.org', 'lector')['codigo'],
    $espRepo->contarInvitacionesPendientes($eP),
]);
same('pero reinvitar a una dirección que ya tiene invitación vigente sí se permite (la sustituye)', ['ok', 20], [
    $ge->invitar($p1, 'Pepa Pendientes', $eP, 'p0@ejemplo.org', 'lector')['codigo'],
    $espRepo->contarInvitacionesPendientes($eP),
]);
$pdo->exec("UPDATE espacio_invitaciones SET expira_en = TIMESTAMPADD(SECOND, -60, CURRENT_TIMESTAMP) WHERE id_espacio = $eP AND correo LIKE 'p1_@ejemplo.org'");
same('las invitaciones caducadas no cuentan como pendientes', ['ok', 10], [
    $ge->invitar($p1, 'Pepa Pendientes', $eP, 'p-nueva2@ejemplo.org', 'lector')['codigo'],
    $espRepo->contarInvitacionesPendientes($eP) - 1,
]);
$pdo->exec("UPDATE notificaciones_enviadas SET enviado_en = TIMESTAMPADD(HOUR, -25, CURRENT_TIMESTAMP) WHERE id_usuario = $lo");
same('pasadas 24 horas el contador vuelve a permitir invitar', 'ok', $ge->invitar($lo, 'Lola Limites', $eL2, 'inv13@ejemplo.org', 'lector')['codigo']);
$espRepo->addMember($eP, $d1, 'lector');
same('un lector del espacio sigue recibiendo «sin permiso» (el control de permisos va antes que los límites)', 'sin_permiso', $ge->invitar($d1, 'Dani Destino', $eP, 'x1@ejemplo.org', 'lector')['codigo']);
same('y un no miembro recibe «no existe»', 'no_existe', $ge->invitar($d1, 'Dani Destino', $eL2, 'x2@ejemplo.org', 'lector')['codigo']);

section('GestionDeudas: préstamos, desglose capital/interés y liquidación (Fase 11)');
$gd = $container->gestionDeudas();
$dRepo = $container->deudas();
$dUser = (int) $usuarios->create('Daniel Deudas', 'daniel.deudas@correo.com', 'x');
$dCuenta = $cuentas->create($dUser, 'Ahorros Daniel', 'ahorro', '2000000.00');
$deudaId = $dRepo->create($dUser, [
    'nombre' => 'Crédito Libre Inversión',
    'acreedor' => 'Banco X',
    'monto_inicial' => '1000000.00',
    'tasa_mensual_bps' => 200, // 2,00 % M.V. -> interés primer mes = 20.000
    'cuota_minima' => '220000.00',
    'dia_pago' => 15,
]);
$pago1 = $gd->registrarPago($dUser, $deudaId, [
    'id_cuenta' => $dCuenta,
    'monto_total' => '220000.00',
    'modo_pago' => 'cuota',
    'fecha' => '2026-11-15',
    'descripcion' => 'Cuota 1',
]);
same('registrar primera cuota de 220.000 desglosa 20.000 interés y 200.000 capital', ['ok', '200000.00', '20000.00', '800000.00', false], [
    $pago1['codigo'],
    $pago1['abono_capital'],
    $pago1['pago_interes'],
    $pago1['nuevo_saldo'],
    $pago1['pagada'],
]);
same('el saldo de la cuenta de ahorros bajó 220.000 (a 1.780.000)', '1780000.00', (string) $cuentas->findOwned($dUser, $dCuenta)?->saldo);
same('una deuda con pagos registrados no se puede eliminar para no descuadrar el saldo de la cuenta', 'tiene_pagos', $gd->eliminar($dUser, $deudaId));

$pago2 = $gd->registrarPago($dUser, $deudaId, [
    'id_cuenta' => $dCuenta,
    'monto_total' => '800000.00',
    'modo_pago' => 'capital',
    'fecha' => '2026-11-20',
    'descripcion' => 'Abono total a capital',
]);
same('liquidar los 800.000 restantes con abono a capital marca la deuda como pagada', ['ok', '0.00', true, 'pagada'], [
    $pago2['codigo'],
    $pago2['nuevo_saldo'],
    $pago2['pagada'],
    $dRepo->findOwned($dUser, $deudaId)?->estado,
]);

section('ClonadorPresupuestos: clonar del mes anterior y guardar lote 50/30/20 (Fase 12)');
$clonador = $container->clonadorPresupuestos();
$pUser = (int) $usuarios->create('Paula Presupuestos', 'paula.pres@correo.com', 'x');
$guardadosLote = $clonador->guardarLote($pUser, 10, 2026, [
    $catA => '450000.00',
    $catB => '250000.00',
]);
same('guardarLote guarda los 2 presupuestos de octubre 2026', 2, $guardadosLote);
$resClon = $clonador->clonarDelMesAnterior($pUser, 11, 2026);
same('clonarDelMesAnterior copia los 2 presupuestos de octubre a noviembre', ['copiados' => 2, 'omitidos' => 0], $resClon);
same('volver a clonar omite los que ya existen en noviembre', ['copiados' => 0, 'omitidos' => 2], $clonador->clonarDelMesAnterior($pUser, 11, 2026));

section('ConciliacionCuenta e ImportadorCsv (Fase 13)');
$conciliador = $container->conciliacionCuenta();
$importador = $container->importadorCsv();
$cUser = (int) $usuarios->create('Carlos Concilia', 'carlos.concilia@correo.com', 'x');
$cCuenta = $cuentas->create($cUser, 'Billetera Carlos', 'efectivo', '200000.00');
$resConc1 = $conciliador->conciliar($cUser, $cCuenta, '175000.00', $rCatIngreso, $rCatGasto, '2026-11-25');
same('conciliar de 200.000 a 175.000 crea un gasto de ajuste de 25.000', ['ok', 'gasto', '25000.00', '175000.00'], [
    $resConc1['codigo'],
    $resConc1['tipo_ajuste'],
    $resConc1['diferencia'],
    (string) $cuentas->findOwned($cUser, $cCuenta)?->saldo,
]);
same('conciliar al mismo saldo actual devuelve sin_diferencia', 'sin_diferencia', $conciliador->conciliar($cUser, $cCuenta, '175000.00', $rCatIngreso, $rCatGasto, '2026-11-25')['codigo']);

$parsedLote = App\Movimientos\ImportadorCsv::parsearTexto("2026-11-25; Bono; +50000\n2026-11-25; Taxi; -20000")['filas'];
$resImp = $importador->importar($cUser, $cCuenta, $rCatGasto, $rCatIngreso, $parsedLote);
same('importar lote CSV de +50.000 y -20.000 deja el saldo en 205.000', ['ok', 2, '205000.00'], [
    $resImp['codigo'],
    $resImp['importados'],
    (string) $cuentas->findOwned($cUser, $cCuenta)?->saldo,
]);

// M8/U4: vista previa (no escribe), duplicados, totales y saldo resultante.
$antesSaldo = (string) $cuentas->findOwned($cUser, $cCuenta)?->saldo;
$lotePrev = App\Movimientos\ImportadorCsv::parsearTexto("2026-11-25;Bono;+50000\n2026-11-25;Cena;-30000\n2026-11-25;Cena;-30000\n2026-11-26;Nueva;-1000")['filas'];
$prev = $importador->vistaPrevia($cUser, $cCuenta, $lotePrev);
same('vista previa: marca como duplicado de la cuenta lo que ya está importado (mismo día, tipo, monto y descripción)', [1 => 'bd'], array_intersect_key($prev['duplicadas'], [1 => 1]));
same('vista previa: la 2.ª «Cena» es nueva en la cuenta y la 3.ª se marca repetida en el archivo', [3 => 'archivo'], array_intersect_key($prev['duplicadas'], [2 => 1, 3 => 1]));
same('vista previa: no hay duplicado si cambia el monto o el día', false, isset($prev['duplicadas'][4]));
same('vista previa: totales y saldo resultante', ['50000.00', '61000.00', $antesSaldo, App\Support\Dinero::sumar([$antesSaldo, '50000.00', '-30000.00', '-30000.00', '-1000.00'])], [$prev['ingresos'], $prev['gastos'], $prev['saldo_actual'], $prev['saldo_final']]);
same('vista previa: NO escribió nada (el saldo no cambió)', $antesSaldo, (string) $cuentas->findOwned($cUser, $cCuenta)?->saldo);
same('vista previa: avisa si la cuenta quedaría en negativo', true, $importador->vistaPrevia($cUser, $cCuenta, App\Movimientos\ImportadorCsv::parsearTexto('2026-11-27;Gasto enorme;-99999999')['filas'])['saldo_negativo']);
same('vista previa de una cuenta ajena: null (no revela nada)', null, $importador->vistaPrevia($uOtroImp = (int) $usuarios->create('Intruso Imp', 'intruso.imp@correo.com', 'x'), $cCuenta, $lotePrev));
same('vista previa sin filas: null', null, $importador->vistaPrevia($cUser, $cCuenta, []));

// R1: GuardarMovimiento (aplica un envío ya validado) y AvisoPresupuesto.
$gm = $container->guardarMovimiento();
$subVacia = App\Support\ResultadoSubida::vacio();
$envNormal = static fn (array $over = []): App\Movimientos\EnvioMovimiento => new App\Movimientos\EnvioMovimiento(false, $over + ['tipo' => 'gasto', 'monto' => '1000.00', 'fecha' => '2026-11-26', 'id_cuenta' => $cCuenta, 'id_categoria' => $rCatGasto, 'descripcion' => 'Prueba R1', 'etiqueta' => null, 'num_cuotas' => 1], [], [], [], [], $subVacia);
$saldoR1 = (string) $cuentas->findOwned($cUser, $cCuenta)?->saldo;
$r1 = $gm->guardar($cUser, null, $envNormal());
same('GuardarMovimiento: un gasto normal se guarda, dice qué se registró y baja el saldo', [true, 'ok', true, App\Support\Dinero::sumar([$saldoR1, '-1000.00'])], [$r1->ok, $r1->tono, str_starts_with($r1->mensaje, 'Se registró el gasto de '), (string) $cuentas->findOwned($cUser, $cCuenta)?->saldo]);
$idR1 = (int) $pdo->query("SELECT id_transaccion FROM transacciones WHERE descripcion = 'Prueba R1' AND id_usuario = $cUser")->fetchColumn();
$r2 = $gm->guardar($cUser, $container->movimientos()->findOwned($cUser, $idR1), $envNormal(['monto' => '2500.00']));
same('GuardarMovimiento: editar un movimiento responde «Se guardaron los cambios» y ajusta el saldo', [true, 'Se guardaron los cambios del movimiento.', App\Support\Dinero::sumar([$saldoR1, '-2500.00'])], [$r2->ok, $r2->mensaje, (string) $cuentas->findOwned($cUser, $cCuenta)?->saldo]);
$r3 = $gm->guardar($cUser, null, $envNormal(['id_categoria' => $rCatIngreso]));
same('GuardarMovimiento: una categoría de ingresos en un gasto da el error de la categoría y no guarda', [false, ['id_categoria']], [$r3->ok, array_keys($r3->errors)]);
$r4 = $gm->guardar($cUser, null, $envNormal(['monto' => '999999999.00']));
same('GuardarMovimiento: sin saldo suficiente da el error del monto', [false, ['monto']], [$r4->ok, array_keys($r4->errors)]);
$r5 = $gm->guardar($cUser, null, $envNormal(['id_cuenta' => 99999999]));
same('GuardarMovimiento: una cuenta que no existe (o ajena) es «no encontrado»', [false, true], [$r5->ok, $r5->noEncontrado]);
$otroUsr = (int) $usuarios->create('Intruso R1', 'intruso.r1@correo.com', 'x');
$saldoAntesIntruso = (string) $cuentas->findOwned($cUser, $cCuenta)?->saldo;
same('GuardarMovimiento: la cuenta de otra persona es «no encontrado» y no se toca su saldo', [true, $saldoAntesIntruso], [$gm->guardar($otroUsr, null, $envNormal())->noEncontrado, (string) $cuentas->findOwned($cUser, $cCuenta)?->saldo]);
$partes = [['id_categoria' => $rCatGasto, 'monto' => '700.00', 'descripcion' => 'a'], ['id_categoria' => $rCatGasto, 'monto' => '300.00', 'descripcion' => 'b']];
$envDiv = new App\Movimientos\EnvioMovimiento(true, ['tipo' => 'gasto', 'monto_total' => '1000.00', 'fecha' => '2026-11-26', 'id_cuenta' => $cCuenta, 'descripcion' => 'Dividido R1', 'etiqueta' => null, 'num_cuotas' => 1], $partes, [], [], [], $subVacia);
$r6 = $gm->guardar($cUser, null, $envDiv);
same('GuardarMovimiento: un gasto dividido crea una fila por parte y lo cuenta en el mensaje', [true, true, 2], [$r6->ok, str_contains($r6->mensaje, '(2 categorías) por '), (int) $pdo->query("SELECT COUNT(*) FROM transacciones WHERE id_usuario = $cUser AND descripcion IN ('a', 'b')")->fetchColumn()]);
same('AvisoPresupuesto: sin presupuesto para esa categoría no hay aviso', null, $container->avisoPresupuesto()->para($cUser, $rCatGasto, '2026-11-26'));
same('AvisoPresupuesto: una fecha inválida tampoco', null, $container->avisoPresupuesto()->para($cUser, $rCatGasto, 'no-es-fecha'));

section('División de movimientos (Splits) y etiquetas transversales #tags (Fase 14)');
$sUser = (int) $usuarios->create('Sofía Splits', 'sofia.splits@correo.com', 'x');
$sCuenta = $cuentas->create($sUser, 'Banco Sofía', 'bancaria', '500000.00');
$resSplit = $gestion2->guardarDividido(
    $sUser,
    [
        'tipo' => 'gasto',
        'monto_total' => '300000.00',
        'fecha' => '2026-11-25',
        'id_cuenta' => $sCuenta,
        'descripcion' => 'Compra grande',
        'etiqueta' => 'vacaciones-2026',
    ],
    [
        ['id_categoria' => $catA, 'monto' => '180000.00', 'descripcion' => 'Alimentación viaje'],
        ['id_categoria' => $catB, 'monto' => '120000.00', 'descripcion' => 'Transporte viaje'],
    ],
);
same('guardarDividido inserta las 2 partes con el mismo grupo_split y descuenta el total de la cuenta', ['ok', 2, true, '200000.00'], [
    $resSplit['codigo'],
    count($resSplit['ids']),
    is_string($resSplit['grupo_split']) && strlen($resSplit['grupo_split']) === 16,
    (string) $cuentas->findOwned($sUser, $sCuenta)?->saldo,
]);
same('si la suma de las partes supera el saldo disponible, no inserta ninguna parte', 'saldo_insuficiente', $gestion2->guardarDividido(
    $sUser,
    [
        'tipo' => 'gasto',
        'monto_total' => '250000.00',
        'fecha' => '2026-11-25',
        'id_cuenta' => $sCuenta,
        'descripcion' => 'Exceso',
        'etiqueta' => 'vacaciones-2026',
    ],
    [
        ['id_categoria' => $catA, 'monto' => '150000.00', 'descripcion' => 'Parte 1'],
        ['id_categoria' => $catB, 'monto' => '100000.00', 'descripcion' => 'Parte 2'],
    ],
)['codigo']);
same('listEtiquetas devuelve las etiquetas usadas por el usuario', ['vacaciones-2026'], $movs->listEtiquetas($sUser));
same('filtrar por etiqueta en MovimientoRepository trae las 2 partidas', 2, $movs->count($sUser, App\Movimientos\Filtros::desde(['etiqueta' => 'vacaciones-2026'])));
$repTags = $reportes->gastosPorEtiqueta($sUser, 11, 2026);
same('gastosPorEtiqueta suma el total gastado bajo #vacaciones-2026 en noviembre', [['etiqueta' => 'vacaciones-2026', 'total' => '300000.00', 'cantidad' => 2]], $repTags);

section('Tarjetas de crédito: compras a cuotas, fecha de corte y fecha de pago (Fase 15)');
$tcUser = (int) $usuarios->create('Valeria Tarjetas', 'valeria.tc@correo.com', 'x');
$tcId = $cuentas->create($tcUser, 'Mastercard Black', 'tarjeta_credito', '0.00', null, 15, 28);
$tcEfec = $cuentas->create($tcUser, 'Efectivo Valeria', 'efectivo', '500000.00');
$tcObj = $cuentas->findOwned($tcUser, $tcId);
same('la tarjeta guarda dia_corte=15 y dia_pago_limite=28', [15, 28], [$tcObj?->diaCorte, $tcObj?->diaPagoLimite]);

$movCuotas = $container->gestionMovimientos()->guardar($tcUser, null, [
    'tipo' => 'gasto',
    'monto' => '600000.00',
    'fecha' => '2026-11-10',
    'id_cuenta' => $tcId,
    'id_categoria' => $catA,
    'descripcion' => 'Televisor a 6 cuotas',
    'num_cuotas' => 6,
]);
same('registrar compra a 6 cuotas con tarjeta de crédito devuelve ok', 'ok', $movCuotas['codigo']);
same('el saldo total de la tarjeta refleja la deuda total (-600.000)', '-600000.00', (string) $cuentas->findOwned($tcUser, $tcId)?->saldo);
$resTc = $container->cuotasTarjeta()->resumenTarjeta($tcId, 15, 28, '2026-11-12');
same('en el ciclo que corta el 2026-11-15 entra la cuota 1 (100.000) y quedan 500.000 diferidos a meses futuros', [
    '100000.00',
    '500000.00',
    1,
    '2026-11-15',
    '2026-11-28',
], [
    $resTc['cuotaProximoCorte'],
    $resTc['deudaDiferidaFutura'],
    $resTc['numCuotasEnCurso'],
    $resTc['proximoCorte']['fecha'] ?? '',
    $resTc['proximoPago']['fecha'] ?? '',
]);

// Si se registra un gasto con num_cuotas=6 en una cuenta de efectivo, se fuerza a 1 cuota y no genera cuotas_tarjeta.
$movEfecCuotas = $container->gestionMovimientos()->guardar($tcUser, null, [
    'tipo' => 'gasto',
    'monto' => '120000.00',
    'fecha' => '2026-11-10',
    'id_cuenta' => $tcEfec,
    'id_categoria' => $catA,
    'descripcion' => 'Compra en efectivo',
    'num_cuotas' => 6,
]);
same('en cuenta de efectivo num_cuotas se fuerza a 1 y no crea filas en cuotas_tarjeta', [1, 0], [
    $movs->findOwned($tcUser, (int) $movEfecCuotas['id'])?->numCuotas,
    (int) $pdo->query('SELECT COUNT(*) FROM cuotas_tarjeta WHERE id_transaccion = ' . (int) $movEfecCuotas['id'])->fetchColumn(),
]);

section('Intereses en compras a cuotas con tarjeta de crédito (Fase 15b)');
$tcIntId = $cuentas->create($tcUser, 'Visa Platinum 2%', 'tarjeta_credito', '0.00', null, 15, 28, 200); // 2,00 % M.V.
same('la tarjeta guarda tasa_mensual_bps = 200 (2 % M.V.)', [200, '2 % M.V.'], [
    $cuentas->findOwned($tcUser, $tcIntId)?->tasaMensualBps,
    $cuentas->findOwned($tcUser, $tcIntId)?->tasaTexto(),
]);
// Compra por defecto (con_intereses = true por omisión) de 300.000 a 3 cuotas:
// Cuota 1 (2026-11-15): 100.000 capital + 6.000 interés = 106.000
// Cuotas 2 y 3 (futuras): 200.000 capital + (4.000 + 2.000) interés = 206.000
$movConInt = $container->gestionMovimientos()->guardar($tcUser, null, [
    'tipo' => 'gasto',
    'monto' => '300000.00',
    'fecha' => '2026-11-10',
    'id_cuenta' => $tcIntId,
    'id_categoria' => $catA,
    'descripcion' => 'Celular a 3 cuotas con interés',
    'num_cuotas' => 3,
]);
same('guardar compra a 3 cuotas usa con_intereses=true por defecto y copia tasa 200 bps', [true, 200], [
    $movs->findOwned($tcUser, (int) $movConInt['id'])?->conIntereses,
    $movs->findOwned($tcUser, (int) $movConInt['id'])?->tasaMensualBps,
]);
$resTcInt = $container->cuotasTarjeta()->resumenTarjeta($tcIntId, 15, 28, '2026-11-12');
same('resumenTarjeta desglosa capital e interés del próximo corte (100.000 + 6.000) y de los meses futuros (200.000 + 6.000)', [
    '106000.00',
    '100000.00',
    '6000.00',
    '206000.00',
    '6000.00',
], [
    $resTcInt['cuotaProximoCorte'],
    $resTcInt['capitalProximoCorte'],
    $resTcInt['interesProximoCorte'],
    $resTcInt['deudaDiferidaFutura'],
    $resTcInt['interesDiferidoFuturo'],
]);

section('SesionRepository: panel de sesiones activas y cierre remoto (Fase 17)');
$sesRepo = $container->sesiones();
$sesUser = (int) $usuarios->create('Sebastián Sesiones', 'sebastian.ses@correo.com', 'x');
$sesOtro = (int) $usuarios->create('Otro Sesiones', 'otro.ses@correo.com', 'x');
$h1 = App\Repository\SesionRepository::hashId('sid-1');
$h2 = App\Repository\SesionRepository::hashId('sid-2');
$hOtro = App\Repository\SesionRepository::hashId('sid-otro');

$sesRepo->registrar($sesUser, $h1, '192.168.1.10', 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0');
$sesRepo->registrar($sesUser, $h2, '10.0.0.5', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) Version/17.5 Safari/604.1');
$sesRepo->registrar($sesOtro, $hOtro, '172.16.0.1', 'curl/8.5.0');

same('listByUser devuelve solo las 2 sesiones activas del usuario', 2, count($sesRepo->listByUser($sesUser)));
same('validarYRenovar reconoce las sesiones propias y rechaza la de otro usuario', [true, true, false], [
    $sesRepo->validarYRenovar($sesUser, $h1),
    $sesRepo->validarYRenovar($sesUser, $h2),
    $sesRepo->validarYRenovar($sesUser, $hOtro),
]);
same('un usuario no puede cerrar la sesión de otro usuario', false, $sesRepo->eliminarDeUsuario($sesUser, $hOtro));
same('eliminarDeUsuario cierra la sesión remota h2', [true, false], [
    $sesRepo->eliminarDeUsuario($sesUser, $h2),
    $sesRepo->validarYRenovar($sesUser, $h2),
]);
$sesRepo->registrar($sesUser, $h2, '10.0.0.5', 'Safari');
same('eliminarOtrasDeUsuario cierra todas menos la sesión actual h1', [1, true, false], [
    $sesRepo->eliminarOtrasDeUsuario($sesUser, $h1),
    $sesRepo->validarYRenovar($sesUser, $h1),
    $sesRepo->validarYRenovar($sesUser, $h2),
]);

// M5: tope de sesiones, vida máxima y escritura de ultimo_uso limitada.
$sesRepo->eliminarOtrasDeUsuario($sesUser, $h1);
$pdo->exec('UPDATE sesiones_usuario SET ultimo_uso = NOW() - INTERVAL 5 MINUTE WHERE id_sesion = ' . $pdo->quote($h1));
$sesRepo->validarYRenovar($sesUser, $h1);
same('M5: una sesión sin tocar más de 1 min refresca ultimo_uso', true, (int) $pdo->query('SELECT ultimo_uso > NOW() - INTERVAL 30 SECOND FROM sesiones_usuario WHERE id_sesion = ' . $pdo->quote($h1))->fetchColumn() === 1);
$pdo->exec('UPDATE sesiones_usuario SET ultimo_uso = NOW() - INTERVAL 20 SECOND WHERE id_sesion = ' . $pdo->quote($h1));
$sesRepo->validarYRenovar($sesUser, $h1);
same('M5: dentro del minuto NO se escribe de nuevo (sin escritura por petición)', true, (int) $pdo->query('SELECT ultimo_uso < NOW() - INTERVAL 10 SECOND FROM sesiones_usuario WHERE id_sesion = ' . $pdo->quote($h1))->fetchColumn() === 1);
$pdo->exec('UPDATE sesiones_usuario SET creado_en = NOW() - INTERVAL 13 HOUR WHERE id_sesion = ' . $pdo->quote($h1));
same('M5: pasada la vida máxima (12 h) la sesión deja de valer aunque esté activa', false, $sesRepo->validarYRenovar($sesUser, $h1));
same('M5: y se borra de la tabla', 0, (int) $pdo->query('SELECT COUNT(*) FROM sesiones_usuario WHERE id_sesion = ' . $pdo->quote($h1))->fetchColumn());
$primera = App\Repository\SesionRepository::hashId('tope-0');
for ($i = 0; $i < App\Repository\SesionRepository::MAX_SESIONES + 4; $i++) {
    $hh = App\Repository\SesionRepository::hashId("tope-$i");
    $sesRepo->registrar($sesUser, $hh, '10.0.0.' . $i, 'Navegador ' . $i);
    $pdo->exec('UPDATE sesiones_usuario SET ultimo_uso = NOW() - INTERVAL ' . (100 - $i) . ' SECOND WHERE id_sesion = ' . $pdo->quote($hh));
}
$ultima = App\Repository\SesionRepository::hashId('tope-' . (App\Repository\SesionRepository::MAX_SESIONES + 3));
same('M5: nunca hay más de 10 sesiones por usuario', App\Repository\SesionRepository::MAX_SESIONES, count($sesRepo->listByUser($sesUser)));
same('M5: la sesión recién abierta sigue válida y la más antigua se cerró', [true, false], [$sesRepo->validarYRenovar($sesUser, $ultima), $sesRepo->validarYRenovar($sesUser, $primera)]);
same('M5: el tope de un usuario no toca las sesiones de otro', true, $sesRepo->validarYRenovar($sesOtro, $hOtro));

section('Autenticación en dos pasos: activar, comprobar, recuperar, desactivar (M7)');
$dp = new App\Auth\DosPasos($pdo, new App\Repository\DosPasosRepository($pdo), new App\Security\CifradoSecreto(str_repeat('t', 40)));
$dpUser = (int) $usuarios->create('Dora DosPasos', 'dora.dp@correo.com', 'x');
$dpAhora = 1700000000;
$dpSecreto = App\Auth\Totp::generarSecreto();
$cod = static fn (int $t): string => App\Auth\Totp::codigo($dpSecreto, App\Auth\Totp::paso($t));

same('al principio no tiene 2FA', [false, false], [$dp->estaActivo($dpUser), $usuarios->findById($dpUser)->dosPasos]);
same('comprobar sin 2FA activo siempre es null', null, $dp->comprobar($dpUser, $cod($dpAhora), $dpAhora));
same('activar con un código incorrecto no activa nada', [null, false], [$dp->activar($dpUser, $dpSecreto, '000000' === $cod($dpAhora) ? '111111' : '000000', $dpAhora), $dp->estaActivo($dpUser)]);
$dpCodigos = $dp->activar($dpUser, $dpSecreto, $cod($dpAhora), $dpAhora);
same('activar con el código correcto devuelve 10 códigos de recuperación distintos', 10, is_array($dpCodigos) ? count(array_unique($dpCodigos)) : -1);
same('queda activo y el Usuario lo refleja', [true, true, 10], [$dp->estaActivo($dpUser), $usuarios->findById($dpUser)->dosPasos, $dp->codigosDisponibles($dpUser)]);
same('el secreto se guardó cifrado (no es el base32)', true, !str_contains((string) $pdo->query("SELECT totp_secreto FROM usuarios WHERE id_usuario = $dpUser")->fetchColumn(), $dpSecreto));
same('los códigos se guardaron como hash (ninguno en claro)', 0, (int) $pdo->query("SELECT COUNT(*) FROM codigos_recuperacion WHERE id_usuario = $dpUser AND codigo_hash IN ('" . implode("','", $dpCodigos) . "')")->fetchColumn());
same('el código con el que se activó no se puede reutilizar para entrar', null, $dp->comprobar($dpUser, $cod($dpAhora), $dpAhora));
same('el del paso siguiente sí (dentro de la tolerancia)', App\Auth\DosPasos::OK_TOTP, $dp->comprobar($dpUser, $cod($dpAhora + 30), $dpAhora));
same('…pero tampoco se puede usar dos veces', null, $dp->comprobar($dpUser, $cod($dpAhora + 30), $dpAhora));
same('un código de un paso ANTERIOR al último aceptado se rechaza', null, $dp->comprobar($dpUser, $cod($dpAhora - 30), $dpAhora));
same('un código inventado se rechaza', null, $dp->comprobar($dpUser, '123456' === $cod($dpAhora + 60) ? '654321' : '123456', $dpAhora + 60));
same('código de recuperación: sirve (con minúsculas y sin guiones) la primera vez', App\Auth\DosPasos::OK_RECUPERACION, $dp->comprobar($dpUser, strtolower(str_replace('-', '', $dpCodigos[0])), $dpAhora));
same('…y no la segunda', null, $dp->comprobar($dpUser, $dpCodigos[0], $dpAhora));
same('quedan 9 códigos', 9, $dp->codigosDisponibles($dpUser));
$dpNuevos = $dp->regenerarCodigos($dpUser);
same('regenerar da 10 nuevos y los anteriores dejan de valer', [10, null, 10], [count($dpNuevos), $dp->comprobar($dpUser, $dpCodigos[1], $dpAhora), $dp->codigosDisponibles($dpUser)]);
same('un usuario no puede usar los códigos de otro', null, (function () use ($dp, $dpNuevos, $usuarios): ?string {
    $otro = (int) $usuarios->create('Otro DP', 'otro.dp@correo.com', 'x');
    return $dp->comprobar($otro, $dpNuevos[0], 1700000000);
})());
same('con otra APP_KEY el secreto guardado no se puede leer: comprobar falla de forma controlada', null, (new App\Auth\DosPasos($pdo, new App\Repository\DosPasosRepository($pdo), new App\Security\CifradoSecreto(str_repeat('x', 40))))->comprobar($dpUser, $cod($dpAhora + 60), $dpAhora + 60));
$dp->desactivar($dpUser);
same('desactivar quita el secreto, el estado y los códigos', [false, false, 0, 0], [
    $dp->estaActivo($dpUser), $usuarios->findById($dpUser)->dosPasos, $dp->codigosDisponibles($dpUser),
    (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE id_usuario = $dpUser AND totp_secreto IS NOT NULL")->fetchColumn(),
]);
$dp->activar($dpUser, $dpSecreto, $cod($dpAhora + 600), $dpAhora + 600);
same('eliminar la cuenta borra también sus códigos de recuperación', [true, 0], [$container->eliminarCuenta()->eliminar($dpUser) === 'ok', (int) $pdo->query("SELECT COUNT(*) FROM codigos_recuperacion WHERE id_usuario = $dpUser")->fetchColumn()]);

$limDp = new LimitadorIntentos($container->intentos());
for ($i = 0; $i < LimitadorIntentos::MAX_POR_CORREO_DOS_PASOS - 1; $i++) {
    $limDp->registrar('login', '2fa:dp@correo.com', '10.8.0.' . (1 + $i));
}
same('9 fallos de código desde IP distintas aún no bloquean', false, $limDp->bloqueado('login', '2fa:dp@correo.com', '10.8.9.9', LimitadorIntentos::MAX_POR_CORREO_DOS_PASOS));
$limDp->registrar('login', '2fa:dp@correo.com', '10.8.0.99');
same('el 10.º bloquea el segundo factor de esa cuenta, sea cual sea la IP', true, $limDp->bloqueado('login', '2fa:dp@correo.com', '10.8.9.9', LimitadorIntentos::MAX_POR_CORREO_DOS_PASOS));
same('y no afecta a la contraseña de la misma cuenta (otra clave)', false, $limDp->bloqueado('login', 'dp@correo.com', '10.8.9.9'));

section('Cola de correo: encolar, reclamar, reintentar y no dejar tokens guardados (M4)');
$cola = new App\Repository\ColaCorreoRepository($pdo);
$colaMailer = new App\Mail\ColaMailer($cola);
$pdo->exec('DELETE FROM cola_correo');
$colaMailer->send('ana@correo.com', 'Restablece tu contraseña', "Hola\nhttps://x/restablecer.php?token=SECRETO\n");
same('enviar por la cola solo inserta una fila pendiente (no habla con el SMTP)', [1, 'pendiente', 0], [
    (int) $pdo->query('SELECT COUNT(*) FROM cola_correo')->fetchColumn(),
    (string) $pdo->query('SELECT estado FROM cola_correo')->fetchColumn(),
    (int) $pdo->query('SELECT intentos FROM cola_correo')->fetchColumn(),
]);
same('un destinatario inválido o con saltos de línea se rechaza al encolar (no se guarda)', [2, 2, 1], (static function () use ($colaMailer, $pdo): array {
    $n = 0;
    foreach (['no-es-correo', "a@b.co\nBcc: x@y.co", 'a b@c.co'] as $malo) {
        try {
            $colaMailer->send($malo, 'Asunto', 'x');
        } catch (App\Mail\MailException) {
            $n++;
        }
    }
    $asunto = 0;
    try {
        $colaMailer->send('ok@correo.com', "Asunto\nBcc: x@y.co", 'x');
    } catch (App\Mail\MailException) {
        $asunto++;
    }
    return [$n === 3 ? 2 : $n, $asunto === 1 ? 2 : $asunto, (int) $pdo->query('SELECT COUNT(*) FROM cola_correo')->fetchColumn()];
})());
$lote = $cola->reclamar(10);
same('reclamar devuelve el correo, cuenta el intento y lo oculta a otro proceso', [1, 1, 'ana@correo.com', 1, []], [count($lote), $lote[0]['intentos'], $lote[0]['destinatario'], (int) str_contains($lote[0]['cuerpo'], 'SECRETO'), $cola->reclamar(10)]);
$id = $lote[0]['id_correo'];
$cola->marcarFallo($id, 1, 'SMTP caído');
same('un fallo lo deja pendiente con espera de 1 min y registra el error', [true, 'SMTP caído', 'pendiente'], [
    (int) $pdo->query("SELECT proximo_intento > NOW() + INTERVAL 30 SECOND AND proximo_intento < NOW() + INTERVAL 90 SECOND FROM cola_correo WHERE id_correo = $id")->fetchColumn() === 1,
    (string) $pdo->query("SELECT ultimo_error FROM cola_correo WHERE id_correo = $id")->fetchColumn(),
    (string) $pdo->query("SELECT estado FROM cola_correo WHERE id_correo = $id")->fetchColumn(),
]);
same('mientras espera, no se reclama', [], $cola->reclamar(10));
same('la espera crece: 1, 5, 15 y 60 min (y 60 después)', [60, 300, 900, 3600, 3600], array_map([App\Repository\ColaCorreoRepository::class, 'esperaTrasFallo'], [1, 2, 3, 4, 9]));
$pdo->exec("UPDATE cola_correo SET proximo_intento = NOW() - INTERVAL 1 SECOND WHERE id_correo = $id");
$lote = $cola->reclamar(10);
same('vencida la espera se vuelve a reclamar (2.º intento)', [1, 2], [count($lote), $lote[0]['intentos'] ?? 0]);
$cola->marcarEnviado($id);
same('al enviarse queda «enviado» y SE BORRA el cuerpo (puede llevar enlaces con token)', ['enviado', '', 0], [
    (string) $pdo->query("SELECT estado FROM cola_correo WHERE id_correo = $id")->fetchColumn(),
    (string) $pdo->query("SELECT cuerpo FROM cola_correo WHERE id_correo = $id")->fetchColumn(),
    count($cola->reclamar(10)),
]);
$colaMailer->send('beto@correo.com', 'Asunto', 'cuerpo con TOKEN');
for ($i = 1; $i <= App\Repository\ColaCorreoRepository::MAX_INTENTOS; $i++) {
    $pdo->exec("UPDATE cola_correo SET proximo_intento = NOW() - INTERVAL 1 SECOND WHERE estado = 'pendiente'");
    $l = $cola->reclamar(10);
    if ($l !== []) {
        $cola->marcarFallo($l[0]['id_correo'], $l[0]['intentos'], 'sigue caído');
    }
}
same('tras 5 intentos fallidos pasa a «fallido», sin cuerpo y sin más reintentos', ['fallido', '', []], [
    (string) $pdo->query("SELECT estado FROM cola_correo WHERE destinatario = 'beto@correo.com'")->fetchColumn(),
    (string) $pdo->query("SELECT cuerpo FROM cola_correo WHERE destinatario = 'beto@correo.com'")->fetchColumn(),
    $cola->reclamar(10),
]);
$colaMailer->send('c1@correo.com', 'A', 'x');
$colaMailer->send('c2@correo.com', 'A', 'x');
$colaMailer->send('c3@correo.com', 'A', 'x');
same('reclamar respeta el máximo pedido y el orden', ['c1@correo.com', 'c2@correo.com'], array_column($cola->reclamar(2), 'destinatario'));
same('el resumen cuenta por estado', [1, 1, 3], (static function () use ($cola): array {
    $r = $cola->resumen();
    return [$r['enviado'], $r['fallido'], $r['pendiente']];
})());
$pdo->exec("UPDATE cola_correo SET procesado_en = NOW() - INTERVAL 8 DAY WHERE estado = 'enviado'");
$pdo->exec("UPDATE cola_correo SET procesado_en = NOW() - INTERVAL 8 DAY WHERE estado = 'fallido'");
same('purgar borra los enviados de más de 7 días pero no los fallidos de menos de 30', [1, 0, 1], [$cola->purgar(), (int) $pdo->query("SELECT COUNT(*) FROM cola_correo WHERE estado = 'enviado'")->fetchColumn(), (int) $pdo->query("SELECT COUNT(*) FROM cola_correo WHERE estado = 'fallido'")->fetchColumn()]);
$pdo->exec('DELETE FROM cola_correo');

section('Esquema: una sola lista de tablas, y ninguna tabla con datos de usuario sin tratar (R2)');
use App\Database\Esquema;

$tablasBd = array_map('strval', $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN));
$ordenar = static function (array $a): array {
    sort($a);
    return $a;
};
same('Esquema::TODAS es exactamente el conjunto de tablas de 01-schema.sql', $ordenar($tablasBd), $ordenar(Esquema::TODAS));
preg_match('/^APP_TABLES="([^"]+)"/m', (string) file_get_contents(dirname(__DIR__, 2) . '/database/03-users.sh'), $mApp);
same('APP_TABLES de database/03-users.sh (permisos de la aplicación) coincide con Esquema::TODAS', $ordenar(Esquema::TODAS), $ordenar(explode(' ', $mApp[1] ?? '')));
$conColumna = static function (string $col) use ($pdo): array {
    $q = $pdo->prepare("SELECT DISTINCT c.table_name FROM information_schema.columns c JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE' WHERE c.table_schema = DATABASE() AND c.column_name = :c");
    $q->execute(['c' => $col]);
    return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
};
$tratadas = array_merge(Esquema::TABLAS_FINANCIERAS, Esquema::EXPLICITAS_EXTRA, Esquema::POR_CASCADA);
$sinTratar = array_values(array_diff(array_merge($conColumna('id_usuario'), $conColumna('id_espacio')), $tratadas));
same('toda tabla con id_usuario o id_espacio está en TABLAS_FINANCIERAS, EXPLICITAS_EXTRA o POR_CASCADA (si no, quedaría huérfana al borrar)', [], $sinTratar);
same('las TABLAS_FINANCIERAS tienen las dos columnas (id_usuario e id_espacio)', [], array_values(array_diff(Esquema::TABLAS_FINANCIERAS, array_intersect($conColumna('id_usuario'), $conColumna('id_espacio')))));
$cascada = $pdo->query("SELECT k.table_name FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema = k.table_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name WHERE k.table_schema = DATABASE() AND k.referenced_table_name IN ('usuarios', 'espacios') AND r.delete_rule = 'CASCADE'")->fetchAll(PDO::FETCH_COLUMN);
same('las tablas POR_CASCADA con id_usuario/id_espacio tienen de verdad FK ON DELETE CASCADE', [], array_values(array_diff(array_intersect(Esquema::POR_CASCADA, array_merge($conColumna('id_usuario'), $conColumna('id_espacio'))), $cascada)));
same('ninguna tabla aparece en dos listas de tratamiento', [], array_values(array_diff_key(array_count_values($tratadas), array_filter(array_count_values($tratadas), static fn (int $n): bool => $n === 1))));

section('Índices de los espacios compartidos (R9)');
$indices = static function (string $tabla) use ($pdo): array {
    $q = $pdo->prepare('SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t GROUP BY index_name');
    $q->execute(['t' => $tabla]);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
};
same('transacciones tiene el índice (id_espacio, fecha)', 'id_espacio,fecha', $indices('transacciones')['idx_trans_espacio_fecha'] ?? null);
same('transferencias tiene el índice (id_espacio, fecha)', 'id_espacio,fecha', $indices('transferencias')['idx_transf_espacio_fecha'] ?? null);
same('movimientos_recurrentes tiene el índice (id_espacio, activo, proxima_fecha)', 'id_espacio,activo,proxima_fecha', $indices('movimientos_recurrentes')['idx_rec_espacio_proxima'] ?? null);
$explain = static fn (string $sql): string => implode(' | ', array_map(static fn (array $f): string => (string) ($f['key'] ?? '') . ':' . (string) ($f['Extra'] ?? ''), $pdo->query('EXPLAIN ' . $sql)->fetchAll()));
$planTrans = $explain('SELECT * FROM transacciones t WHERE t.id_espacio = 1 ORDER BY t.fecha DESC, t.id_transaccion DESC LIMIT 20');
same('el listado de un espacio usa el índice y no ordena en memoria (sin «Using filesort»)', [true, false], [str_contains($planTrans, 'idx_trans_espacio_fecha'), str_contains($planTrans, 'filesort')]);

section('Multimoneda por cuenta, transferencias multimoneda y conversión en reportes/presupuestos (Fase 18)');
$mUser = (int) $usuarios->create('Mateo Multimoneda', 'mateo.multi@correo.com', 'x');
$tasasRepo = $container->tasasCambio();
$cCop = $cuentas->create($mUser, 'Banco COP', 'bancaria', '1000000.00', null, null, null, 0, null);
$cUsd = $cuentas->create($mUser, 'Ahorros USD', 'ahorro', '500.00', null, null, null, 0, 'USD');
App\Support\Dinero::usarMoneda('COP');

same('sin tasa guardada, transferir entre monedas sin monto_destino ni tasa devuelve falta_conversion', 'falta_conversion', $gt->guardar($mUser, null, [
    'id_cuenta_origen' => $cUsd,
    'id_cuenta_destino' => $cCop,
    'monto' => '100.00',
    'fecha' => '2026-11-26',
    'descripcion' => 'Sin tasa',
])['codigo']);

$tasasRepo->upsert($mUser, 'USD', 'COP', 4_200_000_000);
$tasasRepo->upsert($mUser, 'USD', 'COP', 4_000_000_000); // actualiza el mismo par
$mapaMateo = $tasasRepo->listMap($mUser);
same('TasaCambioRepository hace upsert del par USD:COP a 4.000', ['USD:COP' => 4_000_000_000], $mapaMateo);

$consMateo = App\Support\ConversorMoneda::consolidarCuentas($cuentas->listByUser($mUser), 'COP', $mapaMateo);
same('patrimonio consolidado de Mateo: 1.000.000 COP + 500 USD * 4.000 = 3.000.000 COP', ['3000000.00', []], [$consMateo['total'], $consMateo['sinTasa']]);

// 1) Transferencia multimoneda con monto_destino explícito (100 USD -> 415.000 COP)
$trMulti1 = $gt->guardar($mUser, null, [
    'id_cuenta_origen' => $cUsd,
    'id_cuenta_destino' => $cCop,
    'monto' => '100.00',
    'monto_destino' => '415000.00',
    'fecha' => '2026-11-26',
    'descripcion' => 'Cambio USD a COP por monto',
]);
same('transferencia multimoneda con monto_destino explícito', ['ok', '415000.00', '400.00', '1415000.00'], [
    $trMulti1['codigo'],
    $trMulti1['monto_destino'],
    (string) $cuentas->findOwned($mUser, $cUsd)?->saldo,
    (string) $cuentas->findOwned($mUser, $cCop)?->saldo,
]);

// 2) Transferencia multimoneda con tasa aplicada explícita (50 USD * 4.100 = 205.000 COP)
$trMulti2 = $gt->guardar($mUser, null, [
    'id_cuenta_origen' => $cUsd,
    'id_cuenta_destino' => $cCop,
    'monto' => '50.00',
    'tasa_micros' => 4_100_000_000,
    'fecha' => '2026-11-26',
    'descripcion' => 'Cambio USD a COP por tasa',
]);
same('transferencia multimoneda con tasa aplicada explícita', ['ok', '205000.00', '350.00', '1620000.00'], [
    $trMulti2['codigo'],
    $trMulti2['monto_destino'],
    (string) $cuentas->findOwned($mUser, $cUsd)?->saldo,
    (string) $cuentas->findOwned($mUser, $cCop)?->saldo,
]);

// 3) Transferencia multimoneda usando tasa de referencia guardada (50 USD * 4.000 = 200.000 COP)
$trMulti3 = $gt->guardar($mUser, null, [
    'id_cuenta_origen' => $cUsd,
    'id_cuenta_destino' => $cCop,
    'monto' => '50.00',
    'fecha' => '2026-11-26',
    'descripcion' => 'Cambio USD a COP con tasa guardada',
]);
same('transferencia multimoneda con tasa de referencia guardada', ['ok', '200000.00', '300.00', '1820000.00'], [
    $trMulti3['codigo'],
    $trMulti3['monto_destino'],
    (string) $cuentas->findOwned($mUser, $cUsd)?->saldo,
    (string) $cuentas->findOwned($mUser, $cCop)?->saldo,
]);

// 4) Editar transferencia multimoneda #1 (de 415.000 a 400.000 COP) y luego eliminarla revierte exactamente el destino
$trMulti1Id = (int) $trMulti1['id'];
same('editar monto_destino de 415.000 a 400.000 ajusta Banco COP en -15.000 (a 1.805.000)', ['ok', '1805000.00'], [
    $gt->guardar($mUser, $trMulti1Id, [
        'id_cuenta_origen' => $cUsd,
        'id_cuenta_destino' => $cCop,
        'monto' => '100.00',
        'monto_destino' => '400000.00',
        'fecha' => '2026-11-26',
        'descripcion' => 'Editada',
    ])['codigo'],
    (string) $cuentas->findOwned($mUser, $cCop)?->saldo,
]);
same('eliminar transferencia multimoneda revierte 100 USD en origen y 400.000 COP en destino', ['ok', '400.00', '1405000.00'], [
    $gt->eliminar($mUser, $trMulti1Id)['codigo'],
    (string) $cuentas->findOwned($mUser, $cUsd)?->saldo,
    (string) $cuentas->findOwned($mUser, $cCop)?->saldo,
]);

// 5) Conversión al vuelo en ReporteRepository y PresupuestoRepository
$ins($mUser, $cCop, $catA, 'gasto', '200000.00', '2026-11-15', 'Mercado COP');
$ins($mUser, $cUsd, $catA, 'gasto', '100.00', '2026-11-16', 'Mercado USD');
$presupuestos->create($mUser, $catA, 11, 2026, '500000.00');

same('ReporteRepository::totalesMes convierte 100 USD a 400.000 COP y suma 600.000 COP', '600000.00', $reportes->totalesMes($mUser, 11, 2026, null, 'COP', $mapaMateo)['gastos']);
same('ReporteRepository::gastosPorCategoria convierte al vuelo la categoría a 600.000 COP', '600000.00', $reportes->gastosPorCategoria($mUser, 11, 2026, null, 'COP', $mapaMateo)[0]['total']);
same('ReporteRepository::evolucion convierte el mes a 600.000 COP', '600000.00', $reportes->evolucion($mUser, 11, 2026, 1, null, 'COP', $mapaMateo)[0]['gastos']);
$presMateo = $presupuestos->findForCategory($mUser, $catA, 11, 2026, null, 'COP', $mapaMateo);
same('PresupuestoRepository convierte 100 USD + 200.000 COP = 600.000 COP (120 %, excedido sobre límite 500.000)', ['600000.00', 120, 'excedido'], [
    $presMateo?->gastado,
    $presMateo?->progreso()['percent'],
    $presMateo?->progreso()['estado'],
]);
same('migración 010-multimoneda-cuentas.sql es idempotente', true, (static function () use ($pdo): bool {
    foreach (SqlScript::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/010-multimoneda-cuentas.sql')) as $s) {
        $pdo->exec($s);
    }
    return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tasas_cambio'")->fetchColumn() === 1;
})());

section('Adjuntos de comprobantes y limpieza en disco al borrar movimiento o usuario (Fase 19)');
$aUser = (int) $usuarios->create('Andrea Adjuntos', 'andrea.adj@correo.com', 'x');
$aOtro = (int) $usuarios->create('Otro Adjuntos', 'otro.adj@correo.com', 'x');
$aCuenta = $cuentas->create($aUser, 'Banco Andrea', 'bancaria', '500000.00');
$aMov1 = $gestion2->guardar($aUser, null, [
    'tipo' => 'gasto',
    'monto' => '50000.00',
    'fecha' => '2026-11-26',
    'id_cuenta' => $aCuenta,
    'id_categoria' => $catA,
    'descripcion' => 'Factura con recibo',
]);
$aMov1Id = (int) $aMov1['id'];
$pdfBytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$adj1 = App\Support\AlmacenAdjuntos::guardarDesdeContenido($pdfBytes, 'application/pdf', 'factura_luz.pdf');
$movs->actualizarAdjunto($aUser, $aMov1Id, $adj1['ruta'], $adj1['nombre'], $adj1['mime']);
$movLeido = $movs->findOwned($aUser, $aMov1Id);
same('el movimiento conserva los metadatos del comprobante y otro usuario no lo ve', [true, 'factura_luz.pdf', 'application/pdf', null], [
    $movLeido?->tieneAdjunto(),
    $movLeido?->adjuntoNombre,
    $movLeido?->adjuntoMime,
    $movs->findOwned($aOtro, $aMov1Id),
]);
$absAdj1 = App\Support\AlmacenAdjuntos::rutaAbsoluta($adj1['ruta']);
same('al eliminar el movimiento, también se borra el archivo físico del comprobante', [true, true, false], [
    $absAdj1 !== null && is_file($absAdj1),
    $gestion2->eliminar($aUser, $aMov1Id),
    $absAdj1 !== null && is_file($absAdj1),
]);

// Al eliminar la cuenta de usuario (RNF-20), los comprobantes en disco también se borran.
$aMov2 = $gestion2->guardar($aUser, null, [
    'tipo' => 'gasto',
    'monto' => '30000.00',
    'fecha' => '2026-11-26',
    'id_cuenta' => $aCuenta,
    'id_categoria' => $catA,
    'descripcion' => 'Recibo antes de borrar cuenta',
]);
$adj2 = App\Support\AlmacenAdjuntos::guardarDesdeContenido($pdfBytes, 'application/pdf', 'recibo_final.pdf');
$movs->actualizarAdjunto($aUser, (int) $aMov2['id'], $adj2['ruta'], $adj2['nombre'], $adj2['mime']);
$absAdj2 = App\Support\AlmacenAdjuntos::rutaAbsoluta($adj2['ruta']);
same('al eliminar el usuario (EliminarCuenta), se eliminan también sus comprobantes en disco', [true, 'ok', false], [
    $absAdj2 !== null && is_file($absAdj2),
    $borrar->eliminar($aUser),
    $absAdj2 !== null && is_file($absAdj2),
]);
// Al eliminar un ESPACIO compartido (M3 de docs/AUDITORIA.md), los comprobantes de sus movimientos también salen del disco.
$esProp = (int) $usuarios->create('Propietaria Espacio Adj', 'prop.espadj@correo.com', 'x');
$usuarios->marcarCorreoVerificado($esProp);
$esEdit = (int) $usuarios->create('Editor Espacio Adj', 'edit.espadj@correo.com', 'x');
$usuarios->marcarCorreoVerificado($esEdit);
$eaA = $ge->crear($esProp, 'Hogar con comprobantes', 'COP');
$eaB = $ge->crear($esProp, 'Otro hogar con comprobantes', 'COP');
$espRepo->addMember($eaA, $esEdit, 'editor');
$eaCuentaA = $cuentas->create($esProp, 'Caja hogar A', 'bancaria', '500000.00', $eaA);
$eaCuentaB = $cuentas->create($esProp, 'Caja hogar B', 'bancaria', '500000.00', $eaB);
$eaMov = static function (int $user, int $cuenta, int $espacio, string $desc) use ($gestion2, $movs, $catA, $pdfBytes): array {
    $m = $gestion2->guardar($user, null, ['tipo' => 'gasto', 'monto' => '1000.00', 'fecha' => '2026-11-26', 'id_cuenta' => $cuenta, 'id_categoria' => $catA, 'descripcion' => $desc], $espacio);
    $adj = App\Support\AlmacenAdjuntos::guardarDesdeContenido($pdfBytes, 'application/pdf', $desc . '.pdf');
    $movs->actualizarAdjunto($user, (int) $m['id'], $adj['ruta'], $adj['nombre'], $adj['mime'], $espacio);
    return [$adj['ruta'], App\Support\AlmacenAdjuntos::rutaAbsoluta($adj['ruta'])];
};
[, $eaAbs1] = $eaMov($esProp, $eaCuentaA, $eaA, 'comprobante propietaria');
[, $eaAbs2] = $eaMov($esEdit, $eaCuentaA, $eaA, 'comprobante editor');
[, $eaAbsB] = $eaMov($esProp, $eaCuentaB, $eaB, 'comprobante otro espacio');
same('los 3 comprobantes existen en disco antes de borrar', [true, true, true], [is_file((string) $eaAbs1), is_file((string) $eaAbs2), is_file((string) $eaAbsB)]);
same('un editor no puede eliminar el espacio y sus comprobantes siguen en disco', ['sin_permiso', true, true], [$ge->eliminarEspacio($esEdit, $eaA), is_file((string) $eaAbs1), is_file((string) $eaAbs2)]);
same('al eliminar el espacio se borran de disco los comprobantes de TODOS sus miembros', ['ok', false, false], [$ge->eliminarEspacio($esProp, $eaA), is_file((string) $eaAbs1), is_file((string) $eaAbs2)]);
same('y los de otro espacio no se tocan', true, is_file((string) $eaAbsB));
same('un espacio que no existe responde no_existe y no borra nada', ['no_existe', true], [$ge->eliminarEspacio($esProp, $eaA), is_file((string) $eaAbsB)]);
same('sin huérfanos tras borrar el espacio (los archivos del otro espacio tienen fila)', [], array_values(array_filter(
    App\Support\HuerfanosAdjuntos::huerfanos($movs->todasLasRutasAdjuntos(), 0),
    static fn (string $r): bool => str_ends_with((string) $eaAbs1, $r) || str_ends_with((string) $eaAbs2, $r),
)));
same('el detector sí marca como huérfano un archivo sin fila', true, (static function () use ($pdfBytes, $movs): bool {
    $solo = App\Support\AlmacenAdjuntos::guardarDesdeContenido($pdfBytes, 'application/pdf', 'suelto.pdf');
    $marcado = in_array($solo['ruta'], App\Support\HuerfanosAdjuntos::huerfanos($movs->todasLasRutasAdjuntos(), 0), true);
    App\Support\AlmacenAdjuntos::eliminar($solo['ruta']);
    return $marcado;
})());
$ge->eliminarEspacio($esProp, $eaB);
same('al limpiar, el comprobante del segundo espacio también se borra', false, is_file((string) $eaAbsB));
same('migración 011-adjuntos-comprobantes.sql es idempotente', true, (static function () use ($pdo): bool {
    foreach (SqlScript::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/011-adjuntos-comprobantes.sql')) as $s) {
        $pdo->exec($s);
    }
    return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'adjunto_ruta'")->fetchColumn() === 1;
})());

// ---- Fase 20: notificaciones por correo -------------------------------------------------------------------------------

section('NotificadorPresupuesto y ResumenMensual (Fase 20)');
$correos->enviados = [];
$correos->fallar = false;
$notif = $container->notificadorPresupuesto();
$nUser = (int) $usuarios->create('Nora Notif', 'nora.notif@correo.com', 'x');
$usuarios->marcarCorreoVerificado($nUser);
$nOff = (int) $usuarios->create('Nico Sin Alertas', 'nico.off@correo.com', 'x');
$usuarios->marcarCorreoVerificado($nOff);
$usuarios->updateNotificaciones($nUser, true, true);
same('las preferencias de notificación se guardan y se leen', [true, true, false, false], [
    $usuarios->findById($nUser)?->notifPresupuestos,
    $usuarios->findById($nUser)?->notifResumenMensual,
    $usuarios->findById($nOff)?->notifPresupuestos,
    $usuarios->findById($nOff)?->notifResumenMensual,
]);
$nCuenta = $cuentas->create($nUser, 'Banco Nora', 'bancaria', '1000000.00');
$nCuentaOff = $cuentas->create($nOff, 'Banco Nico', 'bancaria', '1000000.00');
$presupuestos->create($nUser, $catA, 11, 2026, '100000.00');
$presupuestos->create($nOff, $catA, 11, 2026, '100000.00');
$mov($nUser, $nCuenta, $catA, 'gasto', '70000.00', '2026-11-05');
same('con el 70 % no se envía ninguna alerta', 0, $notif->notificarGasto($nUser, $catA, '2026-11-05'));
$mov($nUser, $nCuenta, $catA, 'gasto', '15000.00', '2026-11-06');
same('al llegar al 85 % se envía la alerta del 80 %', [1, 'nora.notif@correo.com', true], [
    $notif->notificarGasto($nUser, $catA, '2026-11-06'),
    $correos->enviados[0]['to'] ?? null,
    str_contains($correos->enviados[0]['subject'] ?? '', '80 %'),
]);
same('repetir el mismo umbral no vuelve a enviar (deduplicado)', [0, 1], [$notif->notificarGasto($nUser, $catA, '2026-11-07'), count($correos->enviados)]);
$mov($nUser, $nCuenta, $catA, 'gasto', '20000.00', '2026-11-08');
same('al superar el 100 % se envía una segunda alerta distinta', [1, true], [
    $notif->notificarGasto($nUser, $catA, '2026-11-08'),
    str_contains($correos->enviados[1]['subject'] ?? '', '100 %'),
]);
same('y tampoco se repite el 100 %', [0, 2], [$notif->notificarGasto($nUser, $catA, '2026-11-09'), count($correos->enviados)]);
$mov($nOff, $nCuentaOff, $catA, 'gasto', '150000.00', '2026-11-05');
same('quien no activó las alertas no recibe correo', [0, 2], [$notif->notificarGasto($nOff, $catA, '2026-11-05'), count($correos->enviados)]);
same('una categoría sin presupuesto no envía nada', 0, $notif->notificarGasto($nUser, $catB, '2026-11-05'));

// Si el SMTP falla, no se rompe nada y el envío se reintenta luego (la clave se libera).
$nUser2 = (int) $usuarios->create('Nelly Reintento', 'nelly.re@correo.com', 'x');
$usuarios->marcarCorreoVerificado($nUser2);
$usuarios->updateNotificaciones($nUser2, true, false);
$nCuenta2 = $cuentas->create($nUser2, 'Banco Nelly', 'bancaria', '1000000.00');
$presupuestos->create($nUser2, $catA, 11, 2026, '100000.00');
$mov($nUser2, $nCuenta2, $catA, 'gasto', '90000.00', '2026-11-05');
$correos->fallar = true;
$antes = count($correos->enviados);
same('con el SMTP caído la alerta no lanza excepción ni cuenta como enviada', [0, false], [
    $notif->notificarGasto($nUser2, $catA, '2026-11-05'),
    $container->notificaciones()->existe(App\Notificaciones\NotificadorPresupuesto::clave($nUser2, null, $catA, 11, 2026, 80)),
]);
$correos->fallar = false;
same('al volver el SMTP, el siguiente gasto sí envía la alerta pendiente', [1, $antes + 1], [$notif->notificarGasto($nUser2, $catA, '2026-11-05'), count($correos->enviados)]);

// Espacio compartido: avisa a todos los miembros que lo activaron y nombra a quien registró el gasto.
$correos->enviados = [];
$f1 = (int) $usuarios->create('Ana Familia', 'ana.fam2@correo.com', 'x');
$usuarios->marcarCorreoVerificado($f1);
$f2 = (int) $usuarios->create('Luis Familia', 'luis.fam2@correo.com', 'x');
$usuarios->marcarCorreoVerificado($f2);
$f3 = (int) $usuarios->create('Intruso', 'intruso.fam2@correo.com', 'x');
$usuarios->updateNotificaciones($f1, true, false);
$usuarios->updateNotificaciones($f2, true, false);
$usuarios->updateNotificaciones($f3, true, false);
$espId = $container->gestionEspacios()->crear($f1, 'Hogar Ana y Luis', 'COP');
$invF = $container->gestionEspacios()->invitar($f1, 'Ana Familia', $espId, 'luis.fam2@correo.com', 'editor');
$container->gestionEspacios()->aceptarInvitacion($f2, 'luis.fam2@correo.com', null, $invF['token']);
$cFam = $cuentas->create($f1, 'Fondo Alertas', 'bancaria', '1000000.00', $espId);
$presupuestos->create($f1, $catC, 11, 2026, '200000.00', $espId);
$correos->enviados = [];
$pdo->prepare('INSERT INTO transacciones (id_usuario, id_espacio, id_cuenta, id_categoria, tipo, monto, fecha) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$f2, $espId, $cFam, $catC, 'gasto', '210000.00', '2026-11-10']);
same('en un espacio compartido el gasto de Luis avisa a Ana y a Luis', [2, ['ana.fam2@correo.com', 'luis.fam2@correo.com']], (static function () use ($notif, $f2, $catC, $espId, $correos): array {
    $n = $notif->notificarGasto($f2, $catC, '2026-11-10', $espId);
    $para = array_map(static fn (array $m): string => $m['to'], $correos->enviados);
    sort($para);

    return [$n, $para];
})());
$paraAna = array_values(array_filter($correos->enviados, static fn (array $m): bool => $m['to'] === 'ana.fam2@correo.com'))[0] ?? ['body' => ''];
same('el correo a Ana indica quién registró el gasto y el nombre del espacio', [true, true], [
    str_contains($paraAna['body'], 'Luis Familia registró un gasto'),
    str_contains($paraAna['body'], 'Hogar Ana y Luis'),
]);
same('el intruso (no miembro) no recibió nada', false, in_array('intruso.fam2@correo.com', array_column($correos->enviados, 'to'), true));

// Resumen mensual
$correos->enviados = [];
$mov($nUser, $nCuenta, $catA, 'gasto', '5000.00', '2026-10-03');
$mov($nUser, $nCuenta, $catB, 'ingreso', '3000000.00', '2026-10-01');
$resumen = $container->resumenMensual();
$nuevo = $usuarios->findById($nUser);
$texto = $resumen->construir($nuevo, 10, 2026);
same('el resumen incluye periodo, ingresos, gastos y balance en la moneda del usuario', [true, true, true, true], [
    str_contains($texto['asunto'], 'Octubre 2026'),
    str_contains($texto['cuerpo'], 'Ingresos: $3.000.000'),
    str_contains($texto['cuerpo'], 'Gastos:   $5.000'),
    str_contains($texto['cuerpo'], 'Balance:  $2.995.000'),
]);
same('el resumen lista las categorías con más gasto y metas en curso', [true, true], [
    str_contains($texto['cuerpo'], 'Donde más gastaste:'),
    str_contains($texto['cuerpo'], 'Metas de ahorro en curso:'),
]);
same('enviar el resumen del mes lo manda una sola vez', [true, false, 1], [$resumen->enviar($nuevo, 10, 2026), $resumen->enviar($nuevo, 10, 2026), count($correos->enviados)]);
same('el resumen de prueba (forzado) siempre se envía', [true, 2], [$resumen->enviar($nuevo, 10, 2026, true), count($correos->enviados)]);
same('solo los usuarios con el resumen activado son elegibles para el script', true, (static function () use ($usuarios, $nUser, $nOff): bool {
    $ids = array_map(static fn ($u): int => $u->id, $usuarios->listWithResumenMensual());

    return in_array($nUser, $ids, true) && !in_array($nOff, $ids, true);
})());
same('al eliminar la cuenta se borra su historial de notificaciones', 0, (static function () use ($borrar, $nUser, $pdo): int {
    $borrar->eliminar($nUser);

    return (int) $pdo->query("SELECT COUNT(*) FROM notificaciones_enviadas WHERE id_usuario = $nUser")->fetchColumn();
})());
same('migración 012-notificaciones-correo.sql es idempotente', true, (static function () use ($pdo): bool {
    foreach (SqlScript::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/012-notificaciones-correo.sql')) as $s) {
        $pdo->exec($s);
    }

    return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'notif_presupuestos'")->fetchColumn() === 1;
})());

// ---- A1: verificación del correo ------------------------------------------------------------------------------------

section('VerificacionCorreo: el correo debe demostrarse (A1)');
$vc = $container->verificacionCorreo();
$vTokenDe = static function (array $mail): string {
    preg_match('/verificar\.php\?token=([a-f0-9]{64})/', $mail['body'], $m);
    return $m[1] ?? '';
};
$vOk = (int) $usuarios->create('Valeria Verifica', 'valeria.ver@correo.com', 'x');
$vYa = (int) $usuarios->create('Vera Confiable', 'vera.ver@correo.com', 'x', 'usuario', true);
same('un usuario creado por el registro público nace SIN verificar', false, $usuarios->findById($vOk)?->correoVerificado);
same('create(..., verificado: true) (administradores de consola) nace verificado', true, $usuarios->findById($vYa)?->correoVerificado);
$correos->enviados = [];
same('enviar a una cuenta ya verificada no hace nada', ['ya_verificado', 0], [$vc->enviar($usuarios->findById($vYa)), count($correos->enviados)]);
same('enviar a una cuenta sin verificar manda un correo', ['ok', 1], [$vc->enviar($usuarios->findById($vOk)), count($correos->enviados)]);
$vMail = $correos->enviados[0];
$vTok1 = $vTokenDe($vMail);
same(
    'el correo va a su dirección, con enlace de 64 hex hacia el origen y avisa de las 48 horas',
    true,
    $vMail['to'] === 'valeria.ver@correo.com' && strlen($vTok1) === 64 && str_contains($vMail['body'], '/verificar.php?token=') && str_contains($vMail['body'], '48 horas') && str_contains($vMail['body'], 'Hola Valeria'),
);
same('en la BD solo está el HASH del token, no el token', [0, 1], [
    (int) $pdo->query("SELECT COUNT(*) FROM verificaciones_correo WHERE token_hash = '$vTok1'")->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM verificaciones_correo WHERE token_hash = ' . $pdo->quote(hash('sha256', $vTok1)))->fetchColumn(),
]);
same('el enlace vale y todavía NO verifica nada (solo confirmar lo hace)', [true, false], [$vc->esValido($vTok1), $usuarios->findById($vOk)?->correoVerificado]);
same('un token mal formado no sirve (ni se consulta)', [false, false], [$vc->esValido('abc'), $vc->confirmar(str_repeat('z', 64))]);
$vc->enviar($usuarios->findById($vOk));
$vTok2 = $vTokenDe($correos->enviados[1]);
same('pedir otro enlace anula el anterior', [false, true], [$vc->esValido($vTok1), $vc->esValido($vTok2)]);
same('un enlace anulado no verifica', [false, false], [$vc->confirmar($vTok1), $usuarios->findById($vOk)?->correoVerificado]);
same('el tercer envío de la hora aún sale y el cuarto topa con el límite (3 por hora)', ['ok', 'limite', 3], (static function () use ($vc, $usuarios, $vOk, $correos): array {
    return [$vc->enviar($usuarios->findById($vOk)), $vc->enviar($usuarios->findById($vOk)), count($correos->enviados)];
})());
$vTok3 = $vTokenDe($correos->enviados[2]);
$pdo->exec("UPDATE verificaciones_correo SET expira_en = TIMESTAMPADD(SECOND, -60, NOW()) WHERE id_usuario = $vOk");
same('un enlace caducado no sirve', [false, false], [$vc->esValido($vTok3), $vc->confirmar($vTok3)]);
$pdo->exec("UPDATE verificaciones_correo SET expira_en = TIMESTAMPADD(HOUR, 48, NOW()) WHERE id_usuario = $vOk");
$pdo->exec("UPDATE usuarios SET correo = 'otro.correo.ver@correo.com' WHERE id_usuario = $vOk");
same('si la cuenta cambió de correo desde el envío, el enlace ya no vale', [false, false], [$vc->confirmar($vTok3), $usuarios->findById($vOk)?->correoVerificado]);
$pdo->exec("UPDATE usuarios SET correo = 'valeria.ver@correo.com' WHERE id_usuario = $vOk");
$pdo->exec("UPDATE usuarios SET activo = 0 WHERE id_usuario = $vOk");
same('una cuenta desactivada no puede verificarse', false, $vc->confirmar($vTok3));
$pdo->exec("UPDATE usuarios SET activo = 1 WHERE id_usuario = $vOk");
same('con el enlace vigente el correo queda verificado', [true, true], [$vc->confirmar($vTok3), $usuarios->findById($vOk)?->correoVerificado]);
same('el enlace es de un solo uso', false, $vc->confirmar($vTok3));

$vRec = (int) $usuarios->create('Rita Recupera', 'rita.rec@correo.com', password_hash('clave-vieja-1', PASSWORD_DEFAULT));
same('Rita nace sin verificar', false, $usuarios->findById($vRec)?->correoVerificado);
$correos->enviados = [];
$recup->solicitar('rita.rec@correo.com');
same('restablecer la contraseña con el enlace que llegó a su buzón verifica el correo', [true, true], [
    $recup->restablecer($tokenDe($correos->enviados[0]), 'clave-nueva-123'),
    $usuarios->findById($vRec)?->correoVerificado,
]);

section('Invitaciones a espacios exigen correo verificado (A1)');
$aOwner = (int) $usuarios->create('Olivia Dueña', 'olivia.dueña@correo.com', 'x', 'usuario', true);
$aSquat = (int) $usuarios->create('Intrusa Registrada', 'victima.real@correo.com', 'x');   // se registró con el correo de otra persona
$aOtra = (int) $usuarios->create('Nadie Verificada', 'nadie.ver@correo.com', 'x');
$eA1 = ($ge->crear($aOwner, 'Hogar de Olivia', 'COP'));
$invS = $ge->invitar($aOwner, 'Olivia Dueña', $eA1, 'victima.real@correo.com', 'editor');
same('una persona verificada puede invitar', 'ok', $invS['codigo']);
same('la cuenta registrada con el correo de la víctima NO puede aceptar por token', ['correo_no_verificado', null], (static function () use ($ge, $aSquat, $invS): array {
    $r = $ge->aceptarInvitacion($aSquat, 'victima.real@correo.com', null, $invS['token']);
    return [$r['codigo'], $r['id_espacio']];
})());
$invId = $espRepo->listInvitationsForEmail('victima.real@correo.com')[0]['id_invitacion'] ?? 0;
same('ni por id de invitación', 'correo_no_verificado', $ge->aceptarInvitacion($aSquat, 'victima.real@correo.com', $invId, null)['codigo']);
same('y no entró al espacio ni gastó la invitación', [null, 1], [$espRepo->findForMember($aSquat, $eA1), count($espRepo->listInvitationsForEmail('victima.real@correo.com'))]);
same('quien no verificó su correo tampoco puede INVITAR a otros', 'correo_no_verificado', $ge->invitar($aOtra, 'Nadie Verificada', $ge->crear($aOtra, 'Espacio sin verificar', 'COP'), 'alguien@ejemplo.org', 'lector')['codigo']);
same('tras verificar el correo, la dueña real sí acepta la misma invitación', ['ok', 'editor'], (static function () use ($ge, $usuarios, $aSquat, $invS, $espRepo, $eA1): array {
    $usuarios->marcarCorreoVerificado($aSquat);
    $r = $ge->aceptarInvitacion($aSquat, 'victima.real@correo.com', null, $invS['token']);
    return [$r['codigo'], $espRepo->findForMember($aSquat, $eA1)?->rolUsuario];
})());
same('un usuario verificado con un token inventado recibe no_existe', 'no_existe', $ge->aceptarInvitacion($vYa, 'vera.ver@correo.com', null, str_repeat('a', 64))['codigo']);

section('Notificaciones por correo solo a correos verificados (A1)');
$nuVer = (int) $usuarios->create('Ursula Sinverificar', 'ursula.sv@correo.com', 'x');
$usuarios->updateNotificaciones($nuVer, true, true);
$nuCuenta = $cuentas->create($nuVer, 'Banco Ursula', 'bancaria', '1000000.00');
$presupuestos->create($nuVer, $catA, 11, 2026, '100000.00');
$mov($nuVer, $nuCuenta, $catA, 'gasto', '95000.00', '2026-11-05');
$correos->enviados = [];
same('con las alertas activadas pero sin verificar, un gasto al 95 % NO envía correo', [0, 0], [$notif->notificarGasto($nuVer, $catA, '2026-11-05'), count($correos->enviados)]);
same('tampoco el resumen mensual (ni el de prueba forzado)', [false, false, 0], [
    $container->resumenMensual()->enviar($usuarios->findById($nuVer), 10, 2026),
    $container->resumenMensual()->enviar($usuarios->findById($nuVer), 10, 2026, true),
    count($correos->enviados),
]);
same('el script del resumen mensual no la incluye entre los elegibles', false, in_array($nuVer, array_map(static fn ($u) => $u->id, $usuarios->listWithResumenMensual()), true));
$usuarios->marcarCorreoVerificado($nuVer);
same('al verificar el correo, la alerta pendiente sí sale', [1, 1], [$notif->notificarGasto($nuVer, $catA, '2026-11-05'), count($correos->enviados)]);
same('y ahora sí es elegible para el resumen mensual', true, in_array($nuVer, array_map(static fn ($u) => $u->id, $usuarios->listWithResumenMensual()), true));
same('migración 013-verificacion-correo.sql es idempotente y verifica a los administradores', true, (static function () use ($pdo): bool {
    foreach (SqlScript::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/013-verificacion-correo.sql')) as $st) {
        $pdo->exec($st);
    }
    return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'correo_verificado_en'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND correo_verificado_en IS NULL")->fetchColumn() === 0;
})());

finish();
