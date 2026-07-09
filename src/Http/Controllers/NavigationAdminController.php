<?php

declare(strict_types=1);

namespace Thallo\Navigation\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Http\Response;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Navigation\MenuUpdated;
use Thallo\Navigation\Http\MenuCreateDTO;
use Thallo\Navigation\Http\MenuReorderDTO;
use Thallo\Navigation\Http\MenuTreeDTO;
use Thallo\Navigation\MenuRepository;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

use function config;

/**
 * Admin menu CRUD + the whole-tree replace. Route-gated (capability → auth →
 * content_permission:navigation.manage); the tree write is lock_version-guarded (409 stale).
 * The tree read is LOCALE-AWARE: target_status/target_url are resolved for ?locale=, so
 * badges always reflect the locale the editor is looking at.
 */
final class NavigationAdminController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MenuRepository $menus,
        private readonly EntryTargetResolver $targets,
        private readonly EventService $events,
    ) {
    }

    #[ApiOperation(summary: 'List navigation menus', tags: ['Thallo Navigation'])]
    #[ApiResponse(200, description: 'Menu summaries (slug, name, item_count, lock_version).')]
    public function index(Request $request): Response
    {
        return Response::success(['menus' => $this->menus->listMenus()]);
    }

    #[ApiOperation(summary: 'Create a navigation menu', tags: ['Thallo Navigation'])]
    #[ApiResponse(201, description: 'The created menu.')]
    #[ApiResponse(409, description: 'Slug already exists.')]
    #[ApiResponse(422, description: 'Invalid slug/name.')]
    public function create(Request $request): Response
    {
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);
        $dto = MenuCreateDTO::fromRequest($body); // throws 422

        if ($this->menus->findMenu($dto->slug) !== null) {
            return Response::error("A menu with slug \"{$dto->slug}\" already exists.", 409);
        }
        $menu = $this->menus->createMenu($dto->slug, $dto->name);
        $this->events->dispatch(new MenuUpdated($dto->slug));
        return new Response([
            'success' => true,
            'message' => 'Menu created.',
            'data' => [
                'slug' => $menu['slug'],
                'name' => $menu['name'],
                'lock_version' => (int) $menu['lock_version'],
            ],
        ], 201);
    }

    #[ApiOperation(summary: 'Reorder navigation menus (full ordered set)', tags: ['Thallo Navigation'])]
    #[ApiResponse(200, description: 'The reordered menu summaries.')]
    #[ApiResponse(422, description: 'Payload is not the exact set of existing menus (dupe/unknown/missing).')]
    public function reorder(Request $request): Response
    {
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);
        $dto = MenuReorderDTO::fromRequest($body); // 422 on malformed shape

        // Uniform 422 (no write) for every business rejection — the client must send
        // the COMPLETE order so the result is always dense 0..n-1.
        if (count($dto->slugs) !== count(array_unique($dto->slugs))) {
            return Response::error('Duplicate slugs are not allowed.', 422);
        }
        $existing = array_map(
            static fn(array $m): string => (string) $m['slug'],
            $this->menus->listMenus(),
        );
        $a = $dto->slugs;
        $b = $existing;
        sort($a);
        sort($b);
        if ($a !== $b) {
            return Response::error('The slug list must be exactly the set of existing menus.', 422);
        }

        $this->menus->reorderMenus($dto->slugs);
        foreach ($dto->slugs as $slug) {
            $this->events->dispatch(new MenuUpdated($slug));
        }
        return Response::success(['menus' => $this->menus->listMenus()]);
    }

    #[ApiOperation(
        summary: 'Menu editor payload: full unfiltered tree for a locale',
        description: 'Per entry item: target_status (published|unpublished|deleted|missing|routeless), '
            . 'target_title (the localized page title an empty label inherits) '
            . 'and target_url resolved FOR ?locale= (status is locale-sensitive). Includes lock_version.',
        tags: ['Thallo Navigation'],
    )]
    #[ApiResponse(200, description: 'Menu + tree + lock_version + echoed locale.')]
    #[ApiResponse(404, description: 'Unknown menu.')]
    public function show(Request $request, string $slug): Response
    {
        $menu = $this->menus->findMenu($slug);
        if ($menu === null) {
            return Response::error('Unknown menu.', 404);
        }
        $locale = $this->locale($request);
        $byParent = [];
        foreach ($this->menus->itemsOf((string) $menu['uuid']) as $row) {
            $byParent[(string) ($row['parent_uuid'] ?? '')][] = $row;
        }
        return Response::success([
            'slug' => (string) $menu['slug'],
            'name' => (string) $menu['name'],
            'locale' => $locale,
            'lock_version' => (int) $menu['lock_version'],
            'items' => $this->tree($byParent, '', $locale),
        ]);
    }

    #[ApiOperation(summary: 'Rename a navigation menu', tags: ['Thallo Navigation'])]
    #[ApiResponse(200, description: 'Renamed.')]
    #[ApiResponse(404, description: 'Unknown menu.')]
    public function rename(Request $request, string $slug): Response
    {
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);
        $dto = MenuCreateDTO::fromRequest(['slug' => $slug, 'name' => $body['name'] ?? null]);
        if (!$this->menus->renameMenu($slug, $dto->name)) {
            return Response::error('Unknown menu.', 404);
        }
        $this->events->dispatch(new MenuUpdated($slug));
        return Response::success(['slug' => $slug, 'name' => $dto->name]);
    }

    #[ApiOperation(summary: 'Delete a navigation menu (and its items)', tags: ['Thallo Navigation'])]
    #[ApiResponse(200, description: 'Deleted.')]
    #[ApiResponse(404, description: 'Unknown menu.')]
    public function delete(Request $request, string $slug): Response
    {
        if (!$this->menus->deleteMenu($slug)) {
            return Response::error('Unknown menu.', 404);
        }
        $this->events->dispatch(new MenuUpdated($slug));
        return Response::success(['slug' => $slug]);
    }

    #[ApiOperation(
        summary: 'Replace a menu tree atomically',
        description: 'Whole-tree PUT guarded by lock_version (the GET payload carries it); '
            . 'a stale version is a 409 — reload and retry.',
        tags: ['Thallo Navigation'],
    )]
    #[ApiResponse(200, description: 'The updated editor payload.')]
    #[ApiResponse(404, description: 'Unknown menu.')]
    #[ApiResponse(409, description: 'Stale lock_version.')]
    #[ApiResponse(422, description: 'Invalid tree (kind, url, labels, depth, count, target).')]
    public function replaceItems(Request $request, string $slug): Response
    {
        $menu = $this->menus->findMenu($slug);
        if ($menu === null) {
            return Response::error('Unknown menu.', 404);
        }
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);
        $dto = MenuTreeDTO::fromRequest($body, $this->targets, $this->locale($request)); // throws 422

        if (!$this->menus->replaceTree((string) $menu['uuid'], $dto->lockVersion, $dto->rows)) {
            $current = $this->menus->findMenu($slug);
            return Response::error(
                'The menu changed since you loaded it. Reload and retry.',
                409,
                ['lock_version' => (int) ($current['lock_version'] ?? 0)],
            );
        }
        $this->events->dispatch(new MenuUpdated($slug));
        return $this->show($request, $slug);
    }

    private function locale(Request $request): string
    {
        $locale = (string) $request->query->get('locale', '');
        return $locale !== '' ? $locale : (string) config($this->context, 'i18n.default_locale', 'en');
    }

    /**
     * @param array<string, list<array<string,mixed>>> $byParent
     * @return list<array<string,mixed>> unfiltered editor tree with per-locale target badges
     */
    private function tree(array $byParent, string $parent, string $locale): array
    {
        $out = [];
        foreach ($byParent[$parent] ?? [] as $row) {
            $node = [
                'uuid' => (string) $row['uuid'],
                'kind' => (string) $row['kind'],
                // Optional Lucide icon (nav-v2 spec §5).
                'icon' => isset($row['icon']) && $row['icon'] !== '' ? (string) $row['icon'] : null,
                'labels' => json_decode((string) $row['labels'], true) ?: [],
                // Optional locale → description (nav-v2 megamenu); [] when absent.
                'descriptions' => json_decode((string) ($row['descriptions'] ?? ''), true) ?: [],
                'children' => $this->tree($byParent, (string) $row['uuid'], $locale),
            ];
            if ((string) $row['kind'] === 'entry') {
                $target = $this->targets->resolve((string) $row['entry_uuid'], $locale);
                $node['entry_uuid'] = (string) $row['entry_uuid'];
                $node['target_status'] = $target['status'];
                $node['target_url'] = $target['path'];
                // The inherited menu label (nav-entry-items design): the SPA
                // shows this as the label placeholder — never guesses titles.
                $node['target_title'] = $target['title'] ?? null;
            } else {
                $node['url'] = (string) $row['url'];
            }
            $out[] = $node;
        }
        return $out;
    }
}
