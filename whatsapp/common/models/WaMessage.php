<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $contact_id
 * @property int|null $campaign_id
 * @property string $direction
 * @property string $phone
 * @property string $body
 * @property string $status
 * @property string|null $wa_message_id
 * @property string|null $error_message
 * @property string $matter
 * @property int $created_at
 * @property int $updated_at
 * @property WaContact|null $contact
 */
class WaMessage extends ActiveRecord
{
    public const DIR_OUT = 'out';
    public const DIR_IN = 'in';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RECEIVED = 'received';

    public static function tableName(): string
    {
        return '{{%wa_message}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['user_id', 'phone', 'body'], 'required'],
            [['user_id', 'contact_id', 'campaign_id', 'created_at', 'updated_at'], 'integer'],
            [['body', 'error_message'], 'string'],
            [['direction'], 'in', 'range' => [self::DIR_OUT, self::DIR_IN]],
            [['status', 'matter'], 'string', 'max' => 64],
            [['phone'], 'string', 'max' => 32],
            [['wa_message_id'], 'string', 'max' => 128],
        ];
    }

    public function getContact(): ActiveQuery
    {
        return $this->hasOne(WaContact::class, ['id' => 'contact_id']);
    }
}
