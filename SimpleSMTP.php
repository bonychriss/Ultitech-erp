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
    private $debug = true; // Enabled debug
    private $logFile = __DIR__ . '/../smtp_debug.log';

    public function __construct($host, $port, $user, $pass, $secure = 'tls') {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->secure = $secure;
    }
    
    private function log($msg) {
        if ($this->debug) {
            file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL, FILE_APPEND);
        }
    }

    public function send($fromEmail, $fromName, $toEmail, $subject, $body, $isHtml = true, $attachments = []) {
        try {
            $this->connect();
            $this->auth();
            
            $this->command("MAIL FROM: <$fromEmail>");
            $this->command("RCPT TO: <$toEmail>");
            $this->command("DATA");
            
            // Unique Boundary
            $boundary = md5(uniqid(time()));
            
            // Headers
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "From: \"$fromName\" <$fromEmail>\r\n";
            $headers .= "To: <$toEmail>\r\n";
            $headers .= "Subject: $subject\r\n";
            $headers .= "Date: " . date("r") . "\r\n";
            $headers .= "X-Mailer: SimpleSMTP/1.0\r\n";
            $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
            
            // Message Body
            $message  = "--$boundary\r\n";
            $message .= "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $body . "\r\n";
            
            // Attachments
            if (!empty($attachments)) {
                foreach ($attachments as $att) {
                    if (file_exists($att['path'])) {
                        $fileData = file_get_contents($att['path']);
                        $filename = basename($att['name']);
                        $encoding = "base64";
                        $type = mime_content_type($att['path']) ?: 'application/octet-stream';
                        
                        $message .= "--$boundary\r\n";
                        $message .= "Content-Type: $type; name=\"$filename\"\r\n";
                        $message .= "Content-Description: $filename\r\n";
                        $message .= "Content-Disposition: attachment; filename=\"$filename\"; size=" . filesize($att['path']) . ";\r\n";
                        $message .= "Content-Transfer-Encoding: $encoding\r\n\r\n";
                        $message .= chunk_split(base64_encode($fileData)) . "\r\n";
                    } elseif (isset($att['content'])) {
                         // Direct content support (Base64 or Raw string)
                        $fileData = $att['content'];
                        $filename = $att['name'];
                        $encoding = "base64";
                        $type = $att['type'] ?? 'application/pdf';
                        
                        $message .= "--$boundary\r\n";
                        $message .= "Content-Type: $type; name=\"$filename\"\r\n";
                        $message .= "Content-Disposition: attachment; filename=\"$filename\"\r\n";
                        $message .= "Content-Transfer-Encoding: $encoding\r\n\r\n";
                        $message .= chunk_split(base64_encode($fileData)) . "\r\n";
                    }
                }
            }
            
            $message .= "--$boundary--";
            
            $data = $headers . "\r\n" . $message . "\r\n.";
            $this->command($data);
            
            $this->command("QUIT");
            $this->disconnect();
            
            return true;
        } catch (Exception $e) {
            $this->disconnect();
            $this->log("ERROR: " . $e->getMessage());
            error_log("SMTP Error: " . $e->getMessage());
            return false;
        }
    }

    private function connect() {
        $protocol = '';
        if ($this->secure === 'ssl') {
            $protocol = 'ssl://';
        }
        
        // Force IPv4 by resolving hostname first
        // "Cannot assign requested address" often happens when resolving to IPv6 but no IPv6 route exists
        $targetHost = gethostbyname($this->host);
        if ($targetHost === $this->host) {
            // DNS resolution failed or it's already an IP; try original just in case
        } else {
             $this->log("Resolved " . $this->host . " to " . $targetHost);
        }

        // Use stream_socket_client for better context control (replaces fsockopen)
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $socketUrl = $protocol . $targetHost . ':' . $this->port;
        $this->conn = @stream_socket_client($socketUrl, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        
        if (!$this->conn) {
            // Try fallback to original host if IP failed
            $socketUrl = $protocol . $this->host . ':' . $this->port;
            $this->log("Retrying with original host: $socketUrl");
            $this->conn = @stream_socket_client($socketUrl, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        }

        if (!$this->conn) {
            throw new Exception("Could not connect to SMTP server: $errstr ($errno)");
        }
        
        $this->getResponse(); // Greeting
        
        $this->command("EHLO " . gethostname());
        
        if ($this->secure === 'tls') {
            $this->command("STARTTLS");
            // Enable TLS 1.0, 1.1, 1.2, 1.3 (Best check for compatibility)
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT | @STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | @STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            
            if (!stream_socket_enable_crypto($this->conn, true, $cryptoMethod)) {
                throw new Exception("TLS negotiation failed. Check if port 587 supports STARTTLS or try port 465 with 'ssl'.");
            }
            $this->command("EHLO " . gethostname());
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
