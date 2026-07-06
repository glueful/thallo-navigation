# glueful/lemma-navigation

**Navigation menus as data** for [Lemma](https://getlemma.dev) — menu trees stored once,
served headless through a public API, and consumed by themes through the `MenuReader`
contract — packaged as a **removable capability pack** (V2 rendered-delivery sub-project 1;
see `docs/V2_DESIGN.md`). The future `lemma-render` pack consumes menus *optionally*:
`menu('main')` yields `[]` when this pack is absent or disabled.

## What it provides

- **Two tables:** `navigation_menus` (identity + `lock_version` for optimistic tree
  writes) and `navigation_items` (tree nodes: `entry` items as soft references — no
  cross-package FKs — or raw `url` items, with **per-locale label maps**).
- **Resolution semantics** (`MenuReader::menu(slug, locale)`):

  | Rule | Behavior |
  |---|---|
  | Labels | fallback chain: requested locale → site default → any available |
  | `url` items | served verbatim |
  | `entry` items | resolved to the live public path at read time via `EntryTargetResolver` — slug changes propagate automatically |
  | Non-published targets | item **and its subtree** omitted (`unpublished`, `routeless`, `deleted`, `missing`) — no dead links can ever render |
  | Unknown menu / disabled capability | `null` — indistinguishable from "pack absent" |

- **`EntryTargetResolver`** (contract added in `lemma-contracts`, implemented by core):
  `resolve(entryUuid, locale)` → `{status: published|unpublished|deleted|missing|routeless,
  path}` — `published` means **addressable** (publication AND route); `routeless` is the
  actionable "assign a route" state; `path` is null for every non-published status.

## HTTP API

Public (rate-limited): `GET /v1/menus/{slug}?locale=en` — the resolved published-only tree.

Admin (capability → `auth` → `lemma_permission:navigation.manage`), under
`/v1/admin/navigation`: menu CRUD (`GET|POST /menus`, `GET|PUT|DELETE /menus/{slug}`) and
the **atomic whole-tree replace** `PUT /menus/{slug}/items` — the body carries the
`lock_version` from the editor's GET; a stale version is a **409** (reload and retry).
The admin tree read is **locale-aware** (`?locale=`): `target_status`/`target_url` are
resolved for that locale, so editor badges always match the locale on screen. Tree
payloads are validated recursively: kinds, `http(s)://` or site-relative URLs, labels
≤ 200 chars, depth ≤ 6, ≤ 500 items; `missing`/`deleted` targets are 422s while
`unpublished`/`routeless` are allowed (editors build menus while content is in draft).
`MenuUpdated` is dispatched on every mutation — the render-cache purge seam.

## Admin SPA

Settings-level **Navigation** page (capability-gated): menu list plus a tree editor —
per-locale labels via a locale switcher (which also drives the target badges), entry
picker with `unpublished`/`routeless`/`deleted`/`missing` badges, URL items, and
up/down/indent/outdent reordering. Saving replaces the whole tree under `lock_version`.

## Install / remove

Bundled by default in the Lemma create-project template. Existing app:
`composer require glueful/lemma-navigation`, `./lemma extensions:enable lemma-navigation`,
`./lemma migrate:run`. Disable via the switchboard
(`config/lemma.php: 'capabilities' => ['lemma.navigation' => false]`) or remove entirely —
routes 404, `MenuReader` resolves null, core and every other pack boot unchanged.

## Out of scope (v1)

Menu-item visibility rules (auth-based), mega-menu metadata (icons, badges), theme
menu-region mapping beyond slugs, drag-drop editing polish, and per-item target/rel
attributes — all can layer onto the json columns without schema breaks.
