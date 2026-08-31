<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%sitevolumes_volumesites}}', [
            'id' => $this->primaryKey(),
            'volumeId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%sitevolumes_volumesites}}', ['volumeId', 'siteId'], true);

        $this->addForeignKey(
            null,
            '{{%sitevolumes_volumesites}}',
            ['volumeId'],
            '{{%volumes}}',
            ['id'],
            'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%sitevolumes_volumesites}}',
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE'
        );

        // Kept in lockstep with m240815_000000_add_site_defaults: Craft
        // baselines a plugin's numbered migrations as already-applied (never
        // actually executing safeUp()) when the plugin is installed fresh,
        // on the assumption Install.php already reflects the full current
        // schema. Without this table here too, a brand-new install of this
        // plugin lacks sitevolumes_sitedefaults entirely, and the very first
        // afterSaveSite/afterSaveVolume event that calls
        // RestrictionService::getDefaultVolumeId() throws a "table doesn't
        // exist" PDOException — confirmed live in the CI integration job.
        $this->createTable('{{%sitevolumes_sitedefaults}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'volumeId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%sitevolumes_sitedefaults}}', ['siteId'], true);

        $this->addForeignKey(
            null,
            '{{%sitevolumes_sitedefaults}}',
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%sitevolumes_sitedefaults}}',
            ['volumeId'],
            '{{%volumes}}',
            ['id'],
            'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%sitevolumes_sitedefaults}}');
        $this->dropTableIfExists('{{%sitevolumes_volumesites}}');

        return true;
    }
}
