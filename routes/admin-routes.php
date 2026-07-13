<?php

declare(strict_types=1);

use Thallo\Navigation\Http\Controllers\NavigationAdminController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin navigation API. Triple-gated like the other packs:
 *   1. capability       — this file loads only when thallo.navigation is enabled (else 404).
 *   2. auth             — group middleware.
 *   3. content_permission — navigation.manage on every route.
 *   4. admin_tenant_binding — binds the operator's selected workspace so navigation_menus/items scope
 *      to it (mirrors routes/admin.php); inert until full resolution, tenant_bootstrap handles bootstrap.
 */
$router->group(
    [
        'prefix' => '/v1/admin/navigation',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->get('/menus', [NavigationAdminController::class, 'index'])
            ->middleware('content_permission:navigation.manage');
        $router->post('/menus', [NavigationAdminController::class, 'create'])
            ->middleware('content_permission:navigation.manage');
        $router->post('/menus/reorder', [NavigationAdminController::class, 'reorder'])
            ->middleware('content_permission:navigation.manage');
        $router->get('/menus/{slug}', [NavigationAdminController::class, 'show'])
            ->middleware('content_permission:navigation.manage');
        $router->put('/menus/{slug}', [NavigationAdminController::class, 'rename'])
            ->middleware('content_permission:navigation.manage');
        $router->delete('/menus/{slug}', [NavigationAdminController::class, 'delete'])
            ->middleware('content_permission:navigation.manage');
        $router->put('/menus/{slug}/items', [NavigationAdminController::class, 'replaceItems'])
            ->middleware('content_permission:navigation.manage');
    },
);
