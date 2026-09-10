<?php
/**
 * SimpleSMTP - A lightweight SMTP Client for PHP (No dependencies)
 */
class SimpleSMTP {
    private $host;
    private $port;
    private $user;
    private $pass;
    private $secure; // 'tls', 'ssl', or null
    private $conn;
    private $debug = false; // Enabled debug
    private $logFile = __DIR__ . '/../smtp_debug.log';
    private $connectTimeout = 15;
    private $readTimeout = 60;
    private $lastResponse = '';
    private $lastQueueId = '';

    public function __construct($host, $port, $user, $pass, $secure = 'tls') {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->secure = $secure;
    }

    public function setTimeouts(int $connectSeconds, int $readSeconds): void {
        $this->connectTimeout = max(1, $connectSeconds);
        $this->readTimeout = max(1, $readSeconds);
    }
    
    private function log($msg) {
        if ($this->debug) {
            file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL, FILE_APPEND);
        }
    }

    public function testConnection() {
        try {
            $this->connect();
            $this->auth();
            $this->command('QUIT');
            $this->disconnect();
            return ['success' => true, 'message' => 'SMTP connection and authentication successful.'];
        } catch (Exception $e) {
            $this->disconnect();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function send($fromEmail, $fromName, $toEmail, $subject, $body, $isHtml = true, $attachments = [], $textBody = null) {
        $this->lastResponse = '';
        $this->lastQueueId = '';
        try {
            $this->connect();
            $this->auth();

            $fromEmail = trim((string) $fromEmail);
            $toEmail = trim((string) $toEmail);
            $fromName = trim(str_replace(["\r", "\n"], '', (string) $fromName));
            $fromHeader = $fromName !== ''
                ? ($this->encodeMailboxName($fromName) . " <$fromEmail>")
                : "<$fromEmail>";

            $this->command("MAIL FROM: <$fromEmail>");
            $this->command("RCPT TO: <$toEmail>");
            $this->command("DATA");

            $inline = [];
            $files = [];
            if (!empty($attachments) && is_array($attachments)) {
                $seen = [];
                foreach ($attachments as $att) {
                    if (!is_array($att)) {
                        continue;
                    }
                    $isInline = !empty($att['inline']) || !empty($att['cid']);
                    if ($isInline) {
                        $inline[] = $att;
                        continue;
                    }
                    // Deduplicate identical file attachments (same name + payload).
                    $nameKey = strtolower(basename((string) ($att['name'] ?? $att['path'] ?? 'file.bin')));
                    $payloadKey = '';
                    if (isset($att['content'])) {
                        $payloadKey = md5((string) $att['content']);
                    } elseif (!empty($att['path']) && is_file((string) $att['path'])) {
                        $payloadKey = md5_file((string) $att['path']) ?: '';
                    }
                    $dedupeKey = $nameKey . '|' . $payloadKey;
                    if ($payloadKey !== '' && isset($seen[$dedupeKey])) {
                        continue;
                    }
                    if ($payloadKey !== '') {
                        $seen[$dedupeKey] = true;
                    }
                    $files[] = $att;
                }
            }

            // Large PDF attachments need a longer wait for the final SMTP 250 response.
            if ($files !== [] || $inline !== []) {
                $this->readTimeout = max($this->readTimeout, 90);
                if (is_resource($this->conn)) {
                    stream_set_timeout($this->conn, $this->readTimeout);
                }
            }

            $mixedBoundary = 'mix_' . md5(uniqid((string) mt_rand(), true));
            $altBoundary = 'alt_' . md5(uniqid((string) mt_rand(), true));
            $relatedBoundary = 'rel_' . md5(uniqid((string) mt_rand(), true));

            $domain = 'localhost';
            if (strpos($fromEmail, '@') !== false) {
                $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1) ?: 'localhost';
            }
            $messageId = sprintf('<%s.%s@%s>', bin2hex(random_bytes(8)), time(), $domain);

            $plain = is_string($textBody) && trim($textBody) !== ''
                ? $textBody
                : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($plain === '') {
                $plain = (string) $subject;
            }

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= 'Message-ID: ' . $messageId . "\r\n";
            $headers .= 'Date: ' . date('r') . "\r\n";
            $headers .= 'From: ' . $fromHeader . "\r\n";
            $headers .= "Reply-To: <$fromEmail>\r\n";
            $headers .= "Return-Path: <$fromEmail>\r\n";
            $headers .= "To: <$toEmail>\r\n";
            $headers .= 'Subject: ' . $this->encodeSubject((string) $subject) . "\r\n";
            $headers .= "List-Unsubscribe: <mailto:$fromEmail?subject=unsubscribe>\r\n";

            $textPart  = "Content-Type: text/plain; charset=UTF-8\r\n";
            $textPart .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $textPart .= quoted_printable_encode($plain);

            $htmlPart  = "Content-Type: text/html; charset=UTF-8\r\n";
            $htmlPart .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $htmlPart .= quoted_printable_encode($isHtml ? (string) $body : nl2br(htmlspecialchars((string) $body, ENT_QUOTES, 'UTF-8')));

            $altInner  = "--$altBoundary\r\n" . $textPart . "\r\n";
            $altInner .= "--$altBoundary\r\n" . $htmlPart . "\r\n";
            $altInner .= "--$altBoundary--";

            $altPart  = "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";
            $altPart .= $altInner;

            $message = '';

            if ($inline !== [] && $files !== []) {
                $headers .= "Content-Type: multipart/mixed; boundary=\"$mixedBoundary\"\r\n";
                $message .= "--$mixedBoundary\r\n";
                $message .= "Content-Type: multipart/related; boundary=\"$relatedBoundary\"\r\n\r\n";
                $message .= "--$relatedBoundary\r\n" . $altPart . "\r\n";
                $message .= $this->buildInlineParts($relatedBoundary, $inline);
                $message .= "--$relatedBoundary--\r\n";
                $message .= $this->buildFileParts($mixedBoundary, $files);
                $message .= "--$mixedBoundary--";
            } elseif ($inline !== []) {
                $headers .= "Content-Type: multipart/related; boundary=\"$relatedBoundary\"\r\n";
                $message .= "--$relatedBoundary\r\n" . $altPart . "\r\n";
                $message .= $this->buildInlineParts($relatedBoundary, $inline);
                $message .= "--$relatedBoundary--";
            } elseif ($files !== []) {
                $headers .= "Content-Type: multipart/mixed; boundary=\"$mixedBoundary\"\r\n";
                $message .= "--$mixedBoundary\r\n";
                $message .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n";
                $message .= "Content-Disposition: inline\r\n\r\n";
                $message .= $altInner . "\r\n";
                $message .= $this->buildFileParts($mixedBoundary, $files);
                $message .= "--$mixedBoundary--";
            } else {
                $headers .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n";
                $message = $altInner;
            }

            $data = $headers . "\r\n" . $message . "\r\n.";
            $dataResponse = $this->command($data);
            $this->lastResponse = trim((string) $dataResponse);
            if (preg_match('/id=([A-Za-z0-9_-]+)/', $this->lastResponse, $m)) {
                $this->lastQueueId = $m[1];
            }

            $this->command('QUIT');
            $this->disconnect();

            return true;
        } catch (Exception $e) {
            $this->disconnect();
            $this->log('ERROR: ' . $e->getMessage());
            error_log('SMTP Error: ' . $e->getMessage());
            // Preserve message for mailer_last_error callers.
            throw $e;
        }
    }

    public function getLastResponse(): string
    {
        return $this->lastResponse;
    }

    public function getLastQueueId(): string
    {
        return $this->lastQueueId;
    }

    private function encodeSubject($subject)
    {
        $subject = (string) $subject;
        if (preg_match('/[^\x20-\x7E]/', $subject)) {
            return '=?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        return $subject;
    }

    /**
     * Quote / encode a display name for From/To headers.
     */
    private function encodeMailboxName(string $name): string
    {
        $name = trim(str_replace(["\r", "\n"], '', $name));
        if ($name === '') {
            return '';
        }
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return '=?UTF-8?B?' . base64_encode($name) . '?=';
        }
        if (preg_match('/[\\\\"]/', $name) || !preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ .-]+$/', $name)) {
            return '"' . addcslashes($name, '\\"') . '"';
        }
        // Spaces are common in company names — keep as quoted-string.
        if (strpos($name, ' ') !== false) {
            return '"' . $name . '"';
        }
        return $name;
    }

    /**
     * @param array<int,array<string,mixed>> $inline
     */
    private function buildInlineParts($boundary, array $inline)
    {
        $out = '';
        foreach ($inline as $att) {
            $cid = trim((string) ($att['cid'] ?? ''), '<> ');
            if ($cid === '') {
                continue;
            }
            $payload = $this->attachmentPayload($att);
            if ($payload === null) {
                continue;
            }
            list($bytes, $filename, $type) = $payload;
            $out .= "--$boundary\r\n";
            $out .= "Content-Type: $type; name=\"$filename\"\r\n";
            $out .= "Content-Transfer-Encoding: base64\r\n";
            $out .= "Content-ID: <$cid>\r\n";
            $out .= "Content-Disposition: inline; filename=\"$filename\"\r\n\r\n";
            $out .= chunk_split(base64_encode($bytes)) . "\r\n";
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $files
     */
    private function buildFileParts($boundary, array $files)
    {
        $out = '';
        foreach ($files as $att) {
            $payload = $this->attachmentPayload($att);
            if ($payload === null) {
                continue;
            }
            list($bytes, $filename, $type) = $payload;
            $out .= "--$boundary\r\n";
            $out .= "Content-Type: $type; name=\"$filename\"\r\n";
            $out .= "Content-Disposition: attachment; filename=\"$filename\"\r\n";
            $out .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $out .= chunk_split(base64_encode($bytes)) . "\r\n";
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $att
     * @return array{0:string,1:string,2:string}|null
     */
    private function attachmentPayload(array $att)
    {
        $filename = basename((string) ($att['name'] ?? 'file.bin'));
        $type = (string) ($att['type'] ?? $att['mime'] ?? '');
        if (!empty($att['path']) && is_file((string) $att['path'])) {
            $path = (string) $att['path'];
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                return null;
            }
            if ($type === '' && function_exists('mime_content_type')) {
                $type = (string) (mime_content_type($path) ?: '');
            }
            if ($type === '') {
                $type = 'application/octet-stream';
            }
            if ($filename === '' || $filename === 'file.bin') {
                $filename = basename($path);
            }
            return [$bytes, $filename, $type];
        }
        if (isset($att['content'])) {
            $bytes = (string) $att['content'];
            if ($type === '') {
                $type = 'application/octet-stream';
            }
            return [$bytes, $filename !== '' ? $filename : 'file.bin', $type];
        }
        return null;
    }

    private function connect() {
        $protocol = '';
        if ($this->secure === 'ssl') {
            $protocol = 'ssl://';
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => $this->host,
            ]
        ]);

        $targets = [$this->host];
        $resolvedIp = @gethostbyname($this->host);
        if ($resolvedIp && $resolvedIp !== $this->host && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
            $targets[] = $resolvedIp;
        }

        $lastError = null;
        foreach ($targets as $target) {
            $socketUrl = $protocol . $target . ':' . $this->port;
            $this->log('Connecting to ' . $socketUrl);
            $errno = 0;
            $errstr = '';
            $this->conn = @stream_socket_client(
                $socketUrl,
                $errno,
                $errstr,
                $this->connectTimeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($this->conn) {
                break;
            }

            $lastError = "Could not connect to {$this->host}:{$this->port} within {$this->connectTimeout}s: $errstr ($errno)";
        }

        if (!$this->conn) {
            $hint = '';
            if ((int) $this->port === 465 && $this->secure !== 'ssl') {
                $hint = ' Port 465 requires SSL security.';
            } elseif ((int) $this->port === 587 && $this->secure === 'ssl') {
                $hint = ' Port 587 requires TLS security, not SSL.';
            } elseif ((int) $errno === 110 || stripos((string) $errstr, 'timed out') !== false) {
                $hint = ' Check the host name, port, firewall, and that outbound mail ports are allowed from this server.';
            }
            throw new Exception($lastError . $hint);
        }

        stream_set_timeout($this->conn, $this->readTimeout);
        
        $this->getResponse(); // Greeting

        // Use mail domain for EHLO (helps deliverability vs local PC hostname).
        $ehloHost = $this->host;
        if (preg_match('/@([^>]+)$/', (string) $this->user, $m)) {
            $ehloHost = 'mail.' . strtolower(trim($m[1]));
        } elseif (strpos($this->host, '.') !== false) {
            $ehloHost = $this->host;
        } else {
            $ehloHost = 'localhost';
        }
        $ehloHost = preg_replace('/[^A-Za-z0-9.-]/', '', $ehloHost) ?: 'localhost';
        
        $this->command("EHLO " . $ehloHost);
        
        if ($this->secure === 'tls') {
            $this->command("STARTTLS");
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT | @STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | @STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            
            if (!stream_socket_enable_crypto($this->conn, true, $cryptoMethod)) {
                $this->log("TLS negotiation failed. Crypto Method bits: " . $cryptoMethod);
                throw new Exception("TLS negotiation failed. For cPanel mail, use Port 465 with SSL instead of Port 587 with TLS.");
            }
            stream_set_timeout($this->conn, $this->readTimeout);
            $this->command("EHLO " . $ehloHost);
        }
    }

    private function auth() {
        $this->command("AUTH LOGIN");
        $this->command(base64_encode($this->user));
        $this->command(base64_encode($this->pass));
    }

    private function command($cmd) {
        // Obfuscate password in logs
        $logCmd = $cmd;
        if (base64_decode($cmd, true) === $this->pass) { $logCmd = '********'; }
        $this->log("SMTP > $logCmd");
        fputs($this->conn, $cmd . "\r\n");
        return $this->getResponse();
    }

    private function getResponse() {
        $response = "";
        while ($str = fgets($this->conn, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == " ") { break; }

            $meta = stream_get_meta_data($this->conn);
            if (!empty($meta['timed_out'])) {
                throw new Exception("SMTP server did not respond within {$this->readTimeout}s.");
            }
        }

        if ($response === '') {
            $meta = stream_get_meta_data($this->conn);
            if (!empty($meta['timed_out'])) {
                throw new Exception("SMTP server did not respond within {$this->readTimeout}s.");
            }
            throw new Exception('SMTP server closed the connection unexpectedly.');
        }

        if ($this->debug) { $this->log("SMTP < $response"); }
        
        // Check for error codes (4xx or 5xx)
        $code = substr($response, 0, 3);
        if ($code >= 400) {
            throw new Exception("SMTP Error [$code]: $response");
        }
        return $response;
    }

    private function disconnect() {
        if ($this->conn) {
            fclose($this->conn);
            $this->conn = null;
        }
    }
}
