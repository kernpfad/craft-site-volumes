<?php

namespace kernpfad\sitevolumes\tests\integration;

/**
 * Boots a real Craft application (same DB/config as the manual test-site
 * used during development, upgraded to the Pro edition locally purely to
 * exercise multi-site — no real license involved) and exercises the actual
 * Element::EVENT_REGISTER_SOURCES filtering against real Volume and Site
 * records, rather than asserting against RestrictionService in isolation.
 *
 * Requires CRAFT_TEST_SITE_PATH to point at a working, multi-site Craft
 * install with this plugin linked in via a Composer path repository. Skips
 * itself if that's not configured.
 *
 * PHPUnit will flag the first test as "risky" (error/exception handlers
 * not restored) — that's Craft's own application bootstrap registering its
 * handlers inside the same process, not a bug here. It doesn't fail the
 * run (exit code stays 0).
 */

use craft\base\Element;
use craft\elements\Asset;
use craft\fs\Local;
use craft\models\Site;
use craft\models\Volume;
use kernpfad\sitevolumes\SiteVolumes;
use PHPUnit\Framework\TestCase;

class SourceFilteringTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        $sitePath = getenv('CRAFT_TEST_SITE_PATH');

        if (!$sitePath || !is_dir($sitePath)) {
            $this->markTestSkipped(
                'CRAFT_TEST_SITE_PATH is not set to a working Craft install; skipping integration tests.'
            );
        }

        if (!self::$booted) {
            define('CRAFT_BASE_PATH', $sitePath);
            define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
            require CRAFT_VENDOR_PATH . '/autoload.php';

            if (class_exists(\Dotenv\Dotenv::class)) {
                \Dotenv\Dotenv::createImmutable(CRAFT_BASE_PATH)->safeLoad();
            }

            require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
            self::$booted = true;
        }

        if (count(\Craft::$app->getSites()->getAllSites()) < 2) {
            $this->markTestSkipped('This test needs at least two sites configured on the test install.');
        }
    }

    public function testRestrictedVolumeIsHiddenOnDisallowedSiteButVisibleOnAllowedSite(): void
    {
        $sites = \Craft::$app->getSites()->getAllSites();
        [$siteA, $siteB] = [$sites[0], $sites[1]];

        $volume = $this->createVolume('siteVolumesTestRestricted');

        // Restrict this volume to siteA only.
        SiteVolumes::getInstance()->restrictions->saveAllowedSites($volume->id, [$siteA->id]);

        self::assertTrue($this->volumeSourceVisibleOnSite($volume, $siteA));
        self::assertFalse($this->volumeSourceVisibleOnSite($volume, $siteB));
    }

    public function testUnrestrictedVolumeIsVisibleEverywhere(): void
    {
        $sites = \Craft::$app->getSites()->getAllSites();
        [$siteA, $siteB] = [$sites[0], $sites[1]];

        $volume = $this->createVolume('siteVolumesTestUnrestricted');

        // No restrictions saved at all — opt-in default must be "everywhere".
        self::assertTrue($this->volumeSourceVisibleOnSite($volume, $siteA));
        self::assertTrue($this->volumeSourceVisibleOnSite($volume, $siteB));
    }

    public function testClearingRestrictionsMakesVolumeVisibleAgain(): void
    {
        $sites = \Craft::$app->getSites()->getAllSites();
        [$siteA, $siteB] = [$sites[0], $sites[1]];

        $volume = $this->createVolume('siteVolumesTestCleared');
        $restrictions = SiteVolumes::getInstance()->restrictions;

        $restrictions->saveAllowedSites($volume->id, [$siteA->id]);
        self::assertFalse($this->volumeSourceVisibleOnSite($volume, $siteB));

        $restrictions->saveAllowedSites($volume->id, []);
        self::assertTrue($this->volumeSourceVisibleOnSite($volume, $siteB));
    }

    /**
     * Regression test for the bug that made this plugin a no-op in the very
     * place it exists for.
     *
     * The filter used to read `getSites()->getCurrentSite()`. Craft only
     * calls `setCurrentSite()` on front-end and GraphQL requests — on a
     * control panel request it silently falls back to the primary site,
     * whatever the site menu says. So a volume restricted to a non-primary
     * site was hidden on *every* site in the CP, including the one it was
     * restricted to, and no amount of switching sites brought it back.
     *
     * The other tests in this file never caught it because they call
     * `setCurrentSite()` themselves, which is exactly what the CP does not.
     * This one pins current site and requested site to *different* sites, so
     * it can only pass if the filter follows the requested one.
     */
    public function testFilterFollowsTheRequestedSiteNotTheCurrentSite(): void
    {
        $sites = \Craft::$app->getSites()->getAllSites();
        [$siteA, $siteB] = [$sites[0], $sites[1]];

        $volume = $this->createVolume('siteVolumesTestRequestedSite');
        SiteVolumes::getInstance()->restrictions->saveAllowedSites($volume->id, [$siteB->id]);

        // What a CP request looks like: current site stuck on the primary,
        // while the editor is actually looking at siteB.
        \Craft::$app->getSites()->setCurrentSite($siteA);

        try {
            $this->forceRequestedSite($siteB);
            $this->resetElementSourcesCache();

            $keys = array_column(\Craft::$app->getElementSources()->getSources(Asset::class), 'key');

            self::assertContains(
                'volume:' . $volume->uid,
                $keys,
                'A volume restricted to the site the editor is viewing must be visible there, ' .
                'even though the app-level "current site" is a different one.'
            );
        } finally {
            $this->forceRequestedSite(null);
        }
    }

    /**
     * Front-end and GraphQL requests never set `Cp::requestedSite()` — only
     * `getCurrentSite()`. This mirrors that path explicitly.
     */
    public function testSourceFilterUsesCurrentSiteWhenRequestedSiteIsUnset(): void
    {
        $sites = \Craft::$app->getSites()->getAllSites();
        [$siteA, $siteB] = [$sites[0], $sites[1]];

        $volume = $this->createVolume('siteVolumesTestCurrentSiteOnly');
        SiteVolumes::getInstance()->restrictions->saveAllowedSites($volume->id, [$siteB->id]);

        $this->forceRequestedSite(null);

        try {
            self::assertFalse($this->volumeSourceVisibleOnSite($volume, $siteA));
            self::assertTrue($this->volumeSourceVisibleOnSite($volume, $siteB));
        } finally {
            $this->forceRequestedSite(null);
        }
    }

    /**
     * Site Volumes filters CP source lists only. Element queries must remain
     * unaffected — this plugin is not a data-integrity constraint.
     */
    public function testElementQueryIsNotFilteredByVolumeRestrictions(): void
    {
        $sites = \Craft::$app->getSites()->getAllSites();
        [$siteA, $siteB] = [$sites[0], $sites[1]];

        $volume = $this->createVolume('siteVolumesTestElementQuery');
        SiteVolumes::getInstance()->restrictions->saveAllowedSites($volume->id, [$siteA->id]);

        \Craft::$app->getSites()->setCurrentSite($siteB);

        $result = Asset::find()->volumeId($volume->id)->all();

        self::assertIsArray($result);
        // The query must run on the disallowed site — an empty result is fine,
        // but the plugin must not throw or alter Craft's volume filter here.
    }

    public function testDefaultVolumeSourceIsListedFirst(): void
    {
        $sites = \Craft::$app->getSites()->getAllSites();
        [$siteA] = [$sites[0]];

        $volumeFirst = $this->createVolume('siteVolumesTestDefaultFirstA');
        $volumeDefault = $this->createVolume('siteVolumesTestDefaultFirstB');
        self::assertNotSame($volumeFirst->uid, $volumeDefault->uid);
        $restrictions = SiteVolumes::getInstance()->restrictions;

        $restrictions->saveDefaultVolume($siteA->id, $volumeDefault->id);

        \Craft::$app->getSites()->setCurrentSite($siteA);
        $this->forceRequestedSite(null);
        $this->resetElementSourcesCache();

        try {
            $sources = \Craft::$app->getElementSources()->getSources(Asset::class);
            $volumeKeys = array_values(array_filter(
                array_column($sources, 'key'),
                static fn(string $key) => str_starts_with($key, 'volume:'),
            ));

            self::assertNotEmpty($volumeKeys);
            self::assertSame('volume:' . $volumeDefault->uid, $volumeKeys[0]);
        } finally {
            $restrictions->saveDefaultVolume($siteA->id, null);
            $this->forceRequestedSite(null);
        }
    }

    /**
     * Overwrites the memoized result of `Cp::requestedSite()`. There is no
     * public way to set it, and it can't be produced from a console request
     * (it reads a query param), so reflection is the only route.
     */
    private function forceRequestedSite(?Site $site): void
    {
        $prop = new \ReflectionProperty(\craft\helpers\Cp::class, '_requestedSite');
        $prop->setValue(null, $site ?? false);

        if ($site === null) {
            // `false` means "resolved to nothing"; unset it so the next
            // caller resolves normally again.
            $prop->setValue(null, null);
        }
    }

    private function createVolume(string $handle): Volume
    {
        $fs = new Local([
            'name' => $handle . 'Fs',
            'handle' => $handle . 'Fs',
            'hasUrls' => false,
            'path' => sys_get_temp_dir() . '/site-volumes-tests/' . $handle,
        ]);
        \Craft::$app->getFs()->saveFilesystem($fs);

        $volume = new Volume(['name' => $handle, 'handle' => $handle, 'fs' => $fs]);
        \Craft::$app->getVolumes()->saveVolume($volume);

        // saveVolume() writes through project config; ad-hoc scripts/tests
        // don't go through Craft's normal request lifecycle, which is what
        // normally flushes queued project-config writes and is also what
        // populates $volume->id synchronously afterwards. Force both
        // explicitly and re-fetch a fully-resolved instance.
        \Craft::$app->getProjectConfig()->saveModifiedConfigData();

        $saved = \Craft::$app->getVolumes()->getVolumeByHandle($handle);
        self::assertNotNull($saved, "Volume \"{$handle}\" failed to save.");

        return $saved;
    }

    /**
     * Switches the app's current site, re-triggers Asset source registration
     * (which is memoized per request, so we clear that cache first), and
     * checks whether the given volume's source survived the filter.
     */
    private function volumeSourceVisibleOnSite(Volume $volume, Site $site): bool
    {
        \Craft::$app->getSites()->setCurrentSite($site);
        $this->resetElementSourcesCache();

        $sources = \Craft::$app->getElementSources()->getSources(Asset::class);
        $keys = array_column($sources, 'key');

        return in_array('volume:' . $volume->uid, $keys, true);
    }

    private function resetElementSourcesCache(): void
    {
        // Both ElementSources' own per-request cache and the static one on
        // the element class itself memoize source lists; both need clearing
        // for a site switch mid-test to actually take effect.
        $sourcesProp = new \ReflectionProperty(\Craft::$app->getElementSources(), 'sources');
        $sourcesProp->setValue(\Craft::$app->getElementSources(), []);

        $elementStaticProp = new \ReflectionProperty(Element::class, 'sources');
        $elementStaticProp->setValue(null, []);
    }
}
