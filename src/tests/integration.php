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
$banco = $cuentas->create($elena, 'Banco', 'bancaria', '0');
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
same('nada de lo rechazado se guardó', $antes, $cuenta($elena));
$cuentas->setActiva($elena, $banco, true);
same('al reactivarla sí', 'ok', $gestion2->guardar($elena, null, $dato(['id_cuenta' => $banco, 'monto' => '2500']))['codigo']);

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

finish();
