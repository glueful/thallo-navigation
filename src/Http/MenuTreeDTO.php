<?php

declare(strict_types=1);

namespace Thallo\Navigation\Http;

use Glueful\Helpers\Utils;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Validator;
use Glueful\Validation\Rules\Required;
use Glueful\Validation\Rules\Type;

/**
 * Validates a whole-tree replacement payload recursively (spec §5) and flattens it into
 * rows ready for MenuRepository::replaceTree(). Rules: kind ∈ {entry,url}; entry targets
 * must exist and not be deleted (missing/deleted → 422; unpublished/routeless allowed —
 * editors build menus while content is in draft or awaiting a route); urls are
 * http(s):// or site-relative (≤ 1024); labels map locale → string ≤ 200; depth ≤ 6;
 * ≤ 500 items total. Errors carry dot-paths (items.0.children.1.url).
 */
final class MenuTreeDTO
{
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 500;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(
        public readonly int $lockVersion,
        public readonly array $rows,
    ) {
    }

    /**
     * @param array<string,mixed> $body
     * @throws ValidationException
     */
    public static function fromRequest(array $body, EntryTargetResolver $targets, string $locale): self
    {
        $validator = new Validator([
            'lock_version' => [new Required(), new Type('integer')],
        ]);
        $errors = $validator->validate(['lock_version' => $body['lock_version'] ?? null]);

        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $count = 0;
        $rows = self::walk($items, null, 1, $count, $targets, $locale, $errors, 'items.');

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return new self((int) ($body['lock_version'] ?? 0), $rows);
    }

    /**
     * @param list<mixed> $items
     * @param array<string, list<string>> $errors
     * @return list<array<string,mixed>>
     */
    private static function walk(
        array $items,
        ?string $parent,
        int $depth,
        int &$count,
        EntryTargetResolver $targets,
        string $locale,
        array &$errors,
        string $path,
    ): array {
        if ($depth > self::MAX_DEPTH) {
            $errors[rtrim($path, '.')] = ['menu depth exceeds ' . self::MAX_DEPTH];
            return [];
        }
        $rows = [];
        foreach (array_values($items) as $i => $item) {
            $p = "{$path}{$i}";
            if (!is_array($item)) {
                $errors[$p] = ['item must be an object'];
                continue;
            }
            if (++$count > self::MAX_ITEMS) {
                $errors[$p] = ['menu exceeds ' . self::MAX_ITEMS . ' items'];
                return $rows;
            }
            $labels = is_array($item['labels'] ?? null) ? $item['labels'] : [];
            foreach ($labels as $loc => $text) {
                if (!is_string($loc) || !is_string($text) || mb_strlen($text) > 200) {
                    $errors["{$p}.labels"] = ['labels must map locale to string of at most 200 chars'];
                    break;
                }
            }
            // Optional Lucide icon (nav-v2 spec §5): lucide-only grammar — no
            // brand: namespace in navigation. Empty/null = no icon.
            $icon = $item['icon'] ?? null;
            if ($icon === '') {
                $icon = null;
            }
            if ($icon !== null) {
                if (
                    !is_string($icon)
                    || strlen($icon) > 64
                    || preg_match('/\A[a-z0-9]+(-[a-z0-9]+)*\z/', $icon) !== 1
                ) {
                    $errors["{$p}.icon"] = ['icon must be a lucide name (lowercase, hyphenated)'];
                    $icon = null;
                }
            }
            $uuid = Utils::generateNanoID();
            $kind = $item['kind'] ?? null;
            if ($kind === 'entry') {
                $entry = is_string($item['entry_uuid'] ?? null) ? $item['entry_uuid'] : '';
                $status = $entry !== '' ? $targets->resolve($entry, $locale)['status'] : 'missing';
                if (in_array($status, ['missing', 'deleted'], true)) {
                    $errors["{$p}.entry_uuid"] = ["entry target is {$status}"];
                }
                $rows[] = self::row($uuid, $parent, $i, 'entry', $entry, null, $labels, $icon);
            } elseif ($kind === 'url') {
                $url = is_string($item['url'] ?? null) ? trim($item['url']) : '';
                $ok = preg_match('#^(https?://|/)#', $url) === 1 && mb_strlen($url) <= 1024;
                if (!$ok) {
                    $errors["{$p}.url"] = ['url must be http(s):// or site-relative /… of at most 1024 chars'];
                }
                $rows[] = self::row($uuid, $parent, $i, 'url', null, $url, $labels, $icon);
            } else {
                $errors["{$p}.kind"] = ['kind must be entry or url'];
                continue;
            }
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            if ($children !== []) {
                $rows = array_merge($rows, self::walk(
                    $children,
                    $uuid,
                    $depth + 1,
                    $count,
                    $targets,
                    $locale,
                    $errors,
                    "{$p}.children.",
                ));
            }
        }
        return $rows;
    }

    /**
     * @param array<string,string> $labels
     * @return array<string,mixed>
     */
    private static function row(
        string $uuid,
        ?string $parent,
        int $position,
        string $kind,
        ?string $entryUuid,
        ?string $url,
        array $labels,
        ?string $icon,
    ): array {
        $now = gmdate('Y-m-d H:i:s');
        return [
            'uuid' => $uuid,
            'parent_uuid' => $parent,
            'position' => $position,
            'kind' => $kind,
            'entry_uuid' => $entryUuid,
            'url' => $url,
            'icon' => $icon,
            'labels' => json_encode($labels, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
