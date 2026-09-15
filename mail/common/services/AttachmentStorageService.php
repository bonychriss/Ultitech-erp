<?php

declare(strict_types=1);

namespace common\services;

use common\models\MailAttachment;
use common\models\MailMessage;
use Yii;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;

/**
 * Stores uploaded / generated mail attachments on disk.
 */
class AttachmentStorageService
{
    public const ALLOWED_EXTENSIONS = [
        'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'zip', 'rar', '7z',
    ];

    public function storeUploads(MailMessage $message, array $uploads): int
    {
        $saved = 0;
        $dir = $this->messageDir($message->id);
        FileHelper::createDirectory($dir);

        foreach ($uploads as $upload) {
            if (!$upload instanceof UploadedFile) {
                continue;
            }
            if ($upload->error !== UPLOAD_ERR_OK) {
                continue;
            }

            $ext = strtolower((string) $upload->extension);
            if ($ext !== '' && !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $upload->baseName) ?: 'file';
            $filename = $upload->name;
            $diskName = $safeBase . '_' . uniqid('', true) . ($ext !== '' ? '.' . $ext : '');
            $path = $dir . DIRECTORY_SEPARATOR . $diskName;

            if (!$upload->saveAs($path)) {
                continue;
            }

            $mime = $upload->type ?: $this->guessMime($path, $ext);
            $attachment = new MailAttachment([
                'message_id' => $message->id,
                'filename' => $filename,
                'mime_type' => $mime,
                'size' => (int) filesize($path),
                'storage_path' => $path,
            ]);
            if ($attachment->save(false)) {
                $saved++;
            }
        }

        if ($saved > 0) {
            $message->has_attachments = true;
            $message->save(false, ['has_attachments', 'updated_at']);
        }

        return $saved;
    }

    public function storeBinary(
        MailMessage $message,
        string $filename,
        string $binary,
        string $mimeType,
    ): MailAttachment {
        $dir = $this->messageDir($message->id);
        FileHelper::createDirectory($dir);

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($filename, PATHINFO_FILENAME)) ?: 'file';
        $diskName = $safeBase . '_' . uniqid('', true) . ($ext !== '' ? '.' . $ext : '');
        $path = $dir . DIRECTORY_SEPARATOR . $diskName;
        file_put_contents($path, $binary);

        $attachment = new MailAttachment([
            'message_id' => $message->id,
            'filename' => $filename,
            'mime_type' => $mimeType !== '' ? $mimeType : $this->guessMime($path, $ext),
            'size' => strlen($binary),
            'storage_path' => $path,
        ]);
        $attachment->save(false);

        $message->has_attachments = true;
        $message->save(false, ['has_attachments', 'updated_at']);

        return $attachment;
    }

    public function absolutePath(MailAttachment $attachment): ?string
    {
        $path = (string) $attachment->storage_path;
        if ($path === '' || !is_file($path)) {
            return null;
        }
        return $path;
    }

    public function messageDir(int $messageId): string
    {
        return Yii::getAlias('@frontend/runtime/mail_attachments/' . $messageId);
    }

    public function guessMime(string $path, string $ext = ''): string
    {
        if (function_exists('mime_content_type') && is_file($path)) {
            $detected = @mime_content_type($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return match (strtolower($ext)) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    public static function normalizeImapMime(string $type, string $subtype): string
    {
        $type = strtolower(trim($type));
        $subtype = strtolower(trim($subtype));
        if ($type === '' || $subtype === '') {
            return 'application/octet-stream';
        }
        // imap type numbers sometimes leak in; map common ones
        $map = [
            '0' => 'text',
            '1' => 'multipart',
            '2' => 'message',
            '3' => 'application',
            '4' => 'audio',
            '5' => 'image',
            '6' => 'video',
        ];
        if (isset($map[$type])) {
            $type = $map[$type];
        }
        if (!str_contains($type, '/') && $subtype !== '') {
            return $type . '/' . $subtype;
        }
        return $subtype !== '' && !str_contains($subtype, '/')
            ? (str_contains($type, '/') ? $type : $type . '/' . $subtype)
            : $type;
    }
}
