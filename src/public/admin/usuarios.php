<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

(new App\Controller\AdminController(App\Container::shared()))->usuarios();
