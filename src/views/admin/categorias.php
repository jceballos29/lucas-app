<?php

declare(strict_types=1);

use App\View\View;

/** Categorías predeterminadas, gestionadas por el administrador (RF-34). Ver partials/categorias.php para las variables. */
View::render('partials/categorias', get_defined_vars());
