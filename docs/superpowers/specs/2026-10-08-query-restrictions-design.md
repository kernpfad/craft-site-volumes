# SV-10: Opt-in Asset Query Restrictions (Listing Mode)

**Status:** Draft for review  
**Date:** 2026-10-08  
**Depends on:** SV-09 (Project Config) recommended first so the new setting syncs cleanly; can ship after or in the same minor if Settings model is introduced with PC support  
**Related:** README Limitations today document that GraphQL / `Asset::find()` are not filtered

## Problem

Site Volumes only filters Craft element **sources** (`Element::EVENT_REGISTER_SOURCES`). Front-end templates and GraphQL can still list assets from volumes that the control panel hides for the current site. Teams that want listing behaviour to match the picker have no switch for that.

## Goal

Add an **opt-in** setting that applies the same volume↔site allow rules to `Asset` element queries used for **listing** (including GraphQL `assets`), without turning the plugin into access control and without breaking existing related-asset renders.

## Non-goals

- Replacing Asset Vault or implementing real authorization.
- Blocking saves of relations that point at “foreign” volumes (possible later soft-warning feature).
- Filtering non-asset queries.
- Changing source-list behaviour (already correct).
- **Strict mode** in v1 (hiding assets loaded by explicit `id` / `uid`). That is a follow-up mode if needed.

## Design decision: listing-only (v1)

**Chosen:** Listing enforce only.

| Mode | Behaviour | v1 |
|------|-----------|----|
| Off (default) | Queries unchanged — today’s behaviour | Yes (default) |
| Listing | Restrict volume listing / open-ended asset queries; identity lookups by `id`/`uid` still return the asset | Yes |
| Strict | Also hide identity lookups | No (document as future) |

Rationale: matches the product as a picker/scoping helper; avoids breaking templates and already-related assets; strict mode implies security it cannot deliver (filesystem URLs, transforms, direct files remain reachable).

## Setting

Introduce a plugin Settings model (Craft `hasCpSettings` / settings model pattern) with:

```text
enforceQueryRestrictions: bool = false
```

- Default **false** — no breaking change on upgrade.
- Overridable via `config/site-volumes.php` like other Craft plugin settings.
- Stored in Project Config through Craft’s normal plugin-settings path (complements SV-09’s custom subtree for the matrix).
- CP: checkbox on the existing Site Volumes page (or plugin settings) with short warning copy that this is listing scoping, not access control.

Future (out of scope for this spec): enum/string values such as `off` | `listing` | `strict` if strict is added; until then a boolean is enough. If we anticipate strict soon, prefer a string setting `queryRestrictionMode: off|listing` now to avoid a second migration — **recommendation: string mode with values `off` and `listing` only**, default `off`, so `strict` can be added later without renaming.

**Decision locked for this spec:** use string setting:

```text
queryRestrictionMode: 'off' | 'listing'   // default 'off'
```

Boolean aliases are not exposed.

## When the filter runs

Only when `queryRestrictionMode === 'listing'`.

| Request context | Apply? | Site resolution |
|-----------------|--------|-----------------|
| Front-end web | Yes | `Sites::getCurrentSite()` |
| GraphQL | Yes | Current GraphQL / `getCurrentSite()` (same as Craft’s asset queries) |
| Control panel | **No** | Sources already enforce picker visibility; CP admins must keep seeing related assets |
| Console | **No** by default | Unreliable site context; document escape if needed later |

## Query algorithm

Hook: `Event::on(AssetQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, ...)`.

1. If mode is `off` → return.
2. If request is CP or console (per table) → return.
3. Fire `EVENT_BEFORE_ENFORCE_QUERY_RESTRICTIONS` (new). If `$event->isEnforced === false` → return. Allow a query-level bypass (behaviour flag or event) for modules that need unfiltered listings.
4. Resolve site id; if unavailable → return.
5. Build the set of **allowed volume IDs** for that site using existing resolution (`getAllRestrictions` + `resolveVolumeForSite` / `SiteAccessRule`), so SV-08 listeners still apply.
6. Inspect the `AssetQuery`:
   - If **identity lookup**: `id` or `uid` is set to a concrete value (not null/empty) → **do not modify** the query (listing mode).
   - If `volumeId` is `:empty:` → leave unchanged.
   - If `volumeId` is empty/unset → set `volumeId` to the allowed ID list (may be all volumes when nothing is restricted).
   - If `volumeId` is set → replace with the intersection of requested IDs and allowed IDs; empty intersection → `volumeId = []` so the query returns no rows (Craft’s empty-list behaviour), without throwing.

Do not throw on disallowed volumes; empty result is enough.

## Events / extension

| Event | Purpose |
|-------|---------|
| Existing `EVENT_RESOLVE_VOLUME_FOR_SITE` | Still drives membership in the allowed set |
| New `EVENT_BEFORE_ENFORCE_QUERY_RESTRICTIONS` | `$query`, `$siteId`, `$isEnforced` (mutable) — bypass or adjust before volume IDs are applied |

No change to `EVENT_RESOLVE_DEFAULT_VOLUME_FOR_SITE` for this feature.

## Components

| Unit | Responsibility |
|------|----------------|
| `models/Settings` | `queryRestrictionMode` validation (`off`\|`listing`) |
| `services/QueryRestrictionService` (new, small) | Context checks + apply intersection to `AssetQuery` |
| `RestrictionService` | Allowed-volume set only (reuse) |
| `SiteVolumes::init()` | Register `EVENT_BEFORE_PREPARE` when plugin boots |
| Settings UI | Mode control + help text |
| README | Replace hard “not filtered” limitation with mode documentation |

Keep `QueryRestrictionService` free of DB writes; it only reads restrictions and mutates the query.

## Error handling

- Invalid mode in config → fall back to `off` and log a warning once.
- Missing site context → skip enforcement (fail open for listing mode), consistent with “don’t break console/odd contexts”.
- Never throw from the prepare hook for restriction reasons.

## Testing

- **Unit:** intersection helpers; identity-lookup detection; mode `off` no-op; event bypass.
- **Integration (mode `listing`):**
  - Front-end current site: `Asset::find()->all()` excludes assets in volumes disallowed for that site.
  - Explicit `volumeId` of a disallowed volume → empty.
  - `Asset::find()->id($disallowedAssetId)` → still finds the asset.
  - GraphQL `assets` listing respects the same rules (if the integration harness can issue GQL; otherwise document PHP-level AssetQuery coverage as sufficient for v1 and add GQL when the test image allows).
- **Integration (mode `off`):** existing tests remain valid — queries unrestricted.
- **CP:** prepare hook does not alter Asset queries during a simulated CP request (request type / `Craft::$app->getRequest()->getIsCpRequest()`).

## Documentation

- README Limitations: describe `queryRestrictionMode`; state clearly this is not access control; strict mode not available yet.
- CHANGELOG: SV-10 entry.
- Compatibility: still orthogonal to Volume Copy and Asset Vault.

## Acceptance criteria

| ID | Criterion |
|----|-----------|
| QF-1 | Default mode `off`: asset queries behave as today |
| QF-2 | Mode `listing` on front-end: open-ended `Asset::find()` only returns assets from allowed volumes for the current site |
| QF-3 | Mode `listing`: GraphQL listing equivalent (or AssetQuery coverage documenting the same code path) |
| QF-4 | Mode `listing`: explicit disallowed `volumeId` → empty result |
| QF-5 | Mode `listing`: `id` / `uid` lookup for an asset in a disallowed volume still returns it |
| QF-6 | CP requests are not query-filtered by this hook |
| QF-7 | Bypass event can disable enforcement for a given query |
| QF-8 | README/CHANGELOG updated; `composer check` green |

## Implementation order (within SV-10)

1. Settings model + default `off` + CP control.
2. `QueryRestrictionService` + `EVENT_BEFORE_PREPARE` wiring.
3. Bypass event.
4. Tests.
5. Docs.

## Follow-ups (explicitly out of scope)

- `strict` mode (filter identity lookups too).
- Soft CP warning when an entry relates to an asset whose volume is disallowed for the entry’s site.
- Console opt-in enforcement.
- Audit CLI for “relations that would be hidden under listing/strict”.

## Relationship to SV-09

Ship SV-09 first when possible so matrix data and this setting both deploy via Project Config. If SV-10 must land alone, Craft’s built-in plugin settings PC sync is still enough for `queryRestrictionMode`; the matrix remains DB-only until SV-09.
