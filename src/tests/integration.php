<?php

declare(strict_types=1);

/**
 * Pruebas de integración: repositorios y servicios contra una BASE DE DATOS TEMPORAL (D6). Crea `<base>_test_<azar>`
 * con database/01-schema.sql y 02-seed.sql, corre las pruebas y la borra al terminar. Nunca toca la base de la aplicación.
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
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
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

foreach (['01-schema.sql', '02-seed.sql'] as $file) {
    $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/' . $file);
    $sql = preg_replace('/^--.*$/m', '', $sql) ?? '';

    foreach (preg_split('/;\s*(?:\n|$)/', $sql) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
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

for ($i = 0; $i < LimitadorIntentos::MAX_POR_CORREO - 1; $i++) {
    $limitador->registrar('login', 'x@correo.com', '10.0.0.' . (10 + $i));
}
same('4 fallos del mismo correo aún no bloquean', false, $limitador->bloqueado('login', 'x@correo.com', '10.0.0.99'));
$limitador->registrar('login', 'X@correo.com', '10.0.0.50');
same('el 5.º fallo bloquea ese correo (sin distinguir mayúsculas)', true, $limitador->bloqueado('login', 'x@correo.com', '10.0.0.99'));
same('otro correo desde otra IP no se ve afectado', false, $limitador->bloqueado('login', 'y@correo.com', '10.0.0.99'));
same('los fallos de login no cuentan para el registro', false, $limitador->bloqueado('registro', 'x@correo.com', '10.0.0.99'));
$limitador->perdonar('login', 'x@correo.com');
same('perdonar libera el correo', false, $limitador->bloqueado('login', 'x@correo.com', '10.0.0.99'));

for ($i = 0; $i < LimitadorIntentos::MAX_POR_IP; $i++) {
    $limitador->registrar('registro', "z$i@correo.com", '10.9.9.9');
}
same('20 intentos desde una IP la bloquean aunque los correos sean distintos', true, $limitador->bloqueado('registro', 'nuevo@correo.com', '10.9.9.9'));

$pdo->exec("UPDATE intentos_acceso SET creado_en = NOW() - INTERVAL 16 MINUTE");
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

finish();
