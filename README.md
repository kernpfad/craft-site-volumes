# Site Volumes

Restrict which asset volumes are available per site in a multi-site Craft install.

Craft scopes sections to sites, but not volumes — every volume is available on every site. This plugin adds that missing per-site scoping.

See [CHANGELOG.md](CHANGELOG.md) for release notes, [CONTRIBUTING.md](CONTRIBUTING.md) for local QA commands, and [SECURITY.md](SECURITY.md) for vulnerability reporting.

## Requirements

- Craft CMS 5.0.0+
- PHP 8.2+

## Installation

```sh
composer require kernpfad/craft-site-volumes
php craft plugin/install site-volumes
```

## What it does

- Adds a **Site Volumes** control panel section: a matrix of volumes × sites where you check the sites each volume belongs to.
- A volume with **no sites checked stays available everywhere**. Restrictions are opt-in per volume, so installing the plugin never silently hides a volume that was already in use.
- Enforces the restriction through Craft's own `Element::EVENT_REGISTER_SOURCES` event — the same mechanism Craft uses to build its volume list — so it applies consistently to the Assets index and to the source list Assets fields fall back on when no explicit sources are configured.
- Resolves every source through its underlying folder to a volume, so volume-level and subfolder-level sources are both filtered.
- Optional **default upload volume per site**: when an Assets field has no explicit upload location, the configured default is moved to the front of the filtered source list for that site.
- **New-site helper**: when Craft adds a site, it automatically inherits the primary site's restricted-volume access (and default upload volume when applicable). Unrestricted volumes already work everywhere.

## Extending via events

Other modules can hook into volume resolution:

```php
use kernpfad\sitevolumes\events\ResolveVolumeForSiteEvent;
use kernpfad\sitevolumes\services\RestrictionService;
use yii\base\Event;

Event::on(
    RestrictionService::class,
    RestrictionService::EVENT_RESOLVE_VOLUME_FOR_SITE,
    function(ResolveVolumeForSiteEvent $event) {
        // $event->volumeId, $event->siteId, $event->isAllowed (mutable)
    }
);
```

`RestrictionService::EVENT_RESOLVE_DEFAULT_VOLUME_FOR_SITE` works the same way for default upload volumes (`$event->volumeId` is mutable) and fires during source-list filtering in the control panel. `EVENT_RESOLVE_VOLUME_FOR_SITE` fires there too, plus once more when a new site is created and inherits the primary site's restricted-volume access — a listener assuming it only runs on that cheap per-render path will also run there.

## Permissions

The settings page requires the **Manage volume/site restrictions** permission, assignable per user group.

## Which URL wins?

This plugin doesn't touch URLs, filesystem paths, or propagation — those aren't per-site concepts for volumes in Craft to begin with:

- **Filesystem path:** a volume has exactly one underlying filesystem. Its files live in one place regardless of which sites you allow it for here. Restricting a volume to fewer sites doesn't move, copy, or duplicate anything on disk — see [Volume Copy](https://github.com/kernpfad/craft-volume-copy) if you actually need per-site copies.
- **Canonical URL:** an asset's URL is derived from its volume's filesystem, not from a site. The same asset has the same URL everywhere; this plugin only controls whether the control panel *offers* the volume as a source on a given site, not what the asset resolves to once selected.
- **Propagation:** Craft propagates *content* (entries, field values) across sites, not volumes. There's nothing to propagate here — a volume simply is or isn't listed as a source for a site.

In short: restricting a volume changes what shows up in the picker. It never changes where a file lives or what URL it resolves to.

A save that would leave a site with **zero available volumes** is rejected outright (every Assets field and the Assets index would come up empty for that site, with no way to select or upload anything) — see [Limitations](#limitations) below for what this restriction does and doesn't cover otherwise.

## Compatibility with other Kernpfad plugins

Site Volumes controls **which volumes appear as CP sources per site**. It does not copy files, change URLs, or enforce access at the API layer.

| Plugin | Handle | Relationship |
|--------|--------|--------------|
| [Volume Copy](https://github.com/kernpfad/craft-volume-copy) | `volume-copy` | Orthogonal — duplicates files per site on disk. Site Volumes only hides or shows volumes in the picker; it never copies anything. Use both when you need per-site file copies *and* per-site source visibility. |
| Asset Vault | `asset-vault` | Orthogonal — access control and encryption for assets. Site Volumes does not replace vault rules; a volume hidden from a site's picker can still hold assets reachable through other paths (see Limitations). |

When Volume Copy or Asset Vault is installed alongside Site Volumes, the settings page shows a notice linking here. There is no functional coupling between the plugins.

## Limitations

This restricts what the control panel *offers* as a source. It is not a data-integrity constraint: an asset that already exists can still be related to content on another site through the API or GraphQL directly.

**GraphQL and element queries** (`Asset::find()`, GraphQL `assets { ... }`) are not filtered by volume/site restrictions. Craft resolves those queries through its own site and volume parameters. The plugin only affects source lists built via `Element::EVENT_REGISTER_SOURCES` (Assets index, Assets field fallback sources). On front-end and GraphQL requests that path uses `getCurrentSite()`; in the control panel it uses `Cp::requestedSite()` (the site menu).

## License

Licensed under the [Craft License Agreement](LICENSE.md).

[Legal notice](https://kernpfad.dev/en/legal-notice) · [Privacy policy](https://kernpfad.dev/en/privacy-policy) · [Terms and conditions](https://kernpfad.dev/en/terms-and-conditions)
