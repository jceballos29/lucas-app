<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

(new App\Controller\MetaController(App\Container::shared()))->index();
