<?php

declare(strict_types=1);

namespace Thallo\Navigation;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * Reads/writes navigation_menus + navigation_items. replaceTree() is the ONLY tree write:
 * one transaction, guarded by the menu's lock_version (optimistic concurrency — a stale
 * version returns false and the caller 409s; the collections schema_version precedent).
 */
final class MenuRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed> the created menu row */
    public function createMenu(string $slug, string $name): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'uuid' => Utils::generateNanoID(),
            'slug' => $slug,
            'name' => $name,
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->db->table('navigation_menus')->insert($row);
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function findMenu(string $slug): ?array
    {
        $row = $this->db->table('navigation_menus')->where('slug', '=', $slug)->first();
        return $row === null ? null : (array) $row;
    }

    /** @return list<array{slug:string,name:string,item_count:int,lock_version:int}> */
    public function listMenus(): array
    {
        $stmt = $this->db->getPDO()->query(
            'SELECT m.slug, m.name, m.lock_version, COUNT(i.id) AS item_count'
            . ' FROM navigation_menus m LEFT JOIN navigation_items i ON i.menu_uuid = m.uuid'
            . ' GROUP BY m.id, m.slug, m.name, m.lock_version ORDER BY m.slug ASC'
        );
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'item_count' => (int) $row['item_count'],
                'lock_version' => (int) $row['lock_version'],
            ];
        }
        return $out;
    }

    public function renameMenu(string $slug, string $name): bool
    {
        $affected = $this->db->table('navigation_menus')
            ->where('slug', '=', $slug)
            ->update(['name' => $name, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return $affected > 0;
    }

    public function deleteMenu(string $slug): bool
    {
        $menu = $this->findMenu($slug);
        if ($menu === null) {
            return false;
        }
        $pdo = $this->db->getPDO();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM navigation_items WHERE menu_uuid = ?')
                ->execute([(string) $menu['uuid']]);
            $pdo->prepare('DELETE FROM navigation_menus WHERE uuid = ?')
                ->execute([(string) $menu['uuid']]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> flat rows in (position, id) order */
    public function itemsOf(string $menuUuid): array
    {
        $stmt = $this->db->getPDO()->prepare(
            'SELECT uuid, parent_uuid, position, kind, entry_uuid, url, icon, labels'
            . ' FROM navigation_items WHERE menu_uuid = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$menuUuid]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string,mixed>> $flatItems pre-validated flat rows
     * @return bool false when the lock_version is stale (caller responds 409)
     */
    public function replaceTree(string $menuUuid, int $lockVersion, array $flatItems): bool
    {
        $pdo = $this->db->getPDO();
        $pdo->beginTransaction();
        try {
            $guard = $pdo->prepare(
                'UPDATE navigation_menus SET lock_version = lock_version + 1, updated_at = ?'
                . ' WHERE uuid = ? AND lock_version = ?'
            );
            $guard->execute([gmdate('Y-m-d H:i:s'), $menuUuid, $lockVersion]);
            if ($guard->rowCount() === 0) {
                $pdo->rollBack();
                return false; // stale lock_version (or vanished menu)
            }
            $pdo->prepare('DELETE FROM navigation_items WHERE menu_uuid = ?')->execute([$menuUuid]);
            foreach ($flatItems as $row) {
                $this->db->table('navigation_items')->insert($row + ['menu_uuid' => $menuUuid]);
            }
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
