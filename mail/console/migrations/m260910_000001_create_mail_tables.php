<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Gmail-like mail schema: accounts, folders, messages, attachments.
 */
class m260910_000001_create_mail_tables extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable('{{%mail_account}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'email' => $this->string(255)->notNull(),
            'display_name' => $this->string(255)->notNull()->defaultValue(''),
            'imap_host' => $this->string(255)->notNull(),
            'imap_port' => $this->integer()->notNull()->defaultValue(993),
            'imap_encryption' => $this->string(10)->notNull()->defaultValue('ssl'),
            'imap_username' => $this->string(255)->notNull(),
            'imap_password' => $this->text()->notNull(),
            'smtp_host' => $this->string(255)->notNull(),
            'smtp_port' => $this->integer()->notNull()->defaultValue(587),
            'smtp_encryption' => $this->string(10)->notNull()->defaultValue('tls'),
            'smtp_username' => $this->string(255)->notNull(),
            'smtp_password' => $this->text()->notNull(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'last_synced_at' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx-mail_account-user_id', '{{%mail_account}}', 'user_id');
        $this->addForeignKey(
            'fk-mail_account-user_id',
            '{{%mail_account}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE',
        );

        $this->createTable('{{%mail_folder}}', [
            'id' => $this->primaryKey(),
            'account_id' => $this->integer()->notNull(),
            'name' => $this->string(100)->notNull(),
            'slug' => $this->string(50)->notNull(),
            'imap_path' => $this->string(255)->null(),
            'sort_order' => $this->integer()->notNull()->defaultValue(0),
            'unread_count' => $this->integer()->notNull()->defaultValue(0),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx-mail_folder-account_id', '{{%mail_folder}}', 'account_id');
        $this->createIndex('uniq-mail_folder-account-slug', '{{%mail_folder}}', ['account_id', 'slug'], true);
        $this->addForeignKey(
            'fk-mail_folder-account_id',
            '{{%mail_folder}}',
            'account_id',
            '{{%mail_account}}',
            'id',
            'CASCADE',
            'CASCADE',
        );

        $this->createTable('{{%mail_message}}', [
            'id' => $this->primaryKey(),
            'account_id' => $this->integer()->notNull(),
            'folder_id' => $this->integer()->notNull(),
            'message_uid' => $this->string(64)->null(),
            'message_id_header' => $this->string(512)->null(),
            'in_reply_to' => $this->string(512)->null(),
            'from_email' => $this->string(255)->notNull()->defaultValue(''),
            'from_name' => $this->string(255)->notNull()->defaultValue(''),
            'to_emails' => $this->text()->null(),
            'cc_emails' => $this->text()->null(),
            'bcc_emails' => $this->text()->null(),
            'subject' => $this->string(998)->notNull()->defaultValue('(no subject)'),
            'body_text' => $this->text()->null(),
            'body_html' => $this->text()->null(),
            'snippet' => $this->string(255)->notNull()->defaultValue(''),
            'is_read' => $this->boolean()->notNull()->defaultValue(false),
            'is_starred' => $this->boolean()->notNull()->defaultValue(false),
            'is_draft' => $this->boolean()->notNull()->defaultValue(false),
            'has_attachments' => $this->boolean()->notNull()->defaultValue(false),
            'date_sent' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx-mail_message-account_folder', '{{%mail_message}}', ['account_id', 'folder_id']);
        $this->createIndex('idx-mail_message-starred', '{{%mail_message}}', ['account_id', 'is_starred']);
        $this->createIndex('idx-mail_message-date', '{{%mail_message}}', ['folder_id', 'date_sent']);
        $this->createIndex('idx-mail_message-uid', '{{%mail_message}}', ['account_id', 'folder_id', 'message_uid']);
        $this->addForeignKey(
            'fk-mail_message-account_id',
            '{{%mail_message}}',
            'account_id',
            '{{%mail_account}}',
            'id',
            'CASCADE',
            'CASCADE',
        );
        $this->addForeignKey(
            'fk-mail_message-folder_id',
            '{{%mail_message}}',
            'folder_id',
            '{{%mail_folder}}',
            'id',
            'CASCADE',
            'CASCADE',
        );

        $this->createTable('{{%mail_attachment}}', [
            'id' => $this->primaryKey(),
            'message_id' => $this->integer()->notNull(),
            'filename' => $this->string(255)->notNull(),
            'mime_type' => $this->string(150)->notNull()->defaultValue('application/octet-stream'),
            'size' => $this->integer()->notNull()->defaultValue(0),
            'storage_path' => $this->string(512)->null(),
            'content_id' => $this->string(255)->null(),
            'created_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx-mail_attachment-message_id', '{{%mail_attachment}}', 'message_id');
        $this->addForeignKey(
            'fk-mail_attachment-message_id',
            '{{%mail_attachment}}',
            'message_id',
            '{{%mail_message}}',
            'id',
            'CASCADE',
            'CASCADE',
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%mail_attachment}}');
        $this->dropTable('{{%mail_message}}');
        $this->dropTable('{{%mail_folder}}');
        $this->dropTable('{{%mail_account}}');
    }
}
