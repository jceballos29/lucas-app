<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

(new App\Controller\StatusController(new App\Container()))->show();
