<?php

declare(strict_types=1);

namespace common\services;

use common\models\MailAccount;
use common\models\MailAttachment;
use common\models\MailFolder;
use common\models\MailMessage;
use Yii;
use yii\helpers\FileHelper;

/**
 * Pulls recent messages from IMAP into the local SQL store.
 */
class ImapSyncService
{
    /** @var array<string, string> local slug => preferred IMAP path */
    private const FOLDER_MAP = [
        'inbox' => 'INBOX',
        'sent' => 'Sent',
        'drafts' => 'Drafts',
        'trash' => 'Trash',
        'spam' => 'Junk',
    ];

    public function sync(MailAccount $account, int $limit = 50): array
    {
        if (!extension_loaded('imap') || !function_exists('imap_open')) {
            return [
                'ok' => false,
                'message' => 'PHP IMAP extension is not enabled on this server. Ask the host to enable ext-imap.',
                'imported' => 0,
            ];
        }

        $account->ensureDefaultFolders();
        $password = $account->getDecryptedImapPassword();
        if ($password === '') {
            return ['ok' => false, 'message' => 'IMAP password is missing. Update it in Email settings.', 'imported' => 0];
        }

        // Keep IMAP attempts short so a bad password cannot freeze the UI.
        if (function_exists('imap_timeout')) {
            if (defined('IMAP_OPENTIMEOUT')) {
                @imap_timeout(IMAP_OPENTIMEOUT, 8);
            }
            if (defined('IMAP_READTIMEOUT')) {
                @imap_timeout(IMAP_READTIMEOUT, 12);
            }
            if (defined('IMAP_WRITETIMEOUT')) {
                @imap_timeout(IMAP_WRITETIMEOUT, 8);
            }
        }

        $imported = 0;
        $errors = [];
        $syncedFolders = 0;
        $authFailed = false;

        foreach (self::FOLDER_MAP as $slug => $defaultPath) {
            if ($authFailed) {
                break;
            }

            $folder = $account->getFolderBySlug($slug);
            if (!$folder) {
                continue;
            }

            $paths = array_values(array_unique(array_filter([
                $folder->imap_path ?: null,
                $defaultPath,
                $slug === 'sent' ? 'INBOX.Sent' : null,
                $slug === 'sent' ? 'Sent Items' : null,
                $slug === 'sent' ? 'Sent Messages' : null,
                $slug === 'spam' ? 'Spam' : null,
                $slug === 'spam' ? 'INBOX.spam' : null,
                $slug === 'spam' ? 'INBOX.Junk' : null,
                $slug === 'trash' ? 'INBOX.Trash' : null,
                $slug === 'trash' ? 'Deleted Items' : null,
                $slug === 'drafts' ? 'INBOX.Drafts' : null,
            ])));

            $opened = false;
            foreach ($paths as $path) {
                $mailbox = $this->buildMailboxString($account, $path);
                $stream = @imap_open($mailbox, $account->imap_username, $password, 0, 0, [
                    'DISABLE_AUTHENTICATOR' => 'GSSAPI',
                ]);
                if ($stream === false) {
                    $imapErr = implode(' ', array_filter(array_merge(
                        imap_errors() ?: [],
                        imap_alerts() ?: [],
                        [imap_last_error() ?: ''],
                    )));
                    if ($this->isAuthFailure($imapErr)) {
                        $authFailed = true;
                        $errors[] = 'IMAP login failed. Check the mailbox password in Email settings.';
                        break;
                    }
                    continue;
                }

                try {
                    if ($folder->imap_path !== $path) {
                        $folder->imap_path = $path;
                        $folder->save(false, ['imap_path', 'updated_at']);
                    }
                    $imported += $this->importRecent($stream, $account, $folder, $limit);
                    $folder->refreshUnreadCount();
                    $syncedFolders++;
                    $opened = true;
                } finally {
                    imap_close($stream);
                }
                break;
            }

            if (!$opened && $slug === 'inbox' && !$authFailed) {
                $error = imap_last_error() ?: 'Unable to connect to IMAP server.';
                $errors[] = $error;
            }
        }

        if ($syncedFolders === 0) {
            return [
                'ok' => false,
                'message' => $errors[0] ?? 'Unable to connect to IMAP server.',
                'imported' => 0,
            ];
        }

        $account->last_synced_at = time();
        $account->save(false, ['last_synced_at', 'updated_at']);
        imap_errors();
        imap_alerts();

        return [
            'ok' => true,
            'message' => $imported > 0
                ? "Synced {$imported} new message(s)."
                : 'Mailbox up to date.',
            'imported' => $imported,
        ];
    }

    private function importRecent($stream, MailAccount $account, MailFolder $folder, int $limit): int
    {
        $check = imap_check($stream);
        if ($check === false || (int) $check->Nmsgs === 0) {
            return 0;
        }

        $uids = imap_search($stream, 'ALL', SE_UID);
        if ($uids === false || $uids === []) {
            return 0;
        }

        sort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, -$limit);
        $imported = 0;

        foreach (array_reverse($uids) as $uid) {
            $uid = (string) $uid;
            $exists = MailMessage::find()
                ->where([
                    'account_id' => $account->id,
                    'folder_id' => $folder->id,
                    'message_uid' => $uid,
                ])
                ->exists();
            if ($exists) {
                continue;
            }

            $overview = imap_fetch_overview($stream, $uid, FT_UID);
            if (!$overview || empty($overview[0])) {
                continue;
            }
            $meta = $overview[0];
            $header = imap_headerinfo($stream, (int) imap_msgno($stream, (int) $uid));
            $structure = imap_fetchstructure($stream, (int) $uid, FT_UID);
            [$text, $html] = $this->extractBodies($stream, (int) $uid, $structure, true);

            $fromEmail = '';
            $fromName = '';
            if ($header && !empty($header->from[0])) {
                $from = $header->from[0];
                $fromEmail = ($from->mailbox ?? '') . '@' . ($from->host ?? '');
                $fromName = isset($from->personal) ? $this->decodeMime($from->personal) : '';
            }

            $message = new MailMessage([
                'account_id' => $account->id,
                'folder_id' => $folder->id,
                'message_uid' => $uid,
                'message_id_header' => ($header && isset($header->message_id)) ? $this->toUtf8(trim($header->message_id)) : null,
                'in_reply_to' => ($header && isset($header->in_reply_to)) ? $this->toUtf8(trim($header->in_reply_to)) : null,
                'from_email' => $this->toUtf8($fromEmail),
                'from_name' => $this->toUtf8($fromName),
                'to_emails' => json_encode($this->addressesFromHeader($header->to ?? []), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]',
                'cc_emails' => json_encode($this->addressesFromHeader($header->cc ?? []), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]',
                'subject' => $this->toUtf8(isset($meta->subject) ? $this->decodeMime($meta->subject) : '(no subject)'),
                'body_text' => $this->toUtf8($text),
                'body_html' => $this->toUtf8($html),
                'snippet' => $this->toUtf8(MailMessage::makeSnippet($text ?: strip_tags((string) $html))),
                'is_read' => !empty($meta->seen),
                'is_starred' => !empty($meta->flagged),
                'is_draft' => $folder->slug === 'drafts',
                'has_attachments' => $this->structureHasAttachment($structure),
                'date_sent' => isset($meta->udate) ? (int) $meta->udate : time(),
            ]);
            if ($message->save(false)) {
                $imported++;
                try {
                    $this->saveAttachments($stream, (int) $uid, $structure, $message, true);
                } catch (\Throwable $e) {
                    Yii::warning('Attachment save failed for UID ' . $uid . ': ' . $e->getMessage(), __METHOD__);
                }
            }
        }

        return $imported;
    }

    private function isAuthFailure(string $error): bool
    {
        $error = strtolower($error);
        return str_contains($error, 'authenticationfailed')
            || str_contains($error, 'authentication failed')
            || str_contains($error, 'invalid credentials')
            || str_contains($error, 'login failed')
            || str_contains($error, 'auth fail')
            || str_contains($error, 'incorrect authentication')
            || str_contains($error, '[auth]');
    }

    private function buildMailboxString(MailAccount $account, string $folderPath): string
    {
        if ($account->imap_encryption === 'ssl') {
            $flags = '/imap/ssl/novalidate-cert';
        } elseif ($account->imap_encryption === 'tls') {
            $flags = '/imap/tls/novalidate-cert';
        } else {
            $flags = '/imap/notls';
        }
        return '{' . $account->imap_host . ':' . $account->imap_port . $flags . '}' . $folderPath;
    }

    private function addressesFromHeader($list): array
    {
        $out = [];
        if (!is_array($list)) {
            return $out;
        }
        foreach ($list as $addr) {
            $email = ($addr->mailbox ?? '') . '@' . ($addr->host ?? '');
            $out[] = [
                'email' => $email,
                'name' => isset($addr->personal) ? $this->decodeMime($addr->personal) : '',
            ];
        }
        return $out;
    }

    private function decodeMime(string $value): string
    {
        $decoded = @imap_mime_header_decode($value);
        if ($decoded === false) {
            return $this->toUtf8($value);
        }
        $out = '';
        foreach ($decoded as $part) {
            $charset = ($part->charset === 'default') ? 'UTF-8' : $part->charset;
            $text = $part->text;
            if (strtoupper((string) $charset) !== 'UTF-8') {
                $converted = @iconv((string) $charset, 'UTF-8//IGNORE', $text);
                if ($converted === false) {
                    $converted = @mb_convert_encoding($text, 'UTF-8', (string) $charset);
                }
                $text = $converted !== false ? $converted : $text;
            }
            $out .= $text;
        }
        return $this->toUtf8($out);
    }

    private function toUtf8(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII');
            $value = is_string($converted) ? $converted : $value;
        }
        // Strip leftover invalid bytes so json_encode never fails
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($clean === false) {
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        }
        return $clean;
    }

    private function extractBodies($stream, int $id, $structure, bool $byUid = false): array
    {
        $text = '';
        $html = '';
        $ft = $byUid ? FT_UID : 0;
        if (!$structure) {
            $raw = imap_body($stream, $id, $ft);
            return [$this->toUtf8($raw ?: ''), ''];
        }

        if (empty($structure->parts)) {
            $body = $this->decodeTextPart(imap_body($stream, $id, $ft), $structure->encoding ?? 0, $structure);
            if (strtoupper($structure->subtype ?? '') === 'HTML') {
                $html = $body;
            } else {
                $text = $body;
            }
            return [$this->toUtf8($text), $this->toUtf8($html)];
        }

        $this->walkParts($stream, $id, $structure->parts, '', $text, $html, $byUid);
        return [$this->toUtf8($text), $this->toUtf8($html)];
    }

    private function walkParts($stream, int $id, array $parts, string $prefix, string &$text, string &$html, bool $byUid = false): void
    {
        $ft = $byUid ? FT_UID : 0;
        foreach ($parts as $i => $part) {
            $partNo = $prefix === '' ? (string) ($i + 1) : $prefix . '.' . ($i + 1);
            $type = (int) ($part->type ?? 0);
            $subtype = strtoupper($part->subtype ?? 'PLAIN');

            if (!empty($part->parts)) {
                $this->walkParts($stream, $id, $part->parts, $partNo, $text, $html, $byUid);
                continue;
            }

            $disposition = strtolower($part->disposition ?? '');
            if ($disposition === 'attachment') {
                continue;
            }

            if ($type === 0 && $subtype === 'PLAIN' && $text === '') {
                $raw = imap_fetchbody($stream, $id, $partNo, $ft);
                $text = $this->decodeTextPart($raw, $part->encoding ?? 0, $part);
            } elseif ($type === 0 && $subtype === 'HTML' && $html === '') {
                $raw = imap_fetchbody($stream, $id, $partNo, $ft);
                $html = $this->decodeTextPart($raw, $part->encoding ?? 0, $part);
            }
        }
    }

    private function decodeTransferEncoding(?string $raw, int $encoding): string
    {
        $raw = (string) $raw;
        $decoded = match ($encoding) {
            3 => base64_decode($raw, true),
            4 => quoted_printable_decode($raw),
            default => $raw,
        };
        return $decoded === false ? $raw : $decoded;
    }

    private function decodeTextPart(?string $raw, int $encoding, $part = null): string
    {
        $decoded = $this->decodeTransferEncoding($raw, $encoding);

        $charset = 'UTF-8';
        if ($part && !empty($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute ?? '') === 'charset') {
                    $charset = (string) $param->value;
                    break;
                }
            }
        }
        if (strtoupper($charset) !== 'UTF-8' && $charset !== '') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $decoded);
            if ($converted === false) {
                $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            }
            if (is_string($converted)) {
                $decoded = $converted;
            }
        }

        return $this->toUtf8($decoded);
    }

    private function structureHasAttachment($structure): bool
    {
        if (!$structure || empty($structure->parts)) {
            return false;
        }
        foreach ($structure->parts as $part) {
            $disposition = strtolower($part->disposition ?? '');
            if ($disposition === 'attachment') {
                return true;
            }
            if (!empty($part->parts) && $this->structureHasAttachment($part)) {
                return true;
            }
        }
        return false;
    }

    private function saveAttachments($stream, int $id, $structure, MailMessage $message, bool $byUid = false): void
    {
        if (!$structure || empty($structure->parts)) {
            return;
        }

        $dir = Yii::getAlias('@frontend/runtime/mail_attachments/' . $message->id);
        FileHelper::createDirectory($dir);
        $ft = $byUid ? FT_UID : 0;

        $saved = false;
        foreach ($structure->parts as $i => $part) {
            $disposition = strtolower($part->disposition ?? '');
            $filename = $this->partFilename($part);
            if ($disposition !== 'attachment' && $filename === null) {
                continue;
            }
            if ($filename === null) {
                $filename = 'attachment-' . ($i + 1);
            }
            $raw = imap_fetchbody($stream, $id, (string) ($i + 1), $ft);
            $data = $this->decodeTransferEncoding($raw, $part->encoding ?? 0);
            $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $this->toUtf8($filename)) ?: 'file';
            $path = $dir . '/' . $safeName;
            file_put_contents($path, $data);

            $attachment = new MailAttachment([
                'message_id' => $message->id,
                'filename' => $this->toUtf8($filename),
                'mime_type' => AttachmentStorageService::normalizeImapMime(
                    (string) ($part->type ?? 'application'),
                    (string) ($part->subtype ?? 'octet-stream'),
                ),
                'size' => strlen($data),
                'storage_path' => $path,
            ]);
            $attachment->save(false);
            $saved = true;
        }

        if ($saved) {
            $message->has_attachments = true;
            $message->save(false, ['has_attachments', 'updated_at']);
        }
    }

    private function partFilename($part): ?string
    {
        if (!empty($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute ?? '') === 'filename') {
                    return $this->decodeMime($param->value);
                }
            }
        }
        if (!empty($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute ?? '') === 'name') {
                    return $this->decodeMime($param->value);
                }
            }
        }
        return null;
    }
}
