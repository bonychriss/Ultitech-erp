<?php

declare(strict_types=1);

namespace common\services;

use common\models\MailAccount;
use common\models\MailFolder;
use common\models\MailMessage;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Yii;
use yii\helpers\Json;
use yii\web\UploadedFile;

/**
 * Sends mail through the account SMTP settings and stores a Sent copy.
 * On shared hosting, falls back to sendmail when SMTP login is rejected.
 */
class SmtpSendService
{
    public function send(MailAccount $account, array $data, array $uploads = []): array
    {
        $to = $this->parseAddressList((string) ($data['to'] ?? ''));
        if ($to === []) {
            return ['ok' => false, 'message' => 'Add at least one recipient.'];
        }

        $subject = trim((string) ($data['subject'] ?? '')) ?: '(no subject)';
        $body = (string) ($data['body'] ?? '');
        $cc = $this->parseAddressList((string) ($data['cc'] ?? ''));
        $bcc = $this->parseAddressList((string) ($data['bcc'] ?? ''));
        $uploads = array_values(array_filter(
            $uploads,
            static fn ($file) => $file instanceof UploadedFile && $file->error === UPLOAD_ERR_OK,
        ));

        $smtpPassword = $account->getDecryptedSmtpPassword();
        if ($smtpPassword === '') {
            $smtpPassword = $account->getDecryptedImapPassword();
        }

        $email = (new Email())
            ->from(new Address($account->email, $account->display_name ?: $account->email))
            ->subject($subject)
            ->text($body !== '' ? $body : ' ')
            ->html(
                $body !== ''
                    ? nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                    : '&nbsp;',
            );

        foreach ($this->toAddressObjects($to) as $address) {
            $email->addTo($address);
        }
        foreach ($this->toAddressObjects($cc) as $address) {
            $email->addCc($address);
        }
        foreach ($this->toAddressObjects($bcc) as $address) {
            $email->addBcc($address);
        }

        if (!empty($data['in_reply_to'])) {
            $email->getHeaders()->addTextHeader('In-Reply-To', (string) $data['in_reply_to']);
        }

        foreach ($uploads as $upload) {
            $email->attachFromPath(
                $upload->tempName,
                $upload->name,
                $upload->type ?: null,
            );
        }

        $smtpOk = false;
        $lastError = '';
        try {
            $transport = $this->buildSmtpTransport($account, $smtpPassword);
            if ($transport === null) {
                throw new \RuntimeException('SMTP password is missing. Update it in Email settings.');
            }
            (new Mailer($transport))->send($email);
            $smtpOk = true;
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
            Yii::error($lastError, __METHOD__);

            if ($this->isLocalDemo($account)) {
                // Fall through: store Sent copy only (local/demo).
            } elseif ($this->canUseSendmailFallback($lastError)) {
                try {
                    (new Mailer(new SendmailTransport()))->send($email);
                    $smtpOk = true;
                    Yii::warning('SMTP failed; sent via sendmail fallback. ' . $lastError, __METHOD__);
                } catch (\Throwable $sendmailError) {
                    Yii::error($sendmailError->getMessage(), __METHOD__);
                    return [
                        'ok' => false,
                        'message' => 'Send failed: ' . $this->friendlySmtpError($lastError),
                    ];
                }
            } else {
                return [
                    'ok' => false,
                    'message' => 'Send failed: ' . $this->friendlySmtpError($lastError),
                ];
            }
        }

        $sentFolder = $account->getFolderBySlug('sent') ?: $this->ensureFolder($account, 'sent', 'Sent', 3);
        $stored = new MailMessage([
            'account_id' => $account->id,
            'folder_id' => $sentFolder->id,
            'message_uid' => 'local-' . uniqid('', true),
            'message_id_header' => '<' . uniqid('sent-', true) . '@mail.local>',
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'from_email' => $account->email,
            'from_name' => $account->display_name,
            'to_emails' => Json::encode($to),
            'cc_emails' => $cc ? Json::encode($cc) : null,
            'bcc_emails' => $bcc ? Json::encode($bcc) : null,
            'subject' => $subject,
            'body_text' => $body,
            'body_html' => '<pre class="mail-plain">' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>',
            'snippet' => MailMessage::makeSnippet($body),
            'is_read' => true,
            'is_starred' => false,
            'is_draft' => false,
            'has_attachments' => $uploads !== [],
            'date_sent' => time(),
        ]);
        $stored->save(false);

        if ($uploads !== []) {
            (new AttachmentStorageService())->storeUploads($stored, $uploads);
        }

        if (!empty($data['draft_id'])) {
            $draft = MailMessage::findOne([
                'id' => (int) $data['draft_id'],
                'account_id' => $account->id,
                'is_draft' => 1,
            ]);
            if ($draft) {
                foreach ($draft->attachments as $old) {
                    if ($old->storage_path && is_file($old->storage_path)) {
                        @unlink($old->storage_path);
                    }
                }
                $draft->delete();
            }
        }

        $note = $smtpOk
            ? 'Message sent.'
            : 'Message saved to Sent (local/demo SMTP).';
        if ($uploads !== []) {
            $note .= ' ' . count($uploads) . ' attachment(s) included.';
        }

        return ['ok' => true, 'message' => $note, 'id' => $stored->id];
    }

    public function saveDraft(MailAccount $account, array $data, ?int $draftId = null, array $uploads = []): MailMessage
    {
        $drafts = $account->getFolderBySlug('drafts') ?: $this->ensureFolder($account, 'drafts', 'Drafts', 4);
        $message = $draftId
            ? MailMessage::findOne(['id' => $draftId, 'account_id' => $account->id, 'is_draft' => 1])
            : null;
        if (!$message) {
            $message = new MailMessage([
                'account_id' => $account->id,
                'folder_id' => $drafts->id,
                'is_draft' => true,
                'is_read' => true,
            ]);
        }

        $to = $this->parseAddressList((string) ($data['to'] ?? ''));
        $body = (string) ($data['body'] ?? '');
        $message->from_email = $account->email;
        $message->from_name = $account->display_name;
        $message->to_emails = Json::encode($to);
        $message->cc_emails = Json::encode($this->parseAddressList((string) ($data['cc'] ?? '')));
        $message->subject = trim((string) ($data['subject'] ?? '')) ?: '(no subject)';
        $message->body_text = $body;
        $message->body_html = '<pre class="mail-plain">' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        $message->snippet = MailMessage::makeSnippet($body);
        $message->date_sent = time();
        $message->save(false);

        $uploads = array_values(array_filter(
            $uploads,
            static fn ($file) => $file instanceof UploadedFile && $file->error === UPLOAD_ERR_OK,
        ));
        if ($uploads !== []) {
            (new AttachmentStorageService())->storeUploads($message, $uploads);
        }

        return $message;
    }

    private function buildSmtpTransport(MailAccount $account, string $smtpPassword): ?TransportInterface
    {
        if ($smtpPassword === '') {
            return null;
        }

        $tls = $account->smtp_encryption === 'ssl' ? true : null;
        if ($account->smtp_encryption === 'none') {
            $tls = false;
        }

        $transport = new EsmtpTransport(
            (string) $account->smtp_host,
            (int) $account->smtp_port,
            $tls,
        );
        $transport->setUsername((string) $account->smtp_username);
        $transport->setPassword($smtpPassword);

        return $transport;
    }

    private function canUseSendmailFallback(string $raw): bool
    {
        if (!is_string(ini_get('sendmail_path')) || trim((string) ini_get('sendmail_path')) === '') {
            return false;
        }

        $raw = strtolower($raw);
        return str_contains($raw, 'authentication')
            || str_contains($raw, '535')
            || str_contains($raw, 'password is missing')
            || str_contains($raw, 'incorrect authentication');
    }

    private function friendlySmtpError(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 'SMTP rejected the message.';
        }
        if (stripos($raw, 'authentication') !== false || stripos($raw, '535') !== false) {
            return 'SMTP login failed. Check the mailbox password in Email settings.';
        }
        if (stripos($raw, 'relay') !== false || stripos($raw, '550') !== false) {
            return $raw;
        }
        return $raw;
    }

    private function isLocalDemo(MailAccount $account): bool
    {
        return in_array($account->smtp_host, ['localhost', '127.0.0.1', 'mail.local'], true)
            || str_ends_with($account->email, '@mail.local');
    }

    private function ensureFolder(MailAccount $account, string $slug, string $name, int $sort): MailFolder
    {
        $folder = new MailFolder([
            'account_id' => $account->id,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $sort,
            'unread_count' => 0,
        ]);
        $folder->save(false);
        return $folder;
    }

    private function parseAddressList(string $raw): array
    {
        $parts = preg_split('/[,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(.+?)\s*<([^>]+)>$/', $part, $m)) {
                $out[] = ['name' => trim($m[1], " \t\"'"), 'email' => trim($m[2])];
            } elseif (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $out[] = ['name' => '', 'email' => $part];
            }
        }
        return $out;
    }

    /** @return Address[] */
    private function toAddressObjects(array $list): array
    {
        $out = [];
        foreach ($list as $row) {
            $email = (string) ($row['email'] ?? '');
            if ($email === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $out[] = $name !== '' ? new Address($email, $name) : new Address($email);
        }
        return $out;
    }
}
