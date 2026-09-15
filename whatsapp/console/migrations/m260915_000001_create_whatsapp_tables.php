<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * WhatsApp bot schema: settings, contacts, messages, campaigns.
 */
class m260915_000001_create_whatsapp_tables extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable('{{%wa_setting}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'provider' => $this->string(32)->notNull()->defaultValue('meta_cloud'),
            'phone_number_id' => $this->string(64)->notNull()->defaultValue(''),
            'business_account_id' => $this->string(64)->notNull()->defaultValue(''),
            'access_token' => $this->text()->null(),
            'webhook_verify_token' => $this->string(255)->notNull()->defaultValue(''),
            'display_phone' => $this->string(32)->notNull()->defaultValue(''),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'auto_reply_enabled' => $this->boolean()->notNull()->defaultValue(false),
            'auto_reply_text' => $this->text()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx-wa_setting-user_id', '{{%wa_setting}}', 'user_id');
        $this->addForeignKey(
            'fk-wa_setting-user_id',
            '{{%wa_setting}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE',
        );

        $this->createTable('{{%wa_contact}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(),
            'phone' => $this->string(32)->notNull(),
            'type' => $this->string(20)->notNull()->defaultValue('customer'),
            'tags' => $this->string(512)->notNull()->defaultValue(''),
            'notes' => $this->text()->null(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx-wa_contact-user_id', '{{%wa_contact}}', 'user_id');
        $this->createIndex('idx-wa_contact-type', '{{%wa_contact}}', ['user_id', 'type']);
        $this->createIndex('uniq-wa_contact-phone', '{{%wa_contact}}', ['user_id', 'phone'], true);
        $this->addForeignKey(
            'fk-wa_contact-user_id',
            '{{%wa_contact}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE',
        );

        $this->createTable('{{%wa_campaign}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'title' => $this->string(255)->notNull(),
            'body' => $this->text()->notNull(),
            'audience' => $this->string(20)->notNull()->defaultValue('customers'),
            'status' => $this->string(20)->notNull()->defaultValue('draft'),
            'total' => $this->integer()->notNull()->defaultValue(0),
            'sent_count' => $this->integer()->notNull()->defaultValue(0),
            'failed_count' => $this->integer()->notNull()->defaultValue(0),
            'sent_at' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx-wa_campaign-user_id', '{{%wa_campaign}}', 'user_id');
        $this->addForeignKey(
            'fk-wa_campaign-user_id',
            '{{%wa_campaign}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE',
        );

        $this->createTable('{{%wa_message}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'contact_id' => $this->integer()->null(),
            'campaign_id' => $this->integer()->null(),
            'direction' => $this->string(10)->notNull()->defaultValue('out'),
            'phone' => $this->string(32)->notNull(),
            'body' => $this->text()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'wa_message_id' => $this->string(128)->null(),
            'error_message' => $this->text()->null(),
            'matter' => $this->string(64)->notNull()->defaultValue('general'),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx-wa_message-user_id', '{{%wa_message}}', 'user_id');
        $this->createIndex('idx-wa_message-phone', '{{%wa_message}}', ['user_id', 'phone']);
        $this->createIndex('idx-wa_message-campaign', '{{%wa_message}}', 'campaign_id');
        $this->addForeignKey(
            'fk-wa_message-user_id',
            '{{%wa_message}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE',
        );
        $this->addForeignKey(
            'fk-wa_message-contact_id',
            '{{%wa_message}}',
            'contact_id',
            '{{%wa_contact}}',
            'id',
            'SET NULL',
            'CASCADE',
        );
        $this->addForeignKey(
            'fk-wa_message-campaign_id',
            '{{%wa_message}}',
            'campaign_id',
            '{{%wa_campaign}}',
            'id',
            'SET NULL',
            'CASCADE',
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%wa_message}}');
        $this->dropTable('{{%wa_campaign}}');
        $this->dropTable('{{%wa_contact}}');
        $this->dropTable('{{%wa_setting}}');
    }
}
