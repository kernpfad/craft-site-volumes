<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\events;

use yii\base\Event;

class ResolveDefaultVolumeForSiteEvent extends Event
{
    public function __construct(
        public int $siteId,
        public ?int $volumeId,
    ) {
    }
}
