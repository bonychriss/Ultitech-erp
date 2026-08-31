<?php
/**
 * PHP 7.0+ compatible mail bridge sources (Roadmaster host runs PHP 7.0.33).
 */

class ImapService
{
    /** @var array */
    private $config;
    /** @var resource|null */
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

        $host = (string) (isset($this->config['imap_host']) ? $this->config['imap_host'] : '');
        $port = (string) (isset($this->config['imap_port']) ? $this->config['imap_port'] : '993');
        $user = (string) (isset($this->config['imap_user']) ? $this->config['imap_user'] : '');
        $pass = (string) (isset($this->config['imap_pass']) ? $this->config['imap_pass'] : '');
        $ssl = (string) (isset($this->config['imap_ssl']) ? $this->config['imap_ssl'] : 'ssl');
        $folder = (string) (isset($this->config['imap_folder']) ? $this->config['imap_folder'] : 'INBOX');

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

    public function close()
    {
        if ($this->mbox) {
            @imap_close($this->mbox);
            $this->mbox = null;
        }
    }

    /**
     * @param int $limit
     * @param string|null $since
     * @param int $offset
     * @return array
     */
    public function listMessages($limit = 50, $since = null, $offset = 0)
    {
        $mbox = $this->mbox ? $this->mbox : $this->connect();
        $criteria = 'ALL';
        if ($since) {
            $ts = strtotime($since);
            if ($ts) {
                $criteria = 'SINCE "' . date('d-M-Y', $ts) . '"';
            }
        }

        $ids = @imap_search($mbox, $criteria);
        if (!$ids) {
            return array();
        }

        rsort($ids);
        $offset = max(0, (int) $offset);
        $ids = array_slice($ids, $offset, max(1, min((int) $limit, 200)));
        $out = array();
        foreach ($ids as $msgno) {
            $out[] = $this->fetchMessage((int) $msgno, true);
        }
        return $out;
    }

    /**
     * @param int $msgno
     * @param bool $includeBody
     * @return array
     */
    public function fetchMessage($msgno, $includeBody = true)
    {
        $mbox = $this->mbox ? $this->mbox : $this->connect();
        $overview = @imap_fetch_overview($mbox, $msgno, 0);
        $ov = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
        if (!$ov) {
            throw new RuntimeException('Message not found: ' . $msgno);
        }

        $messageId = isset($ov->message_id) ? trim((string) $ov->message_id) : null;
        $subject = isset($ov->subject) ? $this->decodeHeader($ov->subject) : '';
        $from = isset($ov->from) ? $this->decodeHeader($ov->from) : '';
        $to = isset($ov->to)
            ? $this->decodeHeader($ov->to)
            : (string) (isset($this->config['mailbox_email']) ? $this->config['mailbox_email'] : '');
        $date = isset($ov->udate)
            ? date('c', (int) $ov->udate)
            : (isset($ov->date) ? date('c', strtotime($ov->date)) : date('c'));
        $seen = !empty($ov->seen);

        $item = array(
            'uid' => (int) $msgno,
            'message_id' => $messageId,
            'subject' => $subject,
            'from' => $from,
            'to' => $to,
            'date' => $date,
            'seen' => $seen,
            'mailbox' => (string) (isset($this->config['mailbox_email']) ? $this->config['mailbox_email'] : ''),
            'brand' => (string) (isset($this->config['brand']) ? $this->config['brand'] : ''),
            'domain' => (string) (isset($this->config['domain']) ? $this->config['domain'] : ''),
        );

        if ($includeBody) {
            $parsed = $this->parseParts($mbox, (int) $msgno);
            $html = (string) (isset($parsed['html']) ? $parsed['html'] : '');
            $html = $this->embedInlineImages($html, isset($parsed['inline']) ? $parsed['inline'] : array());
            $item['body_html'] = $html;
            $item['body_text'] = $parsed['text'];
            $item['body'] = $html !== '' ? $html : nl2br(htmlspecialchars($parsed['text'], ENT_QUOTES, 'UTF-8'));
            $item['attachments'] = $parsed['attachments'];
        }

        return $item;
    }

    /**
     * @param string $html
     * @param array $inline
     * @return string
     */
    private function embedInlineImages($html, array $inline)
    {
        $html = (string) $html;
        if ($html === '' || $inline === array()) {
            return $html;
        }
        foreach ($inline as $cid => $img) {
            $cid = trim((string) $cid, '<> ');
            if ($cid === '' || empty($img['data_b64'])) {
                continue;
            }
            $mime = (string) (isset($img['mime']) ? $img['mime'] : 'image/png');
            if ($mime === '' || strpos($mime, '/') === false) {
                $mime = 'image/png';
            }
            $dataUrl = 'data:' . $mime . ';base64,' . $img['data_b64'];
            $replaced = preg_replace('/cid:\s*' . preg_quote($cid, '/') . '/i', $dataUrl, $html);
            if (is_string($replaced)) {
                $html = $replaced;
            }
        }
        return $html;
    }

    /**
     * @param string $messageId
     * @return array|null
     */
    public function findByMessageId($messageId)
    {
        $mbox = $this->mbox ? $this->mbox : $this->connect();
        $messageId = trim((string) $messageId);
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

    /**
     * @param string $value
     * @return string
     */
    private function decodeHeader($value)
    {
        if (function_exists('imap_utf8')) {
            return trim(imap_utf8($value));
        }
        return trim((string) $value);
    }

    private function parseParts($mbox, $msgno, $structure = null, $partNumber = '', &$result = null)
    {
        if ($result === null) {
            $result = array('html' => '', 'text' => '', 'attachments' => array(), 'inline' => array());
        }
        if (!isset($result['inline']) || !is_array($result['inline'])) {
            $result['inline'] = array();
        }
        if ($structure === null) {
            $structure = @imap_fetchstructure($mbox, $msgno);
            if (!$structure) {
                return $result;
            }
        }

        $typeMap = array('TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER');
        $typeIdx = isset($structure->type) ? (int) $structure->type : 0;
        $primary = isset($typeMap[$typeIdx]) ? $typeMap[$typeIdx] : 'OTHER';
        $subtype = strtoupper((string) (isset($structure->subtype) ? $structure->subtype : 'PLAIN'));
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
        $isImage = ((isset($structure->type) ? (int) $structure->type : -1) === 5) || strpos($mimeLower, 'image/') === 0;

        $filename = $this->partFilename($structure);
        $disposition = strtolower((string) (isset($structure->disposition) ? $structure->disposition : ''));

        if ($isImage || $cid !== '') {
            $inlineCid = $cid !== '' ? $cid : md5((string) $content);
            $result['inline'][$inlineCid] = array(
                'mime' => $isImage ? $mimeLower : 'application/octet-stream',
                'data_b64' => base64_encode((string) $content),
            );
        }

        if ($filename !== '' || $disposition === 'attachment') {
            $result['attachments'][] = array(
                'filename' => $filename !== '' ? $filename : ('part-' . $partNo),
                'mime' => $mimeLower,
                'size' => isset($structure->bytes) ? (int) $structure->bytes : strlen((string) $content),
                'part' => $partNo,
                'content_id' => $cid,
                'content_base64' => base64_encode((string) $content),
            );
            return $result;
        }

        if (strtoupper($mime) === 'TEXT/HTML') {
            $result['html'] .= $content;
        } elseif (strtoupper($mime) === 'TEXT/PLAIN') {
            $result['text'] .= $content;
        }

        return $result;
    }

    private function decodePart($content, $structure)
    {
        $content = (string) $content;
        $encoding = isset($structure->encoding) ? (int) $structure->encoding : 0;
        if ($encoding === 3) {
            $content = base64_decode($content);
        } elseif ($encoding === 4) {
            $content = quoted_printable_decode($content);
        }
        return is_string($content) ? $content : '';
    }

    private function partFilename($structure)
    {
        foreach (array('dparameters', 'parameters') as $key) {
            if (empty($structure->$key) || !is_array($structure->$key)) {
                continue;
            }
            foreach ($structure->$key as $param) {
                $attr = strtolower((string) (isset($param->attribute) ? $param->attribute : ''));
                if ($attr === 'filename' || $attr === 'name') {
                    $val = (string) (isset($param->value) ? $param->value : '');
                    return function_exists('imap_utf8') ? imap_utf8($val) : $val;
                }
            }
        }
        return '';
    }
}
