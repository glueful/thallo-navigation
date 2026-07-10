<?php

declare(strict_types=1);

namespace Thallo\Navigation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Tenancy\WriteBarrier;

/**
 * Reads/writes navigation_menus + navigation_items. replaceTree() is the ONLY tree write:
 * one transaction, guarded by the menu's lock_version (optimistic concurrency — a stale
 * version returns false and the caller 409s; the collections schema_version precedent).
 */
final class MenuRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    /** @return array<string,mixed> the created menu row */
    public function createMenu(string $slug, string $name): array
    {
        $now = gmdate('Y-m-d H:i:s');
        // Raw SQL bypasses the tenancy hook — scope the position scan so positions stay dense
        // per tenant (the following insert is a builder call and IS stamped).
        $tenant = TenantScope::current($this->tenants, $this->context);
        $sql = 'SELECT COALESCE(MAX(position), -1) AS m FROM navigation_menus';
        $params = [];
        if ($tenant !== null) {
            $sql .= ' WHERE tenant_uuid = ?';
            $params[] = $tenant;
        }
        $maxStmt = $this->db->getPDO()->prepare($sql);
        $maxStmt->execute($params);
        $max = $maxStmt->fetch(\PDO::FETCH_ASSOC);
        $row = [
            'uuid' => Utils::generateNanoID(),
            'slug' => $slug,
            'name' => $name,
            'lock_version' => 0,
            'position' => ((int) ($max['m'] ?? -1)) + 1,
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
        // Raw SQL — scope the menu filter AND the joined items (prevents cross-tenant item-count drift).
        $tenant = TenantScope::current($this->tenants, $this->context);
        $join = $tenant === null
            ? ' LEFT JOIN navigation_items i ON i.menu_uuid = m.uuid'
            : ' LEFT JOIN navigation_items i ON i.menu_uuid = m.uuid AND i.tenant_uuid = m.tenant_uuid';
        $where = $tenant === null ? '' : ' WHERE m.tenant_uuid = ?';
        $stmt = $this->db->getPDO()->prepare(
            'SELECT m.slug, m.name, m.lock_version, COUNT(i.id) AS item_count'
            . ' FROM navigation_menus m' . $join . $where
            . ' GROUP BY m.id, m.slug, m.name, m.lock_version, m.position'
            . ' ORDER BY m.position ASC, m.slug ASC'
        );
        $stmt->execute($tenant === null ? [] : [$tenant]);
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
        $write = function () use ($menu): bool {
            $pdo = $this->db->getPDO();
            $tenant = TenantScope::current($this->tenants, $this->context);
            $extra = $tenant === null ? '' : ' AND tenant_uuid = ?';
            $uuid = (string) $menu['uuid'];
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM navigation_items WHERE menu_uuid = ?' . $extra)
                    ->execute($tenant === null ? [$uuid] : [$uuid, $tenant]);
                $pdo->prepare('DELETE FROM navigation_menus WHERE uuid = ?' . $extra)
                    ->execute($tenant === null ? [$uuid] : [$uuid, $tenant]);
                $pdo->commit();
                return true;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        };

        return $this->barrier !== null ? $this->barrier->runWritable($write) : $write();
    }

    /**
     * Rewrite the admin-list order. The caller passes the FULL ordered slug set
     * (validated in the controller); this writes dense 0..n-1 in one transaction.
     *
     * @param list<string> $slugs
     */
    public function reorderMenus(array $slugs): void
    {
        $write = function () use ($slugs): void {
            $pdo = $this->db->getPDO();
            $tenant = TenantScope::current($this->tenants, $this->context);
            $where = $tenant === null ? 'WHERE slug = ?' : 'WHERE slug = ? AND tenant_uuid = ?';
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE navigation_menus SET position = ?, updated_at = ? {$where}");
                $now = gmdate('Y-m-d H:i:s');
                foreach (array_values($slugs) as $i => $slug) {
                    $params = $tenant === null
                        ? [$i, $now, $slug]
                        : [$i, $now, $slug, $tenant];
                    $stmt->execute($params);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        };
        $this->barrier !== null ? $this->barrier->runWritable($write) : $write();
    }

    /** @return list<array<string,mixed>> flat rows in (position, id) order */
    public function itemsOf(string $menuUuid): array
    {
        $tenant = TenantScope::current($this->tenants, $this->context);
        $extra = $tenant === null ? '' : ' AND tenant_uuid = ?';
        $stmt = $this->db->getPDO()->prepare(
            'SELECT uuid, parent_uuid, position, kind, entry_uuid, url, icon, labels, descriptions'
            . ' FROM navigation_items WHERE menu_uuid = ?' . $extra . ' ORDER BY position ASC, id ASC'
        );
        $stmt->execute($tenant === null ? [$menuUuid] : [$menuUuid, $tenant]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string,mixed>> $flatItems pre-validated flat rows
     * @return bool false when the lock_version is stale (caller responds 409)
     */
    public function replaceTree(string $menuUuid, int $lockVersion, array $flatItems): bool
    {
        $write = function () use ($menuUuid, $lockVersion, $flatItems): bool {
            $pdo = $this->db->getPDO();
            $tenant = TenantScope::current($this->tenants, $this->context);
            $extra = $tenant === null ? '' : ' AND tenant_uuid = ?';
            $pdo->beginTransaction();
            try {
                $guard = $pdo->prepare(
                    'UPDATE navigation_menus SET lock_version = lock_version + 1, updated_at = ?'
                    . ' WHERE uuid = ? AND lock_version = ?' . $extra
                );
                $guardParams = [gmdate('Y-m-d H:i:s'), $menuUuid, $lockVersion];
                if ($tenant !== null) {
                    $guardParams[] = $tenant;
                }
                $guard->execute($guardParams);
                if ($guard->rowCount() === 0) {
                    $pdo->rollBack();
                    return false; // stale lock_version (or vanished menu)
                }
                $pdo->prepare('DELETE FROM navigation_items WHERE menu_uuid = ?' . $extra)
                ->execute($tenant === null ? [$menuUuid] : [$menuUuid, $tenant]);
                foreach ($flatItems as $row) {
                    $this->db->table('navigation_items')->insert($row + ['menu_uuid' => $menuUuid]);
                }
                $pdo->commit();
                return true;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        };

        return $this->barrier !== null ? $this->barrier->runWritable($write) : $write();
    }
}
