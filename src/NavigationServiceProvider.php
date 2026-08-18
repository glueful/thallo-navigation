<?php

declare(strict_types=1);

namespace Thallo\Navigation;

use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Navigation\MenuReader;
use Thallo\Navigation\Http\Controllers\MenuController;
use Thallo\Navigation\Http\Controllers\NavigationAdminController;
use Psr\Container\ContainerInterface;

final class NavigationServiceProvider extends ServiceProvider implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [];
    }

    /**
     * Post-extension tier (modules-not-extensions spec §5.2): app-integrated modules load
     * AFTER the extension universe, reproducing the pre-conversion order in which they lived
     * at the tail of config/extensions.php. Inter-module order comes from the
     * serviceproviders.php list (the orderer's stable tie-break).
     */
    public static function loadPriority(): int
    {
        return 100;
    }

    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            MenuRepository::class => [
                'class' => MenuRepository::class, 'shared' => true, 'autowire' => true,
            ],
            MenuResolver::class => [
                'shared' => true,
                'factory' => [self::class, 'makeMenuResolver'],
            ],
            MenuReader::class => [
                'shared' => true,
                'factory' => [self::class, 'makeMenuResolver'],
            ],
            NavigationAdminController::class => [
                'class' => NavigationAdminController::class, 'shared' => true, 'autowire' => true,
            ],
            MenuController::class => [
                'class' => MenuController::class, 'shared' => true, 'autowire' => true,
            ],
        ];
    }

    public static function makeMenuResolver(ContainerInterface $container): MenuResolver
    {
        return new MenuResolver(
            $container->get(ApplicationContext::class),
            $container->get(CapabilityRegistry::class),
            $container->get(MenuRepository::class),
            $container->get(EntryTargetResolver::class),
        );
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'thallo.navigation',
            label: 'Navigation',
            description: 'Menu trees served headless and to themes.',
        ));

        // Migrations are declared by the composer manifest (extra.glueful.migrations).

        if ($registry->isEnabled('thallo.navigation')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/public-routes.php');
        }
    }
}
