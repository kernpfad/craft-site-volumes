<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\tests\unit;

use kernpfad\sitevolumes\events\ResolveDefaultVolumeForSiteEvent;
use kernpfad\sitevolumes\events\ResolveVolumeForSiteEvent;
use kernpfad\sitevolumes\services\RestrictionService;
use PHPUnit\Framework\TestCase;
use yii\base\Event;

class ResolveVolumeEventsTest extends TestCase
{
    protected function tearDown(): void
    {
        Event::offAll();
        parent::tearDown();
    }

    public function testResolveVolumeForSiteEventCanOverrideDenial(): void
    {
        $service = new RestrictionService();
        Event::on(
            RestrictionService::class,
            RestrictionService::EVENT_RESOLVE_VOLUME_FOR_SITE,
            static function(ResolveVolumeForSiteEvent $event): void {
                $event->isAllowed = true;
            },
        );

        self::assertTrue($service->resolveVolumeForSite(5, 10, [20]));
    }

    public function testResolveVolumeForSiteEventCanOverrideAllowance(): void
    {
        $service = new RestrictionService();
        Event::on(
            RestrictionService::class,
            RestrictionService::EVENT_RESOLVE_VOLUME_FOR_SITE,
            static function(ResolveVolumeForSiteEvent $event): void {
                $event->isAllowed = false;
            },
        );

        self::assertFalse($service->resolveVolumeForSite(5, 10, [10]));
    }

    public function testResolveDefaultVolumeForSiteEventCanOverrideVolume(): void
    {
        $service = new class() extends RestrictionService {
            public function getDefaultVolumeId(int $siteId): int
            {
                return 1;
            }
        };

        Event::on(
            RestrictionService::class,
            RestrictionService::EVENT_RESOLVE_DEFAULT_VOLUME_FOR_SITE,
            static function(ResolveDefaultVolumeForSiteEvent $event): void {
                $event->volumeId = 99;
            },
        );

        self::assertSame(99, $service->resolveDefaultVolumeForSite(10));
    }
}
