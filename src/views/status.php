<?php

declare(strict_types=1);

/**
 * Diagnóstico del stack.
 *
 * @var list<string> $requiredExtensions
 * @var array{ok: bool, message: string} $database
 */
?>
<h1>Lucas · estado del stack</h1>

<p>PHP <strong><?= escape(PHP_VERSION) ?></strong> · SAPI: <?= escape(PHP_SAPI) ?> · Entorno: <?= escape((string) getenv('APP_ENV')) ?></p>

<h2>Extensiones</h2>
<ul>
    <?php foreach ($requiredExtensions as $extension): ?>
        <li><?= extension_loaded($extension) ? '✔' : '✘' ?> <?= escape($extension) ?><?= extension_loaded($extension) ? '' : ' (falta)' ?></li>
    <?php endforeach; ?>
</ul>

<h2>Base de datos</h2>
<p class="notice notice--<?= $database['ok'] ? 'ok' : 'error' ?>" role="status"><?= escape($database['message']) ?></p>
