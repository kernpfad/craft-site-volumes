<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\tests\unit;

use kernpfad\sitevolumes\services\RestrictionService;
use PHPUnit\Framework\TestCase;

class DefaultVolumeValidationTest extends TestCase
{
    private RestrictionService $service;

    protected function setUp(): void
    {
        $this->service = new RestrictionService();
    }

    public function testNullDefaultIsAlwaysValid(): void
    {
        $proposedDefaults = [10 => null, 20 => null];
        $proposedRestrictions = [1 => [10], 2 => [20]];

        self::assertSame(
            [],
            $this->service->findSitesWithInvalidDefaultVolume($proposedDefaults, $proposedRestrictions),
        );
    }

    public function testDefaultIsInvalidWhenVolumeIsRestrictedAwayFromSite(): void
    {
        $proposedDefaults = [20 => 1];
        $proposedRestrictions = [1 => [10], 2 => []];

        self::assertSame(
            [20],
            $this->service->findSitesWithInvalidDefaultVolume($proposedDefaults, $proposedRestrictions),
        );
    }

    public function testDefaultIsValidWhenVolumeExplicitlyAllowsSite(): void
    {
        $proposedDefaults = [10 => 1];
        $proposedRestrictions = [1 => [10], 2 => [20]];

        self::assertSame(
            [],
            $this->service->findSitesWithInvalidDefaultVolume($proposedDefaults, $proposedRestrictions),
        );
    }

    public function testDefaultIsValidWhenVolumeIsUnrestricted(): void
    {
        $proposedDefaults = [20 => 2];
        $proposedRestrictions = [1 => [10], 2 => []];

        self::assertSame(
            [],
            $this->service->findSitesWithInvalidDefaultVolume($proposedDefaults, $proposedRestrictions),
        );
    }
}
