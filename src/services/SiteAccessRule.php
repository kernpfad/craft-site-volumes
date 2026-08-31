<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\services;

/**
 * The one rule this whole plugin hinges on, kept free of Craft/DB
 * dependencies so it's trivially unit-testable: no sites configured means
 * unrestricted (opt-in per volume), not "nobody can use this volume".
 * Getting this backwards would silently hide every existing volume the
 * moment the plugin is installed.
 */
class SiteAccessRule
{
    /**
     * @param int[] $allowedSiteIds
     */
    public function isAllowed(array $allowedSiteIds, int $siteId): bool
    {
        return $allowedSiteIds === [] || in_array($siteId, $allowedSiteIds, true);
    }
}
