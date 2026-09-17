<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Team mailbox pool: unclaimed accounts (user_id NULL) users can log into once.
 */
class m260917_160000_mail_account_pool extends Migration
{
    public function safeUp(): void
    {
        $table = '{{%mail_account}}';
        $schema = $this->db->getTableSchema($table, true);
        if ($schema === null) {
            return;
        }

        $this->dropForeignKeySafe($table, 'fk-mail_account-user_id');

        if (isset($schema->columns['user_id']) && !$schema->columns['user_id']->allowNull) {
            $this->alterColumn($table, 'user_id', $this->integer()->null());
        }

        $schema = $this->db->getTableSchema($table, true);
        if ($schema && !isset($schema->columns['company'])) {
            $this->addColumn($table, 'company', $this->string(32)->notNull()->defaultValue(''));
        }
        if ($schema && !isset($schema->columns['created_by'])) {
            $this->addColumn($table, 'created_by', $this->integer()->null());
        }

        $this->createIndexSafe('idx-mail_account-company-unclaimed', $table, ['company', 'user_id']);
        $this->createIndexSafe('idx-mail_account-company-email', $table, ['company', 'email']);

        try {
            $this->addForeignKey(
                'fk-mail_account-user_id',
                $table,
                'user_id',
                '{{%user}}',
                'id',
                'CASCADE',
                'CASCADE',
            );
        } catch (\Throwable $e) {
            // already present
        }
    }

    public function safeDown(): void
    {
        // Keep pool columns; irreversible without data loss for null user_id rows.
    }

    private function dropForeignKeySafe(string $table, string $name): void
    {
        try {
            $this->dropForeignKey($name, $table);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function createIndexSafe(string $name, string $table, $columns): void
    {
        try {
            $this->createIndex($name, $table, $columns);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
