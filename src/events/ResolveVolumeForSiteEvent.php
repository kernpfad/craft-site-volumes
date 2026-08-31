<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\events;

use yii\base\Event;

class ResolveVolumeForSiteEvent extends Event
{
    public function __construct(
        public int $volumeId,
        public int $siteId,
        public bool $isAllowed,
    ) {
    }
}
