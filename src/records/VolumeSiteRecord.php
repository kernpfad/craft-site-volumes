<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $volumeId
 * @property int $siteId
 */
class VolumeSiteRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%sitevolumes_volumesites}}';
    }
}
