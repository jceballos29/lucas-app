<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

(new App\Controller\PresupuestoController(App\Container::shared()))->form();
