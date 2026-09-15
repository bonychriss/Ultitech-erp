<?php

declare(strict_types=1);

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string $display_name
 * @property string $imap_host
 * @property int $imap_port
 * @property string $imap_encryption
 * @property string $imap_username
 * @property string $imap_password
 * @property string $smtp_host
 * @property int $smtp_port
 * @property string $smtp_encryption
 * @property string $smtp_username
 * @property string $smtp_password
 * @property bool $is_active
 * @property int|null $last_synced_at
 * @property int $created_at
 * @property int $updated_at
 *
 * @property User $user
 * @property MailFolder[] $folders
 * @property MailMessage[] $messages
 */
class MailAccount extends ActiveRecord
{
    public string $imap_password_plain = '';
    public string $smtp_password_plain = '';

    public static function tableName(): string
    {
        return '{{%mail_account}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['user_id', 'email', 'imap_host', 'imap_username', 'smtp_host', 'smtp_username'], 'required'],
            [['user_id', 'imap_port', 'smtp_port', 'last_synced_at', 'created_at', 'updated_at'], 'integer'],
            [['imap_password', 'smtp_password'], 'string'],
            [['is_active'], 'boolean'],
            [['email'], 'email'],
            [['email', 'display_name', 'imap_host', 'imap_username', 'smtp_host', 'smtp_username'], 'string', 'max' => 255],
            [['imap_encryption', 'smtp_encryption'], 'string', 'max' => 10],
            [['imap_encryption', 'smtp_encryption'], 'in', 'range' => ['ssl', 'tls', 'none']],
            [['imap_password_plain', 'smtp_password_plain'], 'safe'],
            [['imap_port'], 'default', 'value' => 993],
            [['smtp_port'], 'default', 'value' => 587],
            [['imap_encryption'], 'default', 'value' => 'ssl'],
            [['smtp_encryption'], 'default', 'value' => 'tls'],
            [['display_name'], 'default', 'value' => ''],
            [['is_active'], 'default', 'value' => true],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'email' => 'Email address',
            'display_name' => 'Display name',
            'imap_host' => 'IMAP host',
            'imap_port' => 'IMAP port',
            'imap_encryption' => 'IMAP encryption',
            'imap_username' => 'IMAP username',
            'imap_password_plain' => 'IMAP password',
            'smtp_host' => 'SMTP host',
            'smtp_port' => 'SMTP port',
            'smtp_encryption' => 'SMTP encryption',
            'smtp_username' => 'SMTP username',
            'smtp_password_plain' => 'SMTP password',
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if ($this->imap_password_plain !== '') {
            $this->imap_password = $this->encryptSecret($this->imap_password_plain);
        }
        if ($this->smtp_password_plain !== '') {
            $this->smtp_password = $this->encryptSecret($this->smtp_password_plain);
        }

        return true;
    }

    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);
        if ($insert) {
            $this->ensureDefaultFolders();
        }
    }

    public function getDecryptedImapPassword(): string
    {
        return $this->decryptSecret((string) $this->imap_password);
    }

    public function getDecryptedSmtpPassword(): string
    {
        return $this->decryptSecret((string) $this->smtp_password);
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getFolders(): ActiveQuery
    {
        return $this->hasMany(MailFolder::class, ['account_id' => 'id'])->orderBy(['sort_order' => SORT_ASC]);
    }

    public function getMessages(): ActiveQuery
    {
        return $this->hasMany(MailMessage::class, ['account_id' => 'id']);
    }

    public function getFolderBySlug(string $slug): ?MailFolder
    {
        return MailFolder::findOne(['account_id' => $this->id, 'slug' => $slug]);
    }

    public function ensureDefaultFolders(): void
    {
        $defaults = [
            ['Inbox', 'inbox', 'INBOX', 1],
            ['Starred', 'starred', null, 2],
            ['Sent', 'sent', 'Sent', 3],
            ['Drafts', 'drafts', 'Drafts', 4],
            ['Trash', 'trash', 'Trash', 5],
            ['Spam', 'spam', 'Junk', 6],
        ];
        $now = time();
        foreach ($defaults as [$name, $slug, $imapPath, $sort]) {
            if (MailFolder::find()->where(['account_id' => $this->id, 'slug' => $slug])->exists()) {
                continue;
            }
            $folder = new MailFolder([
                'account_id' => $this->id,
                'name' => $name,
                'slug' => $slug,
                'imap_path' => $imapPath,
                'sort_order' => $sort,
                'unread_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $folder->detachBehavior('timestamp');
            $folder->save(false);
        }
    }

    private function encryptSecret(string $plain): string
    {
        if ($plain === '' || str_starts_with($plain, 'enc:')) {
            return $plain;
        }
        $key = Yii::$app->params['mail.secretKey'] ?? 'mail-app-erp-secret-change-me';
        return 'enc:' . base64_encode(Yii::$app->security->encryptByPassword($plain, $key));
    }

    private function decryptSecret(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, 'enc:')) {
            return $stored;
        }
        $key = Yii::$app->params['mail.secretKey'] ?? 'mail-app-erp-secret-change-me';
        $decoded = base64_decode(substr($stored, 4), true);
        if ($decoded === false) {
            return '';
        }
        $plain = Yii::$app->security->decryptByPassword($decoded, $key);
        return is_string($plain) ? $plain : '';
    }
}
