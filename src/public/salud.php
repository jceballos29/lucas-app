<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

(new App\Controller\SaludController(App\Container::shared()))->estado();
