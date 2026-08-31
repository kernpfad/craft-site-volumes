<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\RegisterElementSourcesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\SiteEvent;
use craft\helpers\Cp;
use craft\services\Sites;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use kernpfad\sitevolumes\services\CompatibilityService;
use kernpfad\sitevolumes\services\RestrictionService;
use kernpfad\sitevolumes\services\SiteAccessRule;
use kernpfad\sitevolumes\services\SiteMigrationService;
use yii\base\Event;

/**
 * @property RestrictionService $restrictions
 * @property CompatibilityService $compatibility
 * @property SiteMigrationService $siteMigration
 */
class SiteVolumes extends Plugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        $this->set('restrictions', function() {
            return new RestrictionService(new SiteAccessRule());
        });

        $this->set('compatibility', function() {
            return new CompatibilityService();
        });

        $this->set('siteMigration', function() {
            return new SiteMigrationService($this->restrictions);
        });

        Event::on(
            Sites::class,
            Sites::EVENT_AFTER_SAVE_SITE,
            function(SiteEvent $event) {
                if (!$event->isNew || $event->site->id === null) {
                    return;
                }

                SiteVolumes::getInstance()?->siteMigration->inheritVolumeAccessFromPrimarySite($event->site->id);
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_SOURCES,
            [$this, 'filterSourcesByCurrentSite']
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['site-volumes'] = 'site-volumes/settings/index';
                $event->rules['site-volumes/save'] = 'site-volumes/settings/save';
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Site Volumes',
                    'permissions' => [
                        'siteVolumes:manage' => [
                            'label' => Craft::t('site-volumes', 'Manage volume/site restrictions'),
                        ],
                    ],
                ];
            }
        );
    }

    public function filterSourcesByCurrentSite(RegisterElementSourcesEvent $event): void
    {
        // Which site the editor is actually looking at — NOT
        // `getSites()->getCurrentSite()`. Craft only ever calls
        // `setCurrentSite()` for front-end and GraphQL requests; on a control
        // panel request it falls back to the primary site, whatever the site
        // menu says. Filtering on that hid every volume not allowed on the
        // primary site everywhere, and never showed it on the sites it was
        // restricted to — the exact opposite of this plugin's purpose.
        //
        // `Cp::requestedSite()` is Craft's own answer to the question: it
        // reads the `site` query param the CP site menu links carry, checks
        // it against the editable sites, and falls back to the current site.
        $site = Cp::requestedSite() ?? Craft::$app->getSites()->getCurrentSite();
        $currentSiteId = $site->id;

        if ($currentSiteId === null) {
            return;
        }

        $assetsService = Craft::$app->getAssets();

        // Read every volume's restrictions in one query up front rather than
        // querying per source: this hook runs on every Assets index load and
        // every asset-field render, once per source, so the per-source
        // lookup it replaces meant a query per volume on each of those.
        $restrictionService = $this->restrictions;
        $restrictions = $restrictionService->getAllRestrictions();

        $event->sources = array_values(array_filter($event->sources, function(array $source) use ($currentSiteId, $assetsService, $restrictions, $restrictionService) {
            $folderId = $source['data']['folder-id'] ?? null;

            if ($folderId === null) {
                return true;
            }

            $folder = $assetsService->getFolderById((int)$folderId);

            if ($folder === null || $folder->volumeId === null) {
                return true;
            }

            // No rows for a volume means unrestricted — the plugin is opt-in
            // per volume, so an absent entry must never hide anything.
            return $restrictionService->resolveVolumeForSite(
                $folder->volumeId,
                $currentSiteId,
                $restrictions[$folder->volumeId] ?? [],
            );
        }));

        $event->sources = $this->prioritizeDefaultVolumeSource(
            $event->sources,
            $restrictionService->resolveDefaultVolumeForSite($currentSiteId),
        );
    }

    /**
     * When a site has a configured default volume, move its source to the
     * front of the list. Assets fields with no explicit upload location pick
     * the first visible source, so this is how SV-04 takes effect without
     * forking Craft's Assets field.
     *
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function prioritizeDefaultVolumeSource(array $sources, ?int $defaultVolumeId): array
    {
        if ($defaultVolumeId === null) {
            return $sources;
        }

        $volume = Craft::$app->getVolumes()->getVolumeById($defaultVolumeId);

        if ($volume === null) {
            return $sources;
        }

        $defaultKey = 'volume:' . $volume->uid;
        $index = null;

        foreach ($sources as $i => $source) {
            if (($source['key'] ?? null) === $defaultKey) {
                $index = $i;

                break;
            }
        }

        if ($index === null || $index === 0) {
            return $sources;
        }

        $defaultSource = $sources[$index];
        $reordered = [$defaultSource];

        foreach ($sources as $i => $source) {
            if ($i !== $index) {
                $reordered[] = $source;
            }
        }

        return $reordered;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('site-volumes', 'Site Volumes');

        return $item;
    }
}
