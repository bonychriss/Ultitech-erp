<?php

class SmtpService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(string $to, string $subject, string $body, bool $isHtml = true, ?string $fromEmail = null, ?string $fromName = null): array
    {
        $host = (string) ($this->config['smtp_host'] ?? '');
        $port = (int) ($this->config['smtp_port'] ?? 465);
        $user = (string) ($this->config['smtp_user'] ?? '');
        $pass = (string) ($this->config['smtp_pass'] ?? '');
        $secure = strtolower((string) ($this->config['smtp_secure'] ?? 'ssl'));
        $fromEmail = $fromEmail ?: (string) ($this->config['mailbox_email'] ?? $user);
        $fromName = $fromName ?: (string) ($this->config['from_name'] ?? ($this->config['brand'] ?? 'Mail'));

        if ($host === '' || $user === '') {
            throw new RuntimeException('SMTP is not configured.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid recipient email.');
        }

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($fp, 20);

        $this->expect($fp, 220);
        $this->cmd($fp, 'EHLO mail-bridge.local', 250);

        if ($secure === 'tls') {
            $this->cmd($fp, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS failed.');
            }
            $this->cmd($fp, 'EHLO mail-bridge.local', 250);
        }

        $this->cmd($fp, 'AUTH LOGIN', 334);
        $this->cmd($fp, base64_encode($user), 334);
        $this->cmd($fp, base64_encode($pass), 235);

        $this->cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', 250);
        $this->cmd($fp, 'RCPT TO:<' . $to . '>', 250);
        $this->cmd($fp, 'DATA', 354);

        $boundary = 'mb_' . md5(uniqid((string) mt_rand(), true));
        $headers = [
            'Date: ' . date('r'),
            'From: "' . addslashes($fromName) . '" <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Ultitech-MailBridge/1.0',
        ];

        $plain = $isHtml ? trim(html_entity_decode(strip_tags($body))) : $body;
        $html = $isHtml ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        $data = implode("\r\n", $headers) . "\r\n\r\n";
        $data .= '--' . $boundary . "\r\n";
        $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $data .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $data .= quoted_printable_encode($plain) . "\r\n";
        $data .= '--' . $boundary . "\r\n";
        $data .= "Content-Type: text/html; charset=UTF-8\r\n";
        $data .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $data .= quoted_printable_encode($html) . "\r\n";
        $data .= '--' . $boundary . "--\r\n.";

        $this->cmd($fp, $data, 250);
        $this->cmd($fp, 'QUIT', 221);
        fclose($fp);

        return [
            'from' => $fromEmail,
            'to' => $to,
            'subject' => $subject,
            'sent_at' => date('c'),
        ];
    }

    public function test(): array
    {
        $host = (string) ($this->config['smtp_host'] ?? '');
        $port = (int) ($this->config['smtp_port'] ?? 465);
        $user = (string) ($this->config['smtp_user'] ?? '');
        $pass = (string) ($this->config['smtp_pass'] ?? '');
        $secure = strtolower((string) ($this->config['smtp_secure'] ?? 'ssl'));
        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($fp, 12);
        $this->expect($fp, 220);
        $this->cmd($fp, 'EHLO mail-bridge.local', 250);
        if ($secure === 'tls') {
            $this->cmd($fp, 'STARTTLS', 220);
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->cmd($fp, 'EHLO mail-bridge.local', 250);
        }
        $this->cmd($fp, 'AUTH LOGIN', 334);
        $this->cmd($fp, base64_encode($user), 334);
        $this->cmd($fp, base64_encode($pass), 235);
        $this->cmd($fp, 'QUIT', 221);
        fclose($fp);
        return ['ok' => true];
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private function cmd($fp, string $command, int $expectCode): void
    {
        fwrite($fp, $command . "\r\n");
        $this->expect($fp, $expectCode);
    }

    private function expect($fp, int $code): void
    {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $got = (int) substr($response, 0, 3);
        if ($got !== $code) {
            throw new RuntimeException("SMTP unexpected response (wanted $code): " . trim($response));
        }
    }
}
