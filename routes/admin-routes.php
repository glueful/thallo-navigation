<?php

declare(strict_types=1);

use Thallo\Navigation\Http\Controllers\NavigationAdminController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin navigation API. Triple-gated like the other packs:
 *   1. capability       — this file loads only when lemma.navigation is enabled (else 404).
 *   2. auth             — group middleware.
 *   3. lemma_permission — navigation.manage on every route.
 */
$router->group(
    ['prefix' => '/v1/admin/navigation', 'middleware' => ['auth']],
    function (Router $router): void {
        $router->get('/menus', [NavigationAdminController::class, 'index'])
            ->middleware('lemma_permission:navigation.manage');
        $router->post('/menus', [NavigationAdminController::class, 'create'])
            ->middleware('lemma_permission:navigation.manage');
        $router->get('/menus/{slug}', [NavigationAdminController::class, 'show'])
            ->middleware('lemma_permission:navigation.manage');
        $router->put('/menus/{slug}', [NavigationAdminController::class, 'rename'])
            ->middleware('lemma_permission:navigation.manage');
        $router->delete('/menus/{slug}', [NavigationAdminController::class, 'delete'])
            ->middleware('lemma_permission:navigation.manage');
        $router->put('/menus/{slug}/items', [NavigationAdminController::class, 'replaceItems'])
            ->middleware('lemma_permission:navigation.manage');
    },
);
