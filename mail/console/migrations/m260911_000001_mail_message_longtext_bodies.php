<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Outlook / HTML emails often exceed MySQL TEXT (64KB).
 */
class m260911_000001_mail_message_longtext_bodies extends Migration
{
    public function safeUp(): void
    {
        $this->execute('ALTER TABLE {{%mail_message}} MODIFY `body_text` LONGTEXT NULL');
        $this->execute('ALTER TABLE {{%mail_message}} MODIFY `body_html` LONGTEXT NULL');
    }

    public function safeDown(): void
    {
        $this->execute('ALTER TABLE {{%mail_message}} MODIFY `body_text` TEXT NULL');
        $this->execute('ALTER TABLE {{%mail_message}} MODIFY `body_html` TEXT NULL');
    }
}
