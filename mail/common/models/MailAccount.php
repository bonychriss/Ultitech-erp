<?php

declare(strict_types=1);

namespace common\models;

use common\services\MailSsoService;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $company
 * @property string $account_type
 * @property int|null $created_by
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
 * @property User|null $user
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
            [['email', 'imap_host', 'imap_username', 'smtp_host', 'smtp_username'], 'required'],
            [['user_id', 'created_by', 'imap_port', 'smtp_port', 'last_synced_at', 'created_at', 'updated_at'], 'integer'],
            [['imap_password', 'smtp_password'], 'string'],
            [['is_active'], 'boolean'],
            [['email'], 'email'],
            [['company'], 'string', 'max' => 32],
            [['account_type'], 'string', 'max' => 64],
            [['email', 'display_name', 'imap_host', 'imap_username', 'smtp_host', 'smtp_username'], 'string', 'max' => 255],
            [['imap_encryption', 'smtp_encryption'], 'string', 'max' => 10],
            [['imap_encryption', 'smtp_encryption'], 'in', 'range' => ['ssl', 'tls', 'none']],
            [['imap_password_plain', 'smtp_password_plain'], 'safe'],
            [['imap_port'], 'default', 'value' => 993],
            [['smtp_port'], 'default', 'value' => 465],
            [['imap_encryption'], 'default', 'value' => 'ssl'],
            [['smtp_encryption'], 'default', 'value' => 'ssl'],
            [['display_name'], 'default', 'value' => ''],
            [['company'], 'default', 'value' => ''],
            [['account_type'], 'default', 'value' => ''],
            [['is_active'], 'default', 'value' => true],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'email' => 'Email address',
            'account_type' => 'Account type',
            'display_name' => 'Display name',
            'imap_host' => 'IMAP host',
            'imap_port' => 'IMAP port',
            'imap_encryption' => 'IMAP encryption',
            'imap_password_plain' => 'IMAP password',
            'smtp_host' => 'SMTP host',
            'smtp_port' => 'SMTP port',
            'smtp_encryption' => 'SMTP encryption',
            'smtp_password_plain' => 'SMTP password',
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if ($this->company === '' || $this->company === null) {
            $this->company = MailSsoService::expectedCompany() ?: self::companyFromEmail((string) $this->email);
        }

        if ($this->imap_password_plain !== '') {
            $this->imap_password = $this->encryptSecret($this->imap_password_plain);
        }
        if ($this->smtp_password_plain !== '') {
            $this->smtp_password = $this->encryptSecret($this->smtp_password_plain);
        }

        if ($this->imap_password === null) {
            $this->imap_password = '';
        }
        if ($this->smtp_password === null) {
            $this->smtp_password = '';
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

    public function isUnclaimed(): bool
    {
        return $this->user_id === null || (int) $this->user_id === 0;
    }

    public static function companyFromEmail(string $email): string
    {
        $domain = strtolower((string) (explode('@', $email)[1] ?? ''));
        if (str_contains($domain, 'roadmaster')) {
            return 'roadmaster';
        }
        if (str_contains($domain, 'ultimate')) {
            return 'ultimate';
        }
        return $domain !== '' ? explode('.', $domain)[0] : '';
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getFolders(): ActiveQuery
    {
        return $this->hasMany(MailFolder::class, ['account_id' => 'id'])->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_ASC]);
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
            ['Inbox', 'inbox', 1],
            ['Starred', 'starred', 2],
            ['Sent', 'sent', 3],
            ['Drafts', 'drafts', 4],
            ['Trash', 'trash', 5],
            ['Spam', 'spam', 6],
        ];
        foreach ($defaults as [$name, $slug, $sort]) {
            if ($this->getFolderBySlug($slug)) {
                continue;
            }
            $folder = new MailFolder([
                'account_id' => $this->id,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $sort,
                'unread_count' => 0,
            ]);
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
