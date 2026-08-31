<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\tests\unit;

use kernpfad\sitevolumes\services\RestrictionService;
use kernpfad\sitevolumes\services\SiteMigrationService;
use PHPUnit\Framework\TestCase;

class SiteMigrationServiceTest extends TestCase
{
    private SiteMigrationService $service;

    protected function setUp(): void
    {
        $this->service = new SiteMigrationService(new RestrictionService());
    }

    public function testUnrestrictedVolumeIsUnchanged(): void
    {
        self::assertSame(
            [],
            $this->service->computeAllowedSiteIdsAfterInherit([], 10, 20),
        );
    }

    public function testNewSiteIsAddedWhenPrimaryHasAccess(): void
    {
        self::assertSame(
            [10, 20],
            $this->service->computeAllowedSiteIdsAfterInherit([10], 10, 20),
        );
    }

    public function testNewSiteIsNotAddedWhenPrimaryLacksAccess(): void
    {
        self::assertSame(
            [20],
            $this->service->computeAllowedSiteIdsAfterInherit([20], 10, 30),
        );
    }

    public function testNewSiteIsNotDuplicated(): void
    {
        self::assertSame(
            [10, 20],
            $this->service->computeAllowedSiteIdsAfterInherit([10, 20], 10, 20),
        );
    }

    public function testBuildInheritedRestrictionsOnlyReturnsChangedVolumes(): void
    {
        $restrictions = [
            1 => [10],
            2 => [20],
            3 => [],
        ];

        self::assertSame(
            [1 => [10, 20]],
            $this->service->buildInheritedRestrictions($restrictions, 10, 20),
        );
    }
}
