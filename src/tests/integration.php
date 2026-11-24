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

finish();
