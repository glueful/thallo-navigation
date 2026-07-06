<?php

declare(strict_types=1);

namespace Thallo\Navigation\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Thallo\Contracts\Navigation\MenuReader;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

use function config;

/** Public resolved menu (published-only tree, the MenuReader shape). No auth; rate-limited. */
final class MenuController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MenuReader $menus,
    ) {
    }

    /**
     * @queryParam locale:string="Locale to resolve; defaults to the site default."
     */
    #[ApiOperation(
        summary: 'Resolved navigation menu',
        description: 'Published-only tree: entry items resolve to live public paths; items whose '
            . 'target is not published are omitted with their subtree. Labels follow the locale '
            . 'fallback chain (requested → default → any).',
        tags: ['Thallo Navigation'],
    )]
    #[ApiResponse(200, description: 'The resolved menu tree.')]
    #[ApiResponse(404, description: 'Unknown menu.')]
    public function show(Request $request, string $slug): Response
    {
        $locale = (string) $request->query->get('locale', '');
        $locale = $locale !== '' ? $locale : (string) config($this->context, 'i18n.default_locale', 'en');

        $items = $this->menus->menu($slug, $locale);
        if ($items === null) {
            return Response::error('Unknown menu.', 404);
        }
        return Response::success(['slug' => $slug, 'locale' => $locale, 'items' => $items]);
    }
}
