<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Container;
use App\Controller\MovimientoController;

(new MovimientoController(Container::shared()))->importar();
