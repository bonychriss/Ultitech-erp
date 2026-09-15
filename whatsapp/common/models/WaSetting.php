<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $phone_number_id
 * @property string $business_account_id
 * @property string|null $access_token
 * @property string $webhook_verify_token
 * @property string $display_phone
 * @property bool $is_active
 * @property bool $auto_reply_enabled
 * @property string|null $auto_reply_text
 * @property int $created_at
 * @property int $updated_at
 */
class WaSetting extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%wa_setting}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['user_id'], 'required'],
            [['user_id', 'created_at', 'updated_at'], 'integer'],
            [['access_token', 'auto_reply_text'], 'string'],
            [['is_active', 'auto_reply_enabled'], 'boolean'],
            [['provider'], 'string', 'max' => 32],
            [['phone_number_id', 'business_account_id'], 'string', 'max' => 64],
            [['webhook_verify_token'], 'string', 'max' => 255],
            [['display_phone'], 'string', 'max' => 32],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->phone_number_id !== ''
            && $this->access_token !== null
            && trim((string) $this->access_token) !== '';
    }
}
