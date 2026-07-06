<?php

declare(strict_types=1);

use Thallo\Navigation\Http\Controllers\MenuController;
use Glueful\Routing\Router;

/** @var Router $router */

// Public resolved menus (published-only). Rate-limited like every anonymous Thallo surface.
$router->get('/v1/menus/{slug}', [MenuController::class, 'show'])
    ->middleware('rate_limit');
