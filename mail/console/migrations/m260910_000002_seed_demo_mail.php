<?php

declare(strict_types=1);

use common\models\User;
use yii\db\Migration;

/**
 * Demo user + sample mailbox so the Gmail UI works before IMAP is configured.
 */
class m260910_000002_seed_demo_mail extends Migration
{
    public function safeUp(): void
    {
        $now = time();

        $user = new User();
        $user->username = 'demo';
        $user->email = 'demo@mail.local';
        $user->status = User::STATUS_ACTIVE;
        $user->setPassword('demo1234');
        $user->generateAuthKey();
        $user->created_at = $now;
        $user->updated_at = $now;
        $user->detachBehaviors();
        $this->insert('{{%user}}', [
            'username' => 'demo',
            'auth_key' => $user->auth_key,
            'password_hash' => $user->password_hash,
            'email' => 'demo@mail.local',
            'status' => User::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
            'verification_token' => null,
        ]);
        $userId = (int) $this->db->getLastInsertID();

        $this->insert('{{%mail_account}}', [
            'user_id' => $userId,
            'email' => 'demo@mail.local',
            'display_name' => 'Demo User',
            'imap_host' => 'localhost',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'demo@mail.local',
            'imap_password' => 'demo',
            'smtp_host' => 'localhost',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'demo@mail.local',
            'smtp_password' => 'demo',
            'is_active' => 1,
            'last_synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $accountId = (int) $this->db->getLastInsertID();

        $folders = [
            ['Inbox', 'inbox', 'INBOX', 1],
            ['Starred', 'starred', null, 2],
            ['Sent', 'sent', 'Sent', 3],
            ['Drafts', 'drafts', 'Drafts', 4],
            ['Trash', 'trash', 'Trash', 5],
            ['Spam', 'spam', 'Junk', 6],
        ];
        $folderIds = [];
        foreach ($folders as [$name, $slug, $imapPath, $sort]) {
            $this->insert('{{%mail_folder}}', [
                'account_id' => $accountId,
                'name' => $name,
                'slug' => $slug,
                'imap_path' => $imapPath,
                'sort_order' => $sort,
                'unread_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $folderIds[$slug] = (int) $this->db->getLastInsertID();
        }

        $samples = [
            [
                'folder' => 'inbox',
                'from_email' => 'orders@supplier.com',
                'from_name' => 'Supplier Desk',
                'subject' => 'PO-10482 confirmation — 120 units ready',
                'body' => "Hi Demo,\n\nPurchase order PO-10482 has been confirmed. 120 units will ship tomorrow.\n\nRegards,\nSupplier Desk",
                'is_read' => 0,
                'is_starred' => 1,
                'hours_ago' => 2,
            ],
            [
                'folder' => 'inbox',
                'from_email' => 'finance@erp.local',
                'from_name' => 'ERP Finance',
                'subject' => 'Invoice INV-9081 awaiting approval',
                'body' => "Hello,\n\nInvoice INV-9081 ($4,250.00) is waiting for your approval in the ERP.\n\nOpen the finance module to review.",
                'is_read' => 0,
                'is_starred' => 0,
                'hours_ago' => 5,
            ],
            [
                'folder' => 'inbox',
                'from_email' => 'hr@company.com',
                'from_name' => 'HR Team',
                'subject' => 'Weekly ops standup notes',
                'body' => "Team,\n\nNotes from today's standup:\n- Warehouse stock sync delayed 20m\n- Customer portal SSL renewed\n- Mail app rollout starts this week\n\nThanks,\nHR",
                'is_read' => 1,
                'is_starred' => 0,
                'hours_ago' => 26,
            ],
            [
                'folder' => 'inbox',
                'from_email' => 'support@clientcorp.com',
                'from_name' => 'Client Corp Support',
                'subject' => 'Re: Delivery window for SO-441',
                'body' => "Hi,\n\nCan you confirm the delivery window for sales order SO-441?\nWe need Friday morning if possible.\n\nBest,\nClient Corp",
                'is_read' => 0,
                'is_starred' => 0,
                'hours_ago' => 30,
            ],
            [
                'folder' => 'sent',
                'from_email' => 'demo@mail.local',
                'from_name' => 'Demo User',
                'subject' => 'Re: Delivery window for SO-441',
                'body' => "Hi,\n\nFriday 09:00–12:00 works. Warehouse will prepare SO-441 today.\n\nDemo",
                'is_read' => 1,
                'is_starred' => 0,
                'hours_ago' => 28,
            ],
            [
                'folder' => 'drafts',
                'from_email' => 'demo@mail.local',
                'from_name' => 'Demo User',
                'subject' => 'Vendor follow-up — overdue ASN',
                'body' => "Hi team,\n\nFollowing up on the overdue ASN for PO-10390...",
                'is_read' => 1,
                'is_starred' => 0,
                'is_draft' => 1,
                'hours_ago' => 10,
            ],
        ];

        $unread = 0;
        foreach ($samples as $i => $row) {
            $folderId = $folderIds[$row['folder']];
            $body = $row['body'];
            $snippet = mb_substr(preg_replace('/\s+/', ' ', $body) ?? $body, 0, 120);
            $date = $now - ((int) $row['hours_ago'] * 3600);
            $isDraft = (int) ($row['is_draft'] ?? 0);
            $this->insert('{{%mail_message}}', [
                'account_id' => $accountId,
                'folder_id' => $folderId,
                'message_uid' => 'demo-' . ($i + 1),
                'message_id_header' => '<demo-' . ($i + 1) . '@mail.local>',
                'in_reply_to' => null,
                'from_email' => $row['from_email'],
                'from_name' => $row['from_name'],
                'to_emails' => json_encode([['email' => 'demo@mail.local', 'name' => 'Demo User']], JSON_UNESCAPED_UNICODE),
                'cc_emails' => null,
                'bcc_emails' => null,
                'subject' => $row['subject'],
                'body_text' => $body,
                'body_html' => '<pre style="font-family:inherit;white-space:pre-wrap;">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>',
                'snippet' => $snippet,
                'is_read' => (int) $row['is_read'],
                'is_starred' => (int) $row['is_starred'],
                'is_draft' => $isDraft,
                'has_attachments' => 0,
                'date_sent' => $date,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!(int) $row['is_read'] && $row['folder'] === 'inbox') {
                $unread++;
            }
        }

        $this->update('{{%mail_folder}}', ['unread_count' => $unread], ['id' => $folderIds['inbox']]);
    }

    public function safeDown(): void
    {
        $userId = (new \yii\db\Query())->from('{{%user}}')->select('id')->where(['username' => 'demo'])->scalar();
        if ($userId) {
            $this->delete('{{%user}}', ['id' => $userId]);
        }
    }
}
