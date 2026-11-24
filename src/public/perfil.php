<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

(new App\Controller\PerfilController(App\Container::shared()))->index();
