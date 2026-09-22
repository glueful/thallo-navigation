# glueful/thallo-navigation

**Navigation menus as data** for [Thallo](https://thallo.dev) — menu trees stored once,
served headless through a public API, and consumed by themes through the `MenuReader`
contract — packaged as a **capability pack** (V2 rendered-delivery sub-project 1;
see `docs/internal/V2_DESIGN.md`). The `thallo-render` pack consumes menus *optionally*:
`menu('main')` yields `[]` when this pack is disabled.

## What it provides

- **Two tables:** `navigation_menus` (identity + `lock_version` for optimistic tree
  writes) and `navigation_items` (tree nodes: `entry` items as soft references — no
  cross-package FKs — or raw `url` items, with **per-locale label and description maps** and an
  optional Lucide `icon`). An `entry` item with an empty label inherits the page title.
- **Resolution semantics** (`MenuReader::menu(slug, locale)`):

  | Rule | Behavior |
  |---|---|
  | Labels | fallback chain: requested locale → site default → any available |
  | `url` items | served verbatim |
  | `entry` items | resolved to the live public path at read time via `EntryTargetResolver` — slug changes propagate automatically |
  | Non-published targets | item **and its subtree** omitted (`unpublished`, `routeless`, `deleted`, `missing`) — no dead links can ever render |
  | Unknown menu / disabled capability | `null` |

- **`EntryTargetResolver`** (contract added in `thallo-contracts`, implemented by core):
  `resolve(entryUuid, locale)` → `{status: published|unpublished|deleted|missing|routeless,
  path}` — `published` means **addressable** (publication AND route); `routeless` is the
  actionable "assign a route" state; `path` is null for every non-published status.

## HTTP API

Public (rate-limited): `GET /v1/menus/{slug}?locale=en` — the resolved published-only tree.

Admin (capability → `auth` → `content_permission:navigation.manage`), under
`/v1/admin/navigation`: menu CRUD (`GET|POST /menus`, `GET|PUT|DELETE /menus/{slug}`) and
the **atomic whole-tree replace** `PUT /menus/{slug}/items` — the body carries the
`lock_version` from the editor's GET; a stale version is a **409** (reload and retry).
The admin tree read is **locale-aware** (`?locale=`): `target_status`/`target_url` are
resolved for that locale, so editor badges always match the locale on screen. Tree
payloads are validated recursively: kinds, `http(s)://` or site-relative URLs, labels
≤ 200 chars, descriptions ≤ 500 chars, icons as Lucide names, depth ≤ 6, ≤ 500 items; `missing`/`deleted` targets are 422s while
`unpublished`/`routeless` are allowed (editors build menus while content is in draft).
`MenuUpdated` is dispatched on every mutation — the render-cache purge seam.

## Admin SPA

**Navigation** page under the sidebar's Site group (`/navigation`, capability-gated): menu
list plus a tree editor — per-locale labels and descriptions via a locale switcher (which also
drives the target badges), entry picker with `unpublished`/`routeless`/`deleted`/`missing`
badges, URL items, an icon picker per item, drag-and-drop reordering and nesting, and
up/down/indent/outdent buttons. Saving replaces the whole tree under `lock_version`.

## Install / disable

The pack ships with Thallo: `glueful/thallo-core` requires it at the same version and the project's
`config/serviceproviders.php` loads its provider, so there is nothing to install or enable per pack.
Its tables are created by `php glueful migrate:run` with the rest of the schema. An operator turns
the capability off or on in the admin under **Extensions › Capabilities** (stored system-wide; it
overrides the deploy-time `thallo.capabilities` config map). When it is off, the routes 404,
`MenuReader` resolves null, and core and every other pack boot unchanged.

## Out of scope

Menu-item visibility rules (auth-based), per-item badges, theme menu-region mapping beyond
slugs, and per-item target/rel attributes.

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-navigation/`.
