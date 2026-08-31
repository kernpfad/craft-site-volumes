<?php

declare(strict_types=1);

namespace kernpfad\sitevolumes\migrations;

use craft\db\Migration;

class m240815_000000_add_site_defaults extends Migration
{
    public function safeUp(): bool
    {
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

        return true;
    }
}
