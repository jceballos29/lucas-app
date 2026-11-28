<?php

declare(strict_types=1);

/**
 * Envía los correos de la cola (M4). Lo ejecuta en bucle el servicio `correo` de docker-compose (cada pocos segundos); también se puede
 * lanzar a mano o desde cron:
 *
 *     docker compose exec -T php php scripts/enviar-cola.php [--max=100] [--estado]
 *
 * Cada correo se intenta hasta 5 veces con espera creciente (1, 5, 15 y 60 min); después queda «fallido» (se registra en el log).
 * Al salir o darse por perdido, su cuerpo se borra de la base (puede llevar enlaces con token).
 * `--estado` solo muestra cuántos hay por estado y el pendiente más antiguo (para monitorizar).
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Container;
use App\Logging\Logger;
use App\Mail\MailException;
use App\Mail\SmtpMailer;
use App\Repository\ColaCorreoRepository;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$cola = new ColaCorreoRepository(Container::shared()->pdo());

if (in_array('--estado', $argv, true)) {
    $r = $cola->resumen();
    echo "pendientes: {$r['pendiente']}  enviados: {$r['enviado']}  fallidos: {$r['fallido']}  pendiente más antiguo: " . ($r['mas_antiguo_segundos'] === null ? '-' : $r['mas_antiguo_segundos'] . ' s') . "\n";
    exit(0);
}

$max = 100;
foreach ($argv as $arg) {
    if (preg_match('/^--max=(\d+)$/', $arg, $m) === 1) {
        $max = max(1, (int) $m[1]);
    }
}

try {
    $smtp = SmtpMailer::fromEnvironment();
} catch (MailException $e) {
    Logger::exception('mail', $e);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$enviados = 0;
$fallos = 0;
$inicio = time();

// Lotes pequeños y con límite de tiempo, para que un SMTP lento no deje el proceso atascado y el bucle pueda volver a empezar.
while ($enviados + $fallos < $max && time() - $inicio < 50) {
    $lote = $cola->reclamar(min(10, $max - $enviados - $fallos));

    if ($lote === []) {
        break;
    }

    foreach ($lote as $correo) {
        try {
            $smtp->send($correo['destinatario'], $correo['asunto'], $correo['cuerpo']);
            $cola->marcarEnviado($correo['id_correo']);
            $enviados++;
        } catch (MailException $e) {
            $fallos++;
            Logger::exception('mail', $e);
            $cola->marcarFallo($correo['id_correo'], $correo['intentos'], $e->getMessage());
        }
    }
}

if (random_int(1, 20) === 1) {
    $cola->purgar();
}

if ($enviados + $fallos > 0) {
    echo "enviados: $enviados, con error (se reintentan): $fallos\n";
}
