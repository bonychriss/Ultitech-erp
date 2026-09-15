<?php

declare(strict_types=1);

namespace frontend\models;

use common\services\AttachmentStorageService;
use yii\base\Model;
use yii\web\UploadedFile;

class ComposeForm extends Model
{
    public string $to = '';
    public string $cc = '';
    public string $bcc = '';
    public string $subject = '';
    public string $body = '';
    public ?string $in_reply_to = null;
    public ?int $draft_id = null;

    /** @var UploadedFile[]|null */
    public $attachments;

    public function rules(): array
    {
        return [
            [['to'], 'required', 'on' => 'send', 'message' => 'Add at least one recipient.'],
            [['to', 'cc', 'bcc', 'subject', 'body', 'in_reply_to'], 'string'],
            [['draft_id'], 'integer'],
            [
                ['attachments'],
                'file',
                'extensions' => implode(',', AttachmentStorageService::ALLOWED_EXTENSIONS),
                'maxFiles' => 10,
                'maxSize' => 15 * 1024 * 1024,
                'skipOnEmpty' => true,
                'checkExtensionByMimeType' => false,
            ],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'to' => 'To',
            'cc' => 'Cc',
            'bcc' => 'Bcc',
            'subject' => 'Subject',
            'body' => 'Message',
            'attachments' => 'Attachments',
        ];
    }
}
