<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $body
 * @property string $audience customers|staff|all
 * @property string $status
 * @property int $total
 * @property int $sent_count
 * @property int $failed_count
 * @property int|null $sent_at
 * @property int $created_at
 * @property int $updated_at
 */
class WaCampaign extends ActiveRecord
{
    public const AUDIENCE_CUSTOMERS = 'customers';
    public const AUDIENCE_STAFF = 'staff';
    public const AUDIENCE_ALL = 'all';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return '{{%wa_campaign}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['user_id', 'title', 'body'], 'required'],
            [['user_id', 'total', 'sent_count', 'failed_count', 'sent_at', 'created_at', 'updated_at'], 'integer'],
            [['body'], 'string'],
            [['title'], 'string', 'max' => 255],
            [['audience'], 'in', 'range' => [self::AUDIENCE_CUSTOMERS, self::AUDIENCE_STAFF, self::AUDIENCE_ALL]],
            [['status'], 'string', 'max' => 20],
        ];
    }
}
