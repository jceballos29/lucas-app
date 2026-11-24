<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

(new App\Controller\MovimientoController(App\Container::shared()))->index();
