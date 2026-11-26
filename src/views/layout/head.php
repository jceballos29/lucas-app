<?php

declare(strict_types=1);

/**
 * Cabecera HTML común (hasta <body>). Variables (las pasa View::page()):
 *
 * @var string $pageTitle Título de la página.
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#061A24">
    <title><?= escape(($pageTitle ?? 'Inicio') . ' · Lucas') ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <?php foreach (['tokens', 'base', 'components', 'layout', 'pages'] as $layer): ?>
    <link rel="stylesheet" href="<?= escape(asset("css/$layer.css")) ?>">
    <?php endforeach; ?>
</head>

<body>
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
