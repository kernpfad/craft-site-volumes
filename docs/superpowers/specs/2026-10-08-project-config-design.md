# SV-09: Project Config for Site Volumes

**Status:** Draft for review  
**Date:** 2026-10-08  
**Depends on:** —  
**Enables:** SV-10 (query restrictions setting should sync via Project Config)

## Problem

Volume↔site restrictions and per-site default upload volumes live only in plugin DB tables (`sitevolumes_volumesites`, `sitevolumes_sitedefaults`). Multi-environment Craft installs cannot version or deploy that configuration via `config/project/`. Admins must re-enter the matrix on every environment.

## Goal

Store restrictions and default upload volumes in Craft Project Config so Dev → Staging → Prod stay aligned. The database remains the runtime store; YAML is the deploy-time source of truth.

## Non-goals

- Opt-in/out of Project Config itself (Craft discourages this).
- Folder-level mappings or asset content in Project Config.
- Changing the opt-in semantics: a volume with no restriction entry remains available on every site.
- Moving filesystem paths, URLs, or propagation into this plugin.

## Design decision

Use a dedicated Project Config subtree under the plugin handle (`site-volumes`), not a flat plugin Settings model for the matrix. The matrix is multi-record relationship data (volume × sites, site → default volume). That matches Craft’s “configurable components” pattern (`onAdd` / `onUpdate` / `onRemove` with `{uid}` paths).

A small Settings model may still be introduced for SV-10’s `queryRestrictionMode` flag; that lives under Craft’s normal plugin settings path. This spec covers only the matrix + defaults subtree.

## YAML shape

```yaml
site-volumes:
  volumes:
    # Only restricted volumes appear. Key = volume UID.
    a1b2c3d4-e5f6-7890-abcd-ef1234567890:
      allowedSites:
        - 11111111-2222-3333-4444-555555555555   # site UID
        - 66666666-7777-8888-9999-000000000000
  defaults:
    # Key = site UID, value = volume UID
    11111111-2222-3333-4444-555555555555: a1b2c3d4-e5f6-7890-abcd-ef1234567890
```

Rules:

- References are always **UIDs**, never integer IDs.
- Omitting a volume key (or removing it) means unrestricted.
- An empty `allowedSites` list is treated the same as removing the volume key (unrestricted); writers should remove the key rather than persist empty arrays.
- Omitting a site under `defaults` means no default upload volume for that site.

## Data flow

### Express (write intent)

1. CP save (`SettingsController::actionSave`) or public service API validates the proposed configuration (SV-03 starved sites, SV-04 invalid defaults).
2. On success, the service writes the full intended subtree to Project Config (`set` / `remove`), not directly to ActiveRecord.
3. New-site inherit (SV-07) also expresses changes through Project Config.

### Apply (update database)

Listeners registered in `SiteVolumes::init()`:

- `site-volumes.volumes.{uid}` → add/update/remove rows in `sitevolumes_volumesites`
- `site-volumes.defaults.{uid}` → add/update/remove row in `sitevolumes_sitedefaults`

Handlers:

- Resolve volume/site UIDs to IDs via Craft/Db helpers.
- Unknown UID (volume or site deleted elsewhere): log a warning and skip that reference; do not fatal the whole apply.
- Must be idempotent and safe when Craft replays config.

### Public API

`RestrictionService::saveAllowedSites()` and `saveDefaultVolume()` become the express path (Project Config write). Database mutation moves into apply handlers (or a private apply method only called from those handlers / rebuild). Callers keep the same method names; behaviour becomes “config then DB via events”.

Guard against double-writes when an apply handler would re-enter express logic (mute or detect `ProjectConfig::isApplyingExternalChanges` as Craft docs recommend).

## Validation

Unchanged rules, evaluated **before** any Project Config write:

1. No site may be left with zero available volumes.
2. A site’s default upload volume must be allowed on that site.
3. Referenced volume IDs from the form must still exist.

Read-only Project Config (typical production): reject save with a clear flash, same class of UX as other Craft settings.

## Migration / upgrade

On upgrade to the release that introduces SV-09:

1. Rebuild `site-volumes` Project Config from the current DB tables (one-shot migrate or `rebuildProjectConfig`-style contribution).
2. Fresh installs start with an empty subtree (all volumes unrestricted, no defaults) — valid.
3. Existing installs must keep the same effective allow-lists and defaults after upgrade + `project-config/apply` on another environment that receives the new YAML.

Foreign keys and table schema stay as they are; no content migration beyond mirroring into YAML.

## Control panel

- Settings page UX unchanged (matrix + default row).
- Permission `siteVolumes:manage` unchanged.
- When Project Config is read-only, Save fails with an explanatory message; the matrix remains visible.

## Components

| Unit | Responsibility |
|------|----------------|
| `ProjectConfigService` (new) or methods on `RestrictionService` | Express writes; apply handlers; rebuild-from-DB |
| `RestrictionService` | Keep resolution/query APIs; route saves through PC |
| `SettingsController` | Validate, then call service (no direct record writes) |
| `SiteMigrationService` | Inherit via express path so YAML updates |
| `SiteVolumes::init()` | Register PC path listeners |

Prefer a focused `ProjectConfigService` if `RestrictionService` would otherwise mix resolution, validation, and YAML sync too heavily. Resolution and validation stay on `RestrictionService` / `SiteAccessRule`.

## Error handling

- Validation failure: flash + redirect; no PC write.
- Apply with missing UID: warning log, skip bad reference.
- Apply DB failure: log warning; let Craft’s apply surface the failure rather than silently succeeding.
- Never log secrets or PII (none expected here).

## Testing

- **Unit:** UID ↔ ID mapping helpers; “empty allowedSites removes key”; rebuild payload shape from fixture restrictions/defaults; inherit updates express the right PC paths (can mock Project Config).
- **Integration:** save via service → assert Project Config values; wipe plugin tables → `project-config/apply` (or invoke handlers) → assert `isAllowedForSite` / default volume match; upgrade-path rebuild leaves effective access unchanged.
- Keep existing source-filter and validation tests green; adapt any that called `saveAllowedSites` expecting immediate DB-only writes (they should still observe correct DB state after the save returns, because Craft applies PC in-process).

## Documentation

- README: note that restrictions/defaults are in Project Config; deploy via normal Craft PC workflow.
- CHANGELOG: SV-09 entry under next version heading.
- Limitations: still no GraphQL/query enforcement until SV-10.

## Acceptance criteria

| ID | Criterion |
|----|-----------|
| PC-1 | Saving the matrix in a writable PC environment writes `site-volumes.volumes.*` / `defaults.*` with UIDs |
| PC-2 | Applying project config on an environment with empty plugin tables restores the same effective restrictions and defaults |
| PC-3 | Removing a volume’s restriction (unrestricted) removes its PC key; volume is available everywhere |
| PC-4 | Existing installs keep effective configuration after upgrade rebuild |
| PC-5 | SV-03 / SV-04 validation still blocks invalid saves before PC write |
| PC-6 | New-site inherit updates Project Config and DB consistently |
| PC-7 | Read-only PC rejects CP save with a clear error |
| PC-8 | Unit + integration coverage as above; `composer check` stays green |

## Implementation order (within SV-09)

1. Apply handlers + rebuild-from-DB.
2. Switch express path of `saveAllowedSites` / `saveDefaultVolume`.
3. Wire controller + site migration.
4. Upgrade migration / rebuild hook.
5. Docs + tests.

## Out of scope follow-ups

- SV-10 query-enforcement flag (separate Settings model / spec).
- CLI audit command.
- Matrix UX bulk actions.
