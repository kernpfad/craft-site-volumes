<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\services;

use Craft;
use yii\base\Component;

/**
 * Soft compatibility checks for sibling Kernpfad plugins. No functional
 * coupling — only detects co-installation for CP notices and docs links.
 */
class CompatibilityService extends Component
{
    /** @var array<string, string> Plugin handle => display name */
    private const SIBLING_PLUGINS = [
        'volume-copy' => 'Volume Copy',
        'asset-vault' => 'Asset Vault',
    ];

    /**
     * @return array<string, string> Installed sibling handles mapped to display names.
     */
    public function getInstalledSiblingPlugins(): array
    {
        $plugins = Craft::$app->getPlugins();
        $installed = [];

        foreach (self::SIBLING_PLUGINS as $handle => $name) {
            if ($plugins->isPluginInstalled($handle)) {
                $installed[$handle] = $name;
            }
        }

        return $installed;
    }
}
