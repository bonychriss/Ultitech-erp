<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\helpers\Json;

/**
 * @property int $id
 * @property int $account_id
 * @property int $folder_id
 * @property string|null $message_uid
 * @property string|null $message_id_header
 * @property string|null $in_reply_to
 * @property string $from_email
 * @property string $from_name
 * @property string|null $to_emails
 * @property string|null $cc_emails
 * @property string|null $bcc_emails
 * @property string $subject
 * @property string|null $body_text
 * @property string|null $body_html
 * @property string $snippet
 * @property bool $is_read
 * @property bool $is_starred
 * @property bool $is_draft
 * @property bool $has_attachments
 * @property int|null $date_sent
 * @property int $created_at
 * @property int $updated_at
 *
 * @property MailAccount $account
 * @property MailFolder $folder
 * @property MailAttachment[] $attachments
 */
class MailMessage extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%mail_message}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['account_id', 'folder_id'], 'required'],
            [['account_id', 'folder_id', 'date_sent', 'created_at', 'updated_at'], 'integer'],
            [['to_emails', 'cc_emails', 'bcc_emails', 'body_text', 'body_html'], 'string'],
            [['is_read', 'is_starred', 'is_draft', 'has_attachments'], 'boolean'],
            [['from_email', 'from_name'], 'string', 'max' => 255],
            [['message_uid'], 'string', 'max' => 64],
            [['message_id_header', 'in_reply_to'], 'string', 'max' => 512],
            [['subject'], 'string', 'max' => 998],
            [['snippet'], 'string', 'max' => 255],
            [['subject'], 'default', 'value' => '(no subject)'],
        ];
    }

    public function getAccount(): ActiveQuery
    {
        return $this->hasOne(MailAccount::class, ['id' => 'account_id']);
    }

    public function getFolder(): ActiveQuery
    {
        return $this->hasOne(MailFolder::class, ['id' => 'folder_id']);
    }

    public function getAttachments(): ActiveQuery
    {
        return $this->hasMany(MailAttachment::class, ['message_id' => 'id']);
    }

    public function getFromDisplay(): string
    {
        if ($this->from_name !== '') {
            return $this->from_name;
        }
        return $this->from_email !== '' ? $this->from_email : 'Unknown';
    }

    public function getToList(): array
    {
        return $this->decodeAddressList($this->to_emails);
    }

    public function getCcList(): array
    {
        return $this->decodeAddressList($this->cc_emails);
    }

    public function formatRecipients(array $list): string
    {
        $parts = [];
        foreach ($list as $row) {
            $email = $row['email'] ?? '';
            $name = $row['name'] ?? '';
            if ($name !== '' && $email !== '') {
                $parts[] = $name . ' <' . $email . '>';
            } elseif ($email !== '') {
                $parts[] = $email;
            }
        }
        return implode(', ', $parts);
    }

    public function getBodyForDisplay(): string
    {
        if ($this->body_html) {
            return $this->body_html;
        }
        $text = $this->body_text ?? '';
        return '<pre class="mail-plain">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }

    public function markRead(bool $read = true): void
    {
        if ((bool) $this->is_read === $read) {
            return;
        }
        $this->is_read = $read;
        $this->save(false, ['is_read', 'updated_at']);
        $folder = $this->folder;
        if ($folder) {
            $folder->refreshUnreadCount();
        }
    }

    public function toggleStar(): void
    {
        $this->is_starred = !$this->is_starred;
        $this->save(false, ['is_starred', 'updated_at']);
    }

    public static function makeSnippet(?string $text): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
        return mb_substr($clean, 0, 120);
    }

    private function decodeAddressList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $data = Json::decode($json);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
