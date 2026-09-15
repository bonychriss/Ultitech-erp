<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $phone
 * @property string $type customer|staff
 * @property string $tags
 * @property string|null $notes
 * @property bool $is_active
 * @property int $created_at
 * @property int $updated_at
 */
class WaContact extends ActiveRecord
{
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_STAFF = 'staff';

    public static function tableName(): string
    {
        return '{{%wa_contact}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['user_id', 'name', 'phone'], 'required'],
            [['user_id', 'created_at', 'updated_at'], 'integer'],
            [['notes'], 'string'],
            [['is_active'], 'boolean'],
            [['name'], 'string', 'max' => 255],
            [['phone'], 'string', 'max' => 32],
            [['type'], 'in', 'range' => [self::TYPE_CUSTOMER, self::TYPE_STAFF]],
            [['tags'], 'string', 'max' => 512],
            [['phone'], 'unique', 'targetAttribute' => ['user_id', 'phone'], 'message' => 'This phone is already saved.'],
        ];
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        return ltrim($digits, '0');
    }
}
