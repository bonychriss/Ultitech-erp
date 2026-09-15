<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Seed inbox attachment chips matching the ERP mail screenshot.
 */
class m260910_000004_seed_inbox_attach_chips extends Migration
{
    public function safeUp()
    {
        $accountId = (int) $this->db->createCommand("SELECT id FROM {{%mail_account}} ORDER BY id ASC LIMIT 1")->queryScalar();
        if ($accountId < 1) {
            return;
        }

        $inboxId = (int) $this->db->createCommand(
            'SELECT id FROM {{%mail_folder}} WHERE account_id = :a AND slug = :s',
            [':a' => $accountId, ':s' => 'inbox']
        )->queryScalar();
        if ($inboxId < 1) {
            return;
        }

        $now = time();
        $messages = [
            [
                'from_name' => 'Theresia Madeni',
                'from_email' => 'theresia@example.com',
                'subject' => 'RE: order',
                'snippet' => 'Please find the images for the latest order confirmation attached.',
                'body' => "Hi,\n\nPlease find the images for the latest order confirmation attached.\n\nThanks,\nTheresia",
                'days_ago' => 20,
                'files' => [
                    ['image001.png', 'image/png', true],
                    ['image002.wmz', 'application/x-msmetafile', false],
                    ['image003.gif', 'image/gif', true],
                    ['20260821184712654.pdf', 'application/pdf', true],
                ],
            ],
            [
                'from_name' => 'Iddy Fuko',
                'from_email' => 'iddy@example.com',
                'subject' => 'RE: QUOTE',
                'snippet' => 'Attached is the revised quotation as discussed.',
                'body' => "Hello,\n\nAttached is the revised quotation as discussed.\n\nRegards,\nIddy",
                'days_ago' => 20,
                'files' => [
                    ['quote-revised.pdf', 'application/pdf', true],
                ],
            ],
        ];

        foreach ($messages as $row) {
            $exists = (int) $this->db->createCommand(
                'SELECT COUNT(*) FROM {{%mail_message}} WHERE account_id = :a AND subject = :s AND from_email = :e',
                [':a' => $accountId, ':s' => $row['subject'], ':e' => $row['from_email']]
            )->queryScalar();
            if ($exists > 0) {
                continue;
            }

            $sent = $now - ($row['days_ago'] * 86400);
            $this->insert('{{%mail_message}}', [
                'account_id' => $accountId,
                'folder_id' => $inboxId,
                'message_uid' => 'seed-chip-' . md5($row['subject'] . $row['from_email']),
                'message_id_header' => '<' . uniqid('chip.', true) . '@mail.local>',
                'in_reply_to' => null,
                'from_name' => $row['from_name'],
                'from_email' => $row['from_email'],
                'to_emails' => json_encode([['name' => 'System Admin', 'email' => 'demo@mail.local']], JSON_UNESCAPED_UNICODE),
                'cc_emails' => null,
                'bcc_emails' => null,
                'subject' => $row['subject'],
                'snippet' => $row['snippet'],
                'body_text' => $row['body'],
                'body_html' => null,
                'is_read' => 0,
                'is_starred' => 0,
                'is_draft' => 0,
                'has_attachments' => 1,
                'date_sent' => $sent,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $messageId = (int) $this->db->getLastInsertID();

            foreach ($row['files'] as [$filename, $mime, $hot]) {
                $this->insert('{{%mail_attachment}}', [
                    'message_id' => $messageId,
                    'filename' => $filename,
                    'mime_type' => $mime,
                    'size' => random_int(12_000, 240_000),
                    'storage_path' => null,
                    'content_id' => null,
                    'created_at' => $now,
                ]);
            }
        }

        $unread = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%mail_message}} WHERE folder_id = :f AND is_read = 0',
            [':f' => $inboxId]
        )->queryScalar();
        $this->update('{{%mail_folder}}', ['unread_count' => $unread, 'updated_at' => $now], ['id' => $inboxId]);
    }

    public function safeDown()
    {
        $this->delete('{{%mail_message}}', ['like', 'message_uid', 'seed-chip-%', false]);
    }
}
