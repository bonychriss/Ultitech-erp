<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $message_id
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property string|null $storage_path
 * @property string|null $content_id
 * @property int $created_at
 *
 * @property MailMessage $message
 */
class MailAttachment extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%mail_attachment}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => false,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['message_id', 'filename'], 'required'],
            [['message_id', 'size', 'created_at'], 'integer'],
            [['filename', 'content_id'], 'string', 'max' => 255],
            [['mime_type'], 'string', 'max' => 150],
            [['storage_path'], 'string', 'max' => 512],
        ];
    }

    public function getMessage(): ActiveQuery
    {
        return $this->hasOne(MailMessage::class, ['id' => 'message_id']);
    }

    public function isPdf(): bool
    {
        $mime = strtolower((string) $this->mime_type);
        if (str_contains($mime, 'pdf')) {
            return true;
        }
        return str_ends_with(strtolower($this->filename), '.pdf');
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower((string) $this->mime_type), 'image/');
    }
}
