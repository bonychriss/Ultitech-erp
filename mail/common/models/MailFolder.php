<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $account_id
 * @property string $name
 * @property string $slug
 * @property string|null $imap_path
 * @property int $sort_order
 * @property int $unread_count
 * @property int $created_at
 * @property int $updated_at
 *
 * @property MailAccount $account
 * @property MailMessage[] $messages
 */
class MailFolder extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%mail_folder}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['account_id', 'name', 'slug'], 'required'],
            [['account_id', 'sort_order', 'unread_count', 'created_at', 'updated_at'], 'integer'],
            [['name'], 'string', 'max' => 100],
            [['slug'], 'string', 'max' => 50],
            [['imap_path'], 'string', 'max' => 255],
            [['slug'], 'unique', 'targetAttribute' => ['account_id', 'slug']],
        ];
    }

    public function getAccount(): ActiveQuery
    {
        return $this->hasOne(MailAccount::class, ['id' => 'account_id']);
    }

    public function getMessages(): ActiveQuery
    {
        return $this->hasMany(MailMessage::class, ['folder_id' => 'id']);
    }

    public function refreshUnreadCount(): void
    {
        if ($this->slug === 'starred') {
            return;
        }
        $this->unread_count = (int) MailMessage::find()
            ->where(['folder_id' => $this->id, 'is_read' => 0])
            ->count();
        $this->save(false, ['unread_count', 'updated_at']);
    }
}
