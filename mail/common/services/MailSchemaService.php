<?php

declare(strict_types=1);

namespace common\services;

use Yii;
use yii\db\Exception as DbException;

/**
 * Ensures mail_account supports the team mailbox pool on live hosts
 * where console migrate may not have been run.
 */
final class MailSchemaService
{
    private static bool $ensured = false;

    public static function ensurePoolColumns(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        $db = Yii::$app->db;
        $schema = $db->getTableSchema('{{%mail_account}}', true);
        if ($schema === null) {
            return;
        }

        try {
            if (!isset($schema->columns['company'])) {
                $db->createCommand()->addColumn(
                    '{{%mail_account}}',
                    'company',
                    'VARCHAR(32) NOT NULL DEFAULT \'\'',
                )->execute();
            }
            if (!isset($schema->columns['created_by'])) {
                $db->createCommand()->addColumn(
                    '{{%mail_account}}',
                    'created_by',
                    'INT NULL',
                )->execute();
            }
            if (!isset($schema->columns['account_type'])) {
                $db->createCommand()->addColumn(
                    '{{%mail_account}}',
                    'account_type',
                    'VARCHAR(64) NOT NULL DEFAULT \'\'',
                )->execute();
            }
            if (!isset($schema->columns['remember_login'])) {
                $db->createCommand()->addColumn(
                    '{{%mail_account}}',
                    'remember_login',
                    'TINYINT(1) NOT NULL DEFAULT 0',
                )->execute();
                $db->getSchema()->refreshTableSchema('{{%mail_account}}');
            }

            // Refresh and make user_id nullable if needed.
            $schema = $db->getTableSchema('{{%mail_account}}', true);
            $userCol = $schema->columns['user_id'] ?? null;
            if ($userCol && !$userCol->allowNull) {
                self::makeUserIdNullable();
            }
        } catch (\Throwable $e) {
            Yii::error('Mail pool schema ensure failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    private static function makeUserIdNullable(): void
    {
        $db = Yii::$app->db;
        $rawTable = $db->schema->getRawTableName('{{%mail_account}}');
        $rawUser = $db->schema->getRawTableName('{{%user}}');

        // Drop existing FK on user_id if present.
        try {
            $fkRows = $db->createCommand(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :t
                   AND COLUMN_NAME = \'user_id\'
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                [':t' => $rawTable],
            )->queryAll();
            foreach ($fkRows as $row) {
                $name = $row['CONSTRAINT_NAME'] ?? '';
                if ($name !== '') {
                    $db->createCommand("ALTER TABLE `{$rawTable}` DROP FOREIGN KEY `{$name}`")->execute();
                }
            }
        } catch (\Throwable $e) {
            // continue
        }

        $db->createCommand("ALTER TABLE `{$rawTable}` MODIFY `user_id` INT NULL")->execute();

        try {
            $db->createCommand(
                "ALTER TABLE `{$rawTable}`
                 ADD CONSTRAINT `fk-mail_account-user_id`
                 FOREIGN KEY (`user_id`) REFERENCES `{$rawUser}` (`id`)
                 ON DELETE CASCADE ON UPDATE CASCADE",
            )->execute();
        } catch (\Throwable $e) {
            // constraint may already exist under another name
        }
    }
}
