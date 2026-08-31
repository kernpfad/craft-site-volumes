<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\services;

use Craft;
use yii\base\Component;

/**
 * When Craft adds a new site, restricted volumes won't include it yet — and
 * if every volume is restricted away from the new site, Assets would be
 * unusable there. This service copies the primary site's volume access so
 * new sites start with the same picker visibility as the primary site.
 */
class SiteMigrationService extends Component
{
    public function __construct(
        private readonly RestrictionService $restrictions,
        private readonly SiteAccessRule $accessRule = new SiteAccessRule(),
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * Grants a newly created site the same restricted-volume access as the
     * primary site and copies the primary default upload volume when valid.
     */
    public function inheritVolumeAccessFromPrimarySite(int $newSiteId): bool
    {
        $sitesService = Craft::$app->getSites();

        try {
            $primarySite = $sitesService->getPrimarySite();
        } catch (\Throwable) {
            return false;
        }

        if ($primarySite->id === null || $primarySite->id === $newSiteId) {
            return true;
        }

        $updates = $this->buildInheritedRestrictions(
            $this->restrictions->getAllRestrictions(),
            $primarySite->id,
            $newSiteId,
        );

        foreach ($updates as $volumeId => $allowedSiteIds) {
            if (!$this->restrictions->saveAllowedSites($volumeId, $allowedSiteIds)) {
                Craft::warning(
                    "Site Volumes couldn't inherit volume #{$volumeId} access for new site #{$newSiteId}.",
                    __METHOD__,
                );

                return false;
            }
        }

        if ($this->restrictions->getDefaultVolumeId($newSiteId) === null) {
            $primaryDefault = $this->restrictions->getDefaultVolumeId($primarySite->id);

            if (
                $primaryDefault !== null
                && $this->restrictions->resolveVolumeForSite($primaryDefault, $newSiteId)
            ) {
                if (!$this->restrictions->saveDefaultVolume($newSiteId, $primaryDefault)) {
                    Craft::warning(
                        "Site Volumes couldn't copy the primary default upload volume to new site #{$newSiteId}.",
                        __METHOD__,
                    );

                    return false;
                }
            }
        }

        Craft::info(
            "Site Volumes inherited primary-site volume access for new site #{$newSiteId}.",
            __METHOD__,
        );

        return true;
    }

    /**
     * @param array<int, int[]> $restrictions Volume ID => allowed site IDs (only restricted volumes appear).
     * @return array<int, int[]> Volumes whose allow-list changed and should be saved.
     */
    public function buildInheritedRestrictions(array $restrictions, int $primarySiteId, int $newSiteId): array
    {
        $updates = [];

        foreach ($restrictions as $volumeId => $allowedSiteIds) {
            $updated = $this->computeAllowedSiteIdsAfterInherit($allowedSiteIds, $primarySiteId, $newSiteId);

            if ($updated !== $allowedSiteIds) {
                $updates[$volumeId] = $updated;
            }
        }

        return $updates;
    }

    /**
     * @param int[] $allowedSiteIds
     * @return int[]
     */
    public function computeAllowedSiteIdsAfterInherit(array $allowedSiteIds, int $primarySiteId, int $newSiteId): array
    {
        if ($allowedSiteIds === []) {
            return $allowedSiteIds;
        }

        if (!$this->accessRule->isAllowed($allowedSiteIds, $primarySiteId)) {
            return $allowedSiteIds;
        }

        if (in_array($newSiteId, $allowedSiteIds, true)) {
            return $allowedSiteIds;
        }

        $allowedSiteIds[] = $newSiteId;
        sort($allowedSiteIds);

        return $allowedSiteIds;
    }
}
