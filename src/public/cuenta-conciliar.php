<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Container;
use App\Controller\CuentaController;

(new CuentaController(Container::shared()))->conciliar();
