<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\tests\unit;

use kernpfad\sitevolumes\services\RestrictionService;
use PHPUnit\Framework\TestCase;

class RestrictionServiceTest extends TestCase
{
    private RestrictionService $service;

    protected function setUp(): void
    {
        $this->service = new RestrictionService();
    }

    public function testNoSitesAreStarvedWhenEveryVolumeIsUnrestricted(): void
    {
        $proposed = [1 => [], 2 => []];

        self::assertSame([], $this->service->findSitesWithNoAvailableVolume($proposed, [10, 20]));
    }

    public function testSiteIsStarvedWhenExcludedFromEveryRestrictedVolume(): void
    {
        // Site 20 isn't allowed on either volume - both are restricted, neither includes it.
        $proposed = [1 => [10], 2 => [10]];

        self::assertSame([20], $this->service->findSitesWithNoAvailableVolume($proposed, [10, 20]));
    }

    public function testSiteIsNotStarvedIfAtLeastOneVolumeAllowsIt(): void
    {
        $proposed = [1 => [10], 2 => [20]];

        self::assertSame([], $this->service->findSitesWithNoAvailableVolume($proposed, [10, 20]));
    }

    public function testUnrestrictedVolumeCoversEverySite(): void
    {
        // Volume 1 is restricted away from site 20, but volume 2 is wide open.
        $proposed = [1 => [10], 2 => []];

        self::assertSame([], $this->service->findSitesWithNoAvailableVolume($proposed, [10, 20]));
    }

    public function testNoVolumesAtAllStarvesEverySite(): void
    {
        self::assertSame([10, 20], $this->service->findSitesWithNoAvailableVolume([], [10, 20]));
    }

    public function testMultipleSitesCanBeStarvedAtOnce(): void
    {
        $proposed = [1 => [10]];

        self::assertSame([20, 30], $this->service->findSitesWithNoAvailableVolume($proposed, [10, 20, 30]));
    }
}
