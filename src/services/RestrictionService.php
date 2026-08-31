<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\services;

use Craft;
use craft\db\Query;
use kernpfad\sitevolumes\events\ResolveDefaultVolumeForSiteEvent;
use kernpfad\sitevolumes\events\ResolveVolumeForSiteEvent;
use kernpfad\sitevolumes\records\SiteDefaultRecord;
use kernpfad\sitevolumes\records\VolumeSiteRecord;
use yii\base\Component;
use yii\base\Event;

/**
 * A volume with no rows in {{%sitevolumes_volumesites}} is treated as
 * unrestricted (allowed for every site) — this plugin is opt-in per volume
 * rather than opt-out, so installing it never silently hides existing
 * volumes from sites that were already using them.
 */
class RestrictionService extends Component
{
    public const EVENT_RESOLVE_VOLUME_FOR_SITE = 'resolveVolumeForSite';

    public const EVENT_RESOLVE_DEFAULT_VOLUME_FOR_SITE = 'resolveDefaultVolumeForSite';

    public function __construct(
        private readonly SiteAccessRule $accessRule = new SiteAccessRule(),
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @return int[] Site IDs the volume is explicitly restricted to. Empty means unrestricted.
     */
    public function getAllowedSiteIds(int $volumeId): array
    {
        return (new Query())
            ->select(['siteId'])
            ->from('{{%sitevolumes_volumesites}}')
            ->where(['volumeId' => $volumeId])
            ->column();
    }

    public function isAllowedForSite(int $volumeId, int $siteId): bool
    {
        return $this->resolveVolumeForSite($volumeId, $siteId);
    }

    /**
     * Resolves whether a volume is available for a site, firing
     * {@see self::EVENT_RESOLVE_VOLUME_FOR_SITE} so other modules can override.
     *
     * @param int[]|null $allowedSiteIds Preloaded allow-list; loaded from DB when null.
     */
    public function resolveVolumeForSite(int $volumeId, int $siteId, ?array $allowedSiteIds = null): bool
    {
        $allowedSiteIds ??= $this->getAllowedSiteIds($volumeId);
        $isAllowed = $this->isAllowedForSiteIds($allowedSiteIds, $siteId);

        $event = new ResolveVolumeForSiteEvent($volumeId, $siteId, $isAllowed);
        Event::trigger(self::class, self::EVENT_RESOLVE_VOLUME_FOR_SITE, $event);

        return $event->isAllowed;
    }

    /**
     * Resolves the default upload volume for a site, firing
     * {@see self::EVENT_RESOLVE_DEFAULT_VOLUME_FOR_SITE} so other modules can override.
     */
    public function resolveDefaultVolumeForSite(int $siteId): ?int
    {
        $volumeId = $this->getDefaultVolumeId($siteId);

        $event = new ResolveDefaultVolumeForSiteEvent($siteId, $volumeId);
        Event::trigger(self::class, self::EVENT_RESOLVE_DEFAULT_VOLUME_FOR_SITE, $event);

        return $event->volumeId;
    }

    /**
     * @param int[] $allowedSiteIds
     */
    public function isAllowedForSiteIds(array $allowedSiteIds, int $siteId): bool
    {
        return $this->accessRule->isAllowed($allowedSiteIds, $siteId);
    }

    /**
     * @return array<int, int[]> Volume ID => allowed site IDs, for every volume that has any restriction configured.
     */
    public function getAllRestrictions(): array
    {
        $rows = (new Query())
            ->select(['volumeId', 'siteId'])
            ->from('{{%sitevolumes_volumesites}}')
            ->all();

        $restrictions = [];
        foreach ($rows as $row) {
            $restrictions[(int)$row['volumeId']][] = (int)$row['siteId'];
        }

        return $restrictions;
    }

    /**
     * A site with zero available volumes can't offer an Assets source at
     * all — every Assets field and the Assets index itself would come up
     * empty for it, with no way to select or upload anything. Checked
     * across the *complete* proposed configuration (every volume, not just
     * the one being edited), since a save that looks fine for the volume
     * in front of you can still starve a site if every other volume
     * already excludes it.
     *
     * @param array<int, int[]> $proposedRestrictionsByVolumeId Volume ID => allowed site IDs, for every volume (unrestricted volumes included with an empty array).
     * @param int[] $siteIds All site IDs to check.
     * @return int[] Site IDs left with no available volume.
     */
    public function findSitesWithNoAvailableVolume(array $proposedRestrictionsByVolumeId, array $siteIds): array
    {
        $starvedSiteIds = [];

        foreach ($siteIds as $siteId) {
            $available = false;

            foreach ($proposedRestrictionsByVolumeId as $allowedSiteIds) {
                if ($this->isAllowedForSiteIds($allowedSiteIds, $siteId)) {
                    $available = true;

                    break;
                }
            }

            if (!$available) {
                $starvedSiteIds[] = $siteId;
            }
        }

        return $starvedSiteIds;
    }

    public function getDefaultVolumeId(int $siteId): ?int
    {
        $volumeId = (new Query())
            ->select(['volumeId'])
            ->from('{{%sitevolumes_sitedefaults}}')
            ->where(['siteId' => $siteId])
            ->scalar();

        return $volumeId !== false ? (int)$volumeId : null;
    }

    /**
     * @return array<int, int> Site ID => default volume ID.
     */
    public function getAllDefaultVolumeIds(): array
    {
        $rows = (new Query())
            ->select(['siteId', 'volumeId'])
            ->from('{{%sitevolumes_sitedefaults}}')
            ->all();

        $defaults = [];
        foreach ($rows as $row) {
            $defaults[(int)$row['siteId']] = (int)$row['volumeId'];
        }

        return $defaults;
    }

    /**
     * A site's default upload volume must still be allowed for that site once
     * restrictions are applied — otherwise Assets fields with no explicit
     * default would point at a volume the picker hides.
     *
     * @param array<int, int|null> $proposedDefaultsBySiteId
     * @param array<int, int[]> $proposedRestrictionsByVolumeId
     * @return int[] Site IDs whose default volume would be disallowed.
     */
    public function findSitesWithInvalidDefaultVolume(
        array $proposedDefaultsBySiteId,
        array $proposedRestrictionsByVolumeId,
    ): array {
        $invalidSiteIds = [];

        foreach ($proposedDefaultsBySiteId as $siteId => $volumeId) {
            if ($volumeId === null || $volumeId === 0) {
                continue;
            }

            $allowedSiteIds = $proposedRestrictionsByVolumeId[$volumeId] ?? [];

            if (!$this->isAllowedForSiteIds($allowedSiteIds, $siteId)) {
                $invalidSiteIds[] = $siteId;
            }
        }

        return $invalidSiteIds;
    }

    /**
     * Sets or clears the default upload volume for a site. Passing null clears
     * the default (Craft falls back to its own first-source behaviour).
     */
    public function saveDefaultVolume(int $siteId, ?int $volumeId): bool
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            SiteDefaultRecord::deleteAll(['siteId' => $siteId]);

            if ($volumeId !== null) {
                $record = new SiteDefaultRecord();
                $record->siteId = $siteId;
                $record->volumeId = $volumeId;

                if (!$record->save()) {
                    $transaction->rollBack();

                    return false;
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::warning("Site Volumes couldn't save default volume for site #{$siteId}: {$e->getMessage()}", __METHOD__);

            return false;
        }

        return true;
    }

    /**
     * Replaces the full set of allowed sites for a volume. An empty
     * `$siteIds` clears all restrictions (volume becomes unrestricted again).
     *
     * @param int[] $siteIds
     */
    public function saveAllowedSites(int $volumeId, array $siteIds): bool
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            VolumeSiteRecord::deleteAll(['volumeId' => $volumeId]);

            foreach (array_unique($siteIds) as $siteId) {
                $record = new VolumeSiteRecord();
                $record->volumeId = $volumeId;
                $record->siteId = (int)$siteId;

                if (!$record->save()) {
                    $transaction->rollBack();

                    return false;
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::warning("Site Volumes couldn't save restrictions for volume #{$volumeId}: {$e->getMessage()}", __METHOD__);

            return false;
        }

        return true;
    }
}
