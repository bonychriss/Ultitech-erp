<?php

class ImapService
{
    private array $config;
    private $mbox = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect()
    {
        if (!function_exists('imap_open')) {
            throw new RuntimeException('PHP IMAP extension is not enabled on this server.');
        }

        $host = (string) ($this->config['imap_host'] ?? '');
        $port = (string) ($this->config['imap_port'] ?? '993');
        $user = (string) ($this->config['imap_user'] ?? '');
        $pass = (string) ($this->config['imap_pass'] ?? '');
        $ssl = (string) ($this->config['imap_ssl'] ?? 'ssl');
        $folder = (string) ($this->config['imap_folder'] ?? 'INBOX');

        if ($host === '' || $user === '') {
            throw new RuntimeException('IMAP host/user not configured.');
        }

        $path = '{' . $host . ':' . $port . '/imap/' . $ssl . '/novalidate-cert}' . $folder;
        $this->mbox = @imap_open($path, $user, $pass);
        if (!$this->mbox) {
            $err = function_exists('imap_last_error') ? imap_last_error() : 'unknown';
            throw new RuntimeException('IMAP connection failed: ' . $err);
        }
        return $this->mbox;
    }

    public function close(): void
    {
        if ($this->mbox) {
            @imap_close($this->mbox);
            $this->mbox = null;
        }
    }

    public function listMessages(int $limit = 50, ?string $since = null, int $offset = 0): array
    {
        $mbox = $this->mbox ?: $this->connect();
        $criteria = 'ALL';
        if ($since) {
            $ts = strtotime($since);
            if ($ts) {
                $criteria = 'SINCE "' . date('d-M-Y', $ts) . '"';
            }
        }

        $ids = @imap_search($mbox, $criteria);
        if (!$ids) {
            return [];
        }

        rsort($ids);
        $offset = max(0, $offset);
        $ids = array_slice($ids, $offset, max(1, min($limit, 200)));
        $out = [];
        foreach ($ids as $msgno) {
            $out[] = $this->fetchMessage((int) $msgno, true);
        }
        return $out;
    }

    public function fetchMessage(int $msgno, bool $includeBody = true): array
    {
        $mbox = $this->mbox ?: $this->connect();
        $overview = @imap_fetch_overview($mbox, $msgno, 0);
        $ov = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
        if (!$ov) {
            throw new RuntimeException('Message not found: ' . $msgno);
        }

        $messageId = isset($ov->message_id) ? trim((string) $ov->message_id) : null;
        $subject = isset($ov->subject) ? $this->decodeHeader($ov->subject) : '';
        $from = isset($ov->from) ? $this->decodeHeader($ov->from) : '';
        $to = isset($ov->to) ? $this->decodeHeader($ov->to) : (string) ($this->config['mailbox_email'] ?? '');
        $date = isset($ov->udate) ? date('c', (int) $ov->udate) : (isset($ov->date) ? date('c', strtotime($ov->date)) : date('c'));
        $seen = !empty($ov->seen);

        $item = [
            'uid' => (int) $msgno,
            'message_id' => $messageId,
            'subject' => $subject,
            'from' => $from,
            'to' => $to,
            'date' => $date,
            'seen' => $seen,
            'mailbox' => (string) ($this->config['mailbox_email'] ?? ''),
            'brand' => (string) ($this->config['brand'] ?? ''),
            'domain' => (string) ($this->config['domain'] ?? ''),
        ];

        if ($includeBody) {
            $parsed = $this->parseParts($mbox, $msgno);
            $html = (string) ($parsed['html'] ?? '');
            $html = $this->embedInlineImages($html, $parsed['inline'] ?? []);
            $item['body_html'] = $html;
            $item['body_text'] = $parsed['text'];
            $item['body'] = $html !== '' ? $html : nl2br(htmlspecialchars($parsed['text'], ENT_QUOTES, 'UTF-8'));
            $item['attachments'] = $parsed['attachments'];
        }

        return $item;
    }

    /**
     * Replace cid: references with data: URLs so images display without MIME parts.
     *
     * @param array<string,array{mime:string,data_b64:string}> $inline
     */
    private function embedInlineImages(string $html, array $inline): string
    {
        if ($html === '' || $inline === []) {
            return $html;
        }
        foreach ($inline as $cid => $img) {
            $cid = trim((string) $cid, '<> ');
            if ($cid === '' || empty($img['data_b64'])) {
                continue;
            }
            $mime = (string) ($img['mime'] ?? 'image/png');
            if ($mime === '' || strpos($mime, '/') === false) {
                $mime = 'image/png';
            }
            $dataUrl = 'data:' . $mime . ';base64,' . $img['data_b64'];
            $html = preg_replace('/cid:\s*' . preg_quote($cid, '/') . '/i', $dataUrl, $html) ?? $html;
        }
        return $html;
    }

    public function findByMessageId(string $messageId): ?array
    {
        $mbox = $this->mbox ?: $this->connect();
        $messageId = trim($messageId);
        if ($messageId === '') {
            return null;
        }
        $needle = trim($messageId, '<>');
        $found = @imap_search($mbox, 'HEADER Message-ID "' . $needle . '"');
        if (!$found) {
            $found = @imap_search($mbox, 'HEADER Message-ID "<' . $needle . '>"');
        }
        if (!$found || empty($found[0])) {
            return null;
        }
        return $this->fetchMessage((int) $found[0], true);
    }

    private function decodeHeader(string $value): string
    {
        if (function_exists('imap_utf8')) {
            return trim(imap_utf8($value));
        }
        return trim($value);
    }

    private function parseParts($mbox, int $msgno, $structure = null, string $partNumber = '', array &$result = null): array
    {
        if ($result === null) {
            $result = ['html' => '', 'text' => '', 'attachments' => [], 'inline' => []];
        }
        if (!isset($result['inline']) || !is_array($result['inline'])) {
            $result['inline'] = [];
        }
        if ($structure === null) {
            $structure = @imap_fetchstructure($mbox, $msgno);
            if (!$structure) {
                return $result;
            }
        }

        $typeMap = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER'];
        $primary = $typeMap[(int) ($structure->type ?? 0)] ?? 'OTHER';
        $subtype = strtoupper((string) ($structure->subtype ?? 'PLAIN'));
        $mime = $primary . '/' . $subtype;
        $mimeLower = strtolower($mime);

        if (!empty($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $i => $sub) {
                $subNo = $partNumber === '' ? (string) ($i + 1) : $partNumber . '.' . ($i + 1);
                $this->parseParts($mbox, $msgno, $sub, $subNo, $result);
            }
            return $result;
        }

        $partNo = $partNumber === '' ? '1' : $partNumber;
        $content = @imap_fetchbody($mbox, $msgno, $partNo);
        $content = $this->decodePart($content, $structure);

        $cid = '';
        if (!empty($structure->id)) {
            $cid = trim((string) $structure->id, "<> \t\r\n");
        }
        $isImage = ((int) ($structure->type ?? -1) === 5) || strpos($mimeLower, 'image/') === 0;

        $filename = $this->partFilename($structure);
        $disposition = strtolower((string) ($structure->disposition ?? ''));

        // Keep inline/CID images for HTML embedding (Gmail often also sets a filename).
        if ($isImage || $cid !== '') {
            $inlineCid = $cid !== '' ? $cid : md5((string) $content);
            $result['inline'][$inlineCid] = [
                'mime' => $isImage ? $mimeLower : 'application/octet-stream',
                'data_b64' => base64_encode((string) $content),
            ];
        }

        if ($filename !== '' || $disposition === 'attachment') {
            $result['attachments'][] = [
                'filename' => $filename !== '' ? $filename : ('part-' . $partNo),
                'mime' => $mimeLower,
                'size' => isset($structure->bytes) ? (int) $structure->bytes : strlen((string) $content),
                'part' => $partNo,
                'content_id' => $cid,
                'content_base64' => base64_encode((string) $content),
            ];
            return $result;
        }

        if (strtoupper($mime) === 'TEXT/HTML') {
            $result['html'] .= $content;
        } elseif (strtoupper($mime) === 'TEXT/PLAIN') {
            $result['text'] .= $content;
        }

        return $result;
    }

    private function decodePart($content, $structure): string
    {
        $content = (string) $content;
        $encoding = (int) ($structure->encoding ?? 0);
        if ($encoding === 3) {
            $content = base64_decode($content);
        } elseif ($encoding === 4) {
            $content = quoted_printable_decode($content);
        }
        return is_string($content) ? $content : '';
    }

    private function partFilename($structure): string
    {
        foreach (['dparameters', 'parameters'] as $key) {
            if (empty($structure->$key) || !is_array($structure->$key)) {
                continue;
            }
            foreach ($structure->$key as $param) {
                $attr = strtolower((string) ($param->attribute ?? ''));
                if ($attr === 'filename' || $attr === 'name') {
                    $val = (string) ($param->value ?? '');
                    return function_exists('imap_utf8') ? imap_utf8($val) : $val;
                }
            }
        }
        return '';
    }
}
