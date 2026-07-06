<?php

declare(strict_types=1);

namespace Thallo\Navigation;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Navigation\MenuReader;

use function config;

/**
 * MenuReader implementation: resolves a menu tree for a locale — label fallback chain
 * (requested → default → any → the TARGET ENTRY'S localized title for entry items:
 * an empty label means "follow the page title", typed = override — nav-entry-items
 * design), url items verbatim, entry items via EntryTargetResolver with
 * non-published items dropped WITH their subtree (spec §4).
 */
final class MenuResolver implements MenuReader
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CapabilityRegistry $capabilities,
        private readonly MenuRepository $menus,
        private readonly EntryTargetResolver $targets,
    ) {
    }

    public function menu(string $slug, string $locale): ?array
    {
        // Bindings are compile-time, so the disabled check lives here (the publish-gate
        // precedent): a disabled capability must look exactly like "pack absent".
        if (!$this->capabilities->isEnabled('lemma.navigation')) {
            return null;
        }
        $menu = $this->menus->findMenu($slug);
        if ($menu === null) {
            return null;
        }
        $byParent = [];
        foreach ($this->menus->itemsOf((string) $menu['uuid']) as $row) {
            $byParent[(string) ($row['parent_uuid'] ?? '')][] = $row;
        }
        return $this->children($byParent, '', $locale);
    }

    /**
     * @param array<string, list<array<string,mixed>>> $byParent
     * @return list<array{label:string, url:string, entry:?string, icon:?string, children:list<mixed>}>
     */
    private function children(array $byParent, string $parent, string $locale): array
    {
        $out = [];
        foreach ($byParent[$parent] ?? [] as $row) {
            $entry = null;
            $inherited = null;
            if ((string) $row['kind'] === 'entry') {
                $entry = (string) $row['entry_uuid'];
                $target = $this->targets->resolve($entry, $locale);
                if ($target['status'] !== 'published') {
                    continue; // spec §4: drop the item AND its subtree
                }
                $url = (string) $target['path'];
                $inherited = $target['title'] ?? null;
            } else {
                $url = (string) $row['url'];
            }
            $label = $this->label($row, $locale);
            if ($label === '' && is_string($inherited)) {
                $label = $inherited; // empty label inherits the page title
            }
            $out[] = [
                'label' => $label,
                'url' => $url,
                'entry' => $entry,
                // Optional Lucide icon (nav-v2 spec §5); templates render via icon().
                'icon' => isset($row['icon']) && $row['icon'] !== '' ? (string) $row['icon'] : null,
                'children' => $this->children($byParent, (string) $row['uuid'], $locale),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function label(array $row, string $locale): string
    {
        $labels = json_decode((string) $row['labels'], true);
        if (!is_array($labels) || $labels === []) {
            return '';
        }
        $default = (string) config($this->context, 'i18n.default_locale', 'en');
        return (string) ($labels[$locale] ?? $labels[$default] ?? reset($labels));
    }
}
