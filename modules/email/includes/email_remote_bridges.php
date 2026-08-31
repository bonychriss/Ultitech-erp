<?php
/**
 * Remote mail-bridge clients (Ultimate / Roadmaster cPanel APIs).
 */

if (!function_exists('email_get_remote_bridges')) {
    function email_get_remote_bridges($pdo = null): array
    {
        $settings = array();
        $candidates = array();
        if ($pdo instanceof PDO) {
            $candidates[] = $pdo;
        }
        if (function_exists('email_settings_pdo')) {
            $sp = email_settings_pdo();
            if ($sp instanceof PDO) {
                $candidates[] = $sp;
            }
        }
        if (function_exists('email_module_pdo')) {
            $ep = email_module_pdo();
            if ($ep instanceof PDO) {
                $candidates[] = $ep;
            }
        }
        global $pdo, $control_pdo;
        if ($pdo instanceof PDO) {
            $candidates[] = $pdo;
        }
        if ($control_pdo instanceof PDO) {
            $candidates[] = $control_pdo;
        }
        // Explicit company tenant DB (bridges are saved per-company).
        if (function_exists('currentCompanyId') && function_exists('connectToTenantDatabase') && ($control_pdo instanceof PDO || $pdo instanceof PDO)) {
            $meta = ($control_pdo instanceof PDO) ? $control_pdo : $pdo;
            try {
                $cid = (int) (currentCompanyId() ?? 0);
                if ($cid > 0) {
                    $st = $meta->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
                    $st->execute(array($cid));
                    $dbName = trim((string) ($st->fetchColumn() ?: ''));
                    if ($dbName !== '') {
                        $tenant = connectToTenantDatabase($dbName);
                        if ($tenant instanceof PDO) {
                            $candidates[] = $tenant;
                        }
                    }
                }
            } catch (Throwable $e) {
            }
        }

        foreach (array_values(array_unique($candidates, SORT_REGULAR)) as $conn) {
            try {
                if (!($conn instanceof PDO)) {
                    continue;
                }
                if (function_exists('email_connection_has_table') && !email_connection_has_table($conn, 'system_settings')) {
                    continue;
                }
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_bridge_%'");
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                if (!empty($rows)) {
                    $settings = $rows;
                    break;
                }
            } catch (Throwable $e) {
            }
        }

        $bridges = array();
        foreach (array('ultimate', 'roadmaster') as $key) {
            $url = trim((string) ($settings['email_bridge_' . $key . '_url'] ?? ''));
            $apiKey = trim((string) ($settings['email_bridge_' . $key . '_api_key'] ?? ''));
            $enabledRaw = trim((string) ($settings['email_bridge_' . $key . '_enabled'] ?? '1'));
            if ($url === '' || $apiKey === '') {
                continue;
            }
            // Credentials present => usable unless explicitly disabled.
            $enabled = ($enabledRaw !== '0');
            $bridges[] = array(
                'key' => $key,
                'url' => rtrim($url, '/'),
                'api_key' => $apiKey,
                'enabled' => $enabled,
                'mailbox' => trim((string) ($settings['email_bridge_' . $key . '_mailbox'] ?? '')),
            );
        }
        return $bridges;
    }
}

if (!function_exists('email_local_bridge_smtp_config')) {
    /**
     * Read SMTP credentials from local mail-bridges/{key}/config.php (dev/fallback).
     * @return array{host:string,port:string,user:string,pass:string,secure:string,from_name:string}|null
     */
    function email_local_bridge_smtp_config(string $key): ?array
    {
        static $cache = array();
        $key = preg_replace('/[^a-z0-9_\-]/i', '', $key);
        if ($key === '') {
            return null;
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $root = dirname(__DIR__, 3);
        $path = $root . DIRECTORY_SEPARATOR . 'mail-bridges' . DIRECTORY_SEPARATOR . $key . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_file($path)) {
            return $cache[$key] = null;
        }
        $cfg = include $path;
        if (!is_array($cfg)) {
            return $cache[$key] = null;
        }
        $host = trim((string) ($cfg['smtp_host'] ?? ''));
        $user = trim((string) ($cfg['smtp_user'] ?? $cfg['mailbox_email'] ?? ''));
        $pass = (string) ($cfg['smtp_pass'] ?? '');
        if ($host === '' || $user === '' || $pass === '') {
            return $cache[$key] = null;
        }
        return $cache[$key] = array(
            'host' => $host,
            'port' => (string) ($cfg['smtp_port'] ?? '465'),
            'user' => $user,
            'pass' => $pass,
            'secure' => (string) ($cfg['smtp_secure'] ?? 'ssl'),
            'from_name' => (string) ($cfg['from_name'] ?? ''),
            'mailbox' => (string) ($cfg['mailbox_email'] ?? $user),
        );
    }
}

if (!function_exists('email_get_company_smtp_settings')) {
    /**
     * Company-level SMTP from system_settings (admin Email settings).
     * @return array{host:string,port:string,user:string,pass:string,secure:string}|null
     */
    function email_get_company_smtp_settings($pdo = null): ?array
    {
        $settings = array();
        $candidates = array();
        if ($pdo instanceof PDO) {
            $candidates[] = $pdo;
        }
        if (function_exists('email_settings_pdo')) {
            $sp = email_settings_pdo();
            if ($sp instanceof PDO) {
                $candidates[] = $sp;
            }
        }
        if (function_exists('email_module_pdo')) {
            $ep = email_module_pdo();
            if ($ep instanceof PDO) {
                $candidates[] = $ep;
            }
        }
        global $pdo, $control_pdo;
        if ($pdo instanceof PDO) {
            $candidates[] = $pdo;
        }
        if ($control_pdo instanceof PDO) {
            $candidates[] = $control_pdo;
        }

        foreach (array_values(array_unique($candidates, SORT_REGULAR)) as $conn) {
            try {
                if (!($conn instanceof PDO)) {
                    continue;
                }
                $stmt = $conn->prepare(
                    "SELECT setting_key, setting_value FROM system_settings
                     WHERE setting_key IN (
                        'email_smtp_host','email_smtp_port','email_smtp_user','email_smtp_pass','email_smtp_secure'
                     )"
                );
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                if (!empty($rows['email_smtp_host']) && !empty($rows['email_smtp_user'])) {
                    $settings = $rows;
                    break;
                }
            } catch (Throwable $e) {
            }
        }

        $host = trim((string) ($settings['email_smtp_host'] ?? ''));
        $user = trim((string) ($settings['email_smtp_user'] ?? ''));
        if ($host === '' || $user === '') {
            return null;
        }
        return array(
            'host' => $host,
            'port' => (string) ($settings['email_smtp_port'] ?? '465'),
            'user' => $user,
            'pass' => (string) ($settings['email_smtp_pass'] ?? ''),
            'secure' => (string) ($settings['email_smtp_secure'] ?? 'ssl'),
        );
    }
}

if (!function_exists('email_smtp_send_simple')) {
    /**
     * @param array<int,array{path:string,name?:string}> $attachments
     */
    function email_smtp_send_simple(
        string $host,
        $port,
        string $user,
        string $pass,
        string $secure,
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $htmlBody,
        array $attachments = [],
        int $connectTimeout = 5,
        int $readTimeout = 8
    ): bool {
        if (!class_exists('SimpleSMTP')) {
            $smtpPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SimpleSMTP.php';
            if (is_file($smtpPath)) {
                require_once $smtpPath;
            }
        }
        if (!class_exists('SimpleSMTP')) {
            throw new RuntimeException('SimpleSMTP is unavailable.');
        }
        $smtp = new SimpleSMTP($host, $port ?: 465, $user, $pass, $secure ?: 'ssl');
        if (method_exists($smtp, 'setTimeouts')) {
            $smtp->setTimeouts($connectTimeout, $readTimeout);
        }

        // Embed company logo as CID inline part when body references it.
        if (
            is_string($htmlBody)
            && strpos($htmlBody, 'cid:ultitech-company-logo') !== false
            && function_exists('email_company_logo_inline_attachment')
        ) {
            $logoAtt = email_company_logo_inline_attachment();
            if (is_array($logoAtt) && !empty($logoAtt['path'])) {
                array_unshift($attachments, $logoAtt);
            }
        }

        return (bool) $smtp->send(
            $fromEmail,
            $fromName !== '' ? $fromName : $fromEmail,
            $to,
            $subject,
            $htmlBody,
            true,
            $attachments
        );
    }
}

if (!function_exists('email_resolve_preferred_bridge')) {
    /**
     * Detect Ultimate/Roadmaster mailbox from company slug, URI, or recipient hint.
     */
    function email_resolve_preferred_bridge(string $hint = ''): string
    {
        $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? '') . ' ' . ($_SERVER['HTTP_HOST'] ?? ''));
        $slug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
        $hint = strtolower(trim($hint));
        $company = strtolower(trim((string) ($_SESSION['company_name'] ?? '')));

        if (
            $slug === 'roadmaster'
            || strpos($uri, 'roadmaster') !== false
            || strpos($hint, 'roadmaster') !== false
            || strpos($company, 'roadmaster') !== false
        ) {
            return 'roadmaster';
        }
        if (
            $slug === 'ultimate'
            || strpos($uri, 'ultimate') !== false
            || strpos($hint, 'ultimate') !== false
            || strpos($company, 'ultimate') !== false
        ) {
            return 'ultimate';
        }

        // Dev/local packages present under mail-bridges/
        if (function_exists('email_local_bridge_smtp_config')) {
            if (email_local_bridge_smtp_config('ultimate')) {
                return 'ultimate';
            }
            if (email_local_bridge_smtp_config('roadmaster')) {
                return 'roadmaster';
            }
        }

        return '';
    }
}

if (!function_exists('email_outbound_from_name')) {
    /**
     * Display name for outbound company mail (not the logged-in staff user).
     */
    function email_outbound_from_name(string $preferredBridge = ''): string
    {
        $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? '') . ' ' . ($_SERVER['HTTP_HOST'] ?? ''));
        $slug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
        $bridge = strtolower(trim($preferredBridge !== '' ? $preferredBridge : (function_exists('email_resolve_preferred_bridge') ? email_resolve_preferred_bridge() : '')));

        if (
            $bridge === 'ultimate'
            || $slug === 'ultimate'
            || strpos($uri, '/ultimate/') !== false
            || strpos($uri, 'ultimate') !== false
        ) {
            return 'ULTIMATE GENERAL TRADING';
        }

        if (
            $bridge === 'roadmaster'
            || $slug === 'roadmaster'
            || strpos($uri, '/roadmaster/') !== false
            || strpos($uri, 'roadmaster') !== false
        ) {
            return 'ROADMASTER SPARES LIMITED';
        }

        if (function_exists('email_local_bridge_smtp_config') && $bridge !== '') {
            $local = email_local_bridge_smtp_config($bridge);
            $localName = trim((string) ($local['from_name'] ?? ''));
            if ($localName !== '') {
                return $localName;
            }
        }

        $company = trim((string) ($_SESSION['company_name'] ?? ''));
        if ($company !== '' && !preg_match('/^(system\s*)?admin$/i', $company)) {
            return $company;
        }

        if (defined('COMPANY_NAME')) {
            $cn = trim((string) COMPANY_NAME);
            if ($cn !== '' && !preg_match('/^(system\s*)?admin$/i', $cn)) {
                return $cn;
            }
        }

        return 'Staff';
    }
}

if (!function_exists('email_company_logo_disk_path')) {
    /**
     * @return array{path:string,mime:string,name:string}|null
     */
    function email_company_logo_disk_path(): ?array
    {
        $root = dirname(__DIR__, 3);
        $disk = '';

        if (function_exists('getCompanyLogoUrl')) {
            $url = trim((string) getCompanyLogoUrl());
            if ($url !== '') {
                if (preg_match('#^https?://#i', $url)) {
                    $path = parse_url($url, PHP_URL_PATH);
                    if (is_string($path) && $path !== '') {
                        $rel = ltrim($path, '/');
                        if (stripos($rel, 'public_html/') === 0) {
                            $rel = substr($rel, strlen('public_html/'));
                        }
                        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                        if (is_file($candidate)) {
                            $disk = $candidate;
                        }
                    }
                } elseif (strpos($url, 'data:') !== 0) {
                    $rel = ltrim(str_replace('\\', '/', $url), '/');
                    if (stripos($rel, 'public_html/') === 0) {
                        $rel = substr($rel, strlen('public_html/'));
                    }
                    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                    if (is_file($candidate)) {
                        $disk = $candidate;
                    }
                }
            }
        }

        if ($disk === '' && function_exists('currentCompanyId')) {
            $cid = (int) (currentCompanyId() ?? 0);
            if ($cid > 0) {
                $dir = $root . '/assets/images/company_logos/' . $cid;
                if (is_dir($dir)) {
                    $matches = array_merge(
                        glob($dir . '/*.png') ?: array(),
                        glob($dir . '/*.jpg') ?: array(),
                        glob($dir . '/*.jpeg') ?: array(),
                        glob($dir . '/*.webp') ?: array(),
                        glob($dir . '/*.gif') ?: array()
                    );
                    if ($matches !== []) {
                        usort($matches, static function ($a, $b) {
                            return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
                        });
                        $disk = $matches[0];
                    }
                }
            }
        }

        if ($disk === '' || !is_file($disk)) {
            return null;
        }

        return email_prepare_logo_for_email($disk);
    }
}

if (!function_exists('email_prepare_logo_for_email')) {
    /**
     * Shrink oversized logos so SMTP clients accept the inline image.
     * @return array{path:string,mime:string,name:string}|null
     */
    function email_prepare_logo_for_email(string $sourcePath): ?array
    {
        if (!is_file($sourcePath)) {
            return null;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $size = (int) filesize($sourcePath);
        $cacheDir = dirname(__DIR__, 3) . '/uploads/email_logo_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheKey = $cacheDir . '/logo_' . md5($sourcePath . '|' . (string) filemtime($sourcePath) . '|' . $size) . '.png';

        if (is_file($cacheKey) && filesize($cacheKey) > 0) {
            return array(
                'path' => $cacheKey,
                'mime' => 'image/png',
                'name' => 'company-logo.png',
            );
        }

        // Already small enough ? use as-is when under ~180KB.
        if ($size > 0 && $size <= 180000 && in_array($ext, array('png', 'jpg', 'jpeg', 'gif'), true)) {
            $mime = 'image/png';
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $mime = 'image/jpeg';
            } elseif ($ext === 'gif') {
                $mime = 'image/gif';
            }
            return array(
                'path' => $sourcePath,
                'mime' => $mime,
                'name' => 'company-logo.' . $ext,
            );
        }

        if (!function_exists('imagecreatetruecolor')) {
            // No GD: still allow moderately large logos as CID (better than missing image).
            if ($size > 0 && $size <= 1500000) {
                $mime = 'image/png';
                if ($ext === 'jpg' || $ext === 'jpeg') {
                    $mime = 'image/jpeg';
                } elseif ($ext === 'gif') {
                    $mime = 'image/gif';
                } elseif ($ext === 'webp') {
                    $mime = 'image/webp';
                }
                return array(
                    'path' => $sourcePath,
                    'mime' => $mime,
                    'name' => 'company-logo.' . ($ext !== '' ? $ext : 'png'),
                );
            }
            return null;
        }

        $raw = @file_get_contents($sourcePath);
        if ($raw === false || $raw === '') {
            return null;
        }
        $src = @imagecreatefromstring($raw);
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) {
            imagedestroy($src);
            return null;
        }

        $maxW = 280;
        $maxH = 90;
        $scale = min($maxW / $w, $maxH / $h, 1.0);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $ok = @imagepng($dst, $cacheKey, 6);
        imagedestroy($dst);
        if (!$ok || !is_file($cacheKey)) {
            return null;
        }

        return array(
            'path' => $cacheKey,
            'mime' => 'image/png',
            'name' => 'company-logo.png',
        );
    }
}

if (!function_exists('email_company_logo_inline_attachment')) {
    /**
     * Inline CID attachment descriptor for SimpleSMTP.
     * @return array{path:string,name:string,type:string,cid:string,inline:bool}|null
     */
    function email_company_logo_inline_attachment(): ?array
    {
        $info = function_exists('email_company_logo_disk_path') ? email_company_logo_disk_path() : null;
        if (!$info) {
            return null;
        }
        return array(
            'path' => $info['path'],
            'name' => $info['name'],
            'type' => $info['mime'],
            'cid' => 'ultitech-company-logo@ultitech',
            'inline' => true,
        );
    }
}

if (!function_exists('email_wrap_body_with_company_logo')) {
    /**
     * Prepend company logo header to outbound HTML (CID for reliable client display).
     */
    function email_wrap_body_with_company_logo(string $html): string
    {
        $html = (string) $html;
        if ($html !== '' && strpos($html, 'data-ultitech-company-logo') !== false) {
            return $html;
        }
        $info = function_exists('email_company_logo_disk_path') ? email_company_logo_disk_path() : null;
        if (!$info) {
            return $html;
        }

        $alt = '';
        if (function_exists('email_outbound_from_name')) {
            $alt = email_outbound_from_name();
        } elseif (!empty($_SESSION['company_name'])) {
            $alt = (string) $_SESSION['company_name'];
        }
        $altEsc = htmlspecialchars($alt !== '' ? $alt : 'Company', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $header = '<div data-ultitech-company-logo="1" style="margin:0 0 16px 0;padding:0 0 12px 0;border-bottom:1px solid #e2e8f0;">'
            . '<img src="cid:ultitech-company-logo@ultitech" alt="' . $altEsc . '" '
            . 'style="display:block;max-height:64px;max-width:220px;width:auto;height:auto;border:0;outline:none;">'
            . '</div>';

        return $header . $html;
    }
}

if (!function_exists('email_store_bridge_attachments')) {
    /**
     * Persist bridge/IMAP attachment payloads (base64) onto disk + module_email_attachments.
     *
     * @param array<int,array<string,mixed>> $attachments
     */
    function email_store_bridge_attachments(PDO $pdo, int $emailId, array $attachments): int
    {
        if ($emailId <= 0 || $attachments === []) {
            return 0;
        }
        if (function_exists('ensure_email_module_schema')) {
            ensure_email_module_schema($pdo);
        }
        if (function_exists('email_connection_has_table') && !email_connection_has_table($pdo, 'module_email_attachments')) {
            return 0;
        }

        $root = dirname(__DIR__, 3);
        $uploadDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'email_attachments' . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return 0;
        }

        $saved = 0;
        $ins = $pdo->prepare(
            'INSERT INTO module_email_attachments (email_id, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?)'
        );
        $exists = $pdo->prepare(
            'SELECT id FROM module_email_attachments WHERE email_id = ? AND file_name = ? AND file_size = ? LIMIT 1'
        );

        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            $b64 = (string) ($att['content_base64'] ?? '');
            if ($b64 === '') {
                continue;
            }
            $binary = base64_decode($b64, true);
            if ($binary === false || $binary === '') {
                continue;
            }
            $size = strlen($binary);
            // Skip tiny tracking pixels (< 2KB images without real filename)
            $mime = strtolower((string) ($att['mime'] ?? $att['file_type'] ?? 'application/octet-stream'));
            $name = trim((string) ($att['filename'] ?? $att['file_name'] ?? ''));
            if ($name === '' || $name === 'part-1') {
                $ext = 'bin';
                if (strpos($mime, 'pdf') !== false) {
                    $ext = 'pdf';
                } elseif (preg_match('#image/(jpeg|jpg|png|gif|webp)#', $mime, $m)) {
                    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                }
                $name = 'attachment-' . substr(md5($binary), 0, 8) . '.' . $ext;
            }
            $name = preg_replace('/[^A-Za-z0-9._\- ]+/', '_', $name) ?: ('file-' . $emailId . '.bin');

            $exists->execute(array($emailId, $name, $size));
            if ($exists->fetch()) {
                continue;
            }

            $unique = uniqid('', true) . '_' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $name);
            $full = $uploadDir . $unique;
            if (@file_put_contents($full, $binary) === false) {
                continue;
            }
            $rel = 'uploads/email_attachments/' . $unique;
            try {
                $ins->execute(array($emailId, $name, $rel, $size, $mime !== '' ? $mime : 'application/octet-stream'));
                $saved++;
            } catch (Throwable $e) {
                @unlink($full);
            }
        }

        return $saved;
    }
}

if (!function_exists('email_backfill_attachments_from_local_imap')) {
    /**
     * Re-download attachments via local mail-bridges IMAP config (dev / package SMTP host).
     */
    function email_backfill_attachments_from_local_imap(PDO $pdo, int $emailId, string $messageId): int
    {
        if ($emailId <= 0 || trim($messageId) === '' || !function_exists('imap_open')) {
            return 0;
        }
        if (!function_exists('email_local_bridge_imap_config') || !function_exists('email_imap_download_attachments')) {
            return 0;
        }
        try {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM module_email_attachments WHERE email_id = ?');
            $cnt->execute(array($emailId));
            if ((int) $cnt->fetchColumn() > 0) {
                return 0;
            }
        } catch (Throwable $e) {
            return 0;
        }

        $needle = trim($messageId, '<> ');
        $keys = array('ultimate', 'roadmaster');
        if (function_exists('email_resolve_preferred_bridge')) {
            $pref = email_resolve_preferred_bridge();
            if ($pref !== '') {
                array_unshift($keys, $pref);
                $keys = array_values(array_unique($keys));
            }
        }

        foreach ($keys as $key) {
            $settings = email_local_bridge_imap_config($key);
            if (!$settings) {
                continue;
            }
            $host = (string) ($settings['email_imap_host'] ?? '');
            $user = (string) ($settings['email_imap_user'] ?? '');
            $pass = (string) ($settings['email_imap_pass'] ?? '');
            if ($host === '' || $user === '' || $pass === '') {
                continue;
            }
            $port = (string) ($settings['email_imap_port'] ?? '993');
            $ssl = (string) ($settings['email_imap_ssl'] ?? 'ssl');
            $mboxPath = '{' . $host . ':' . $port . '/imap/' . $ssl . '/novalidate-cert}INBOX';
            $mbox = @imap_open($mboxPath, $user, $pass, 0, 1, array('DISABLE_AUTHENTICATOR' => 'GSSAPI'));
            if (!$mbox) {
                if (function_exists('imap_errors')) {
                    @imap_errors();
                    @imap_alerts();
                }
                continue;
            }
            try {
                // Prefer overview scan over HEADER Message-ID search (often very slow on cPanel).
                $msgno = 0;
                $all = @imap_search($mbox, 'ALL');
                if (is_array($all) && $all !== []) {
                    rsort($all);
                    $all = array_slice($all, 0, 250);
                    $chunks = array_chunk($all, 50);
                    foreach ($chunks as $chunk) {
                        $ovs = @imap_fetch_overview($mbox, implode(',', $chunk), 0);
                        if (!is_array($ovs)) {
                            continue;
                        }
                        foreach ($ovs as $ov) {
                            $mid = trim((string) ($ov->message_id ?? ''), '<> ');
                            if ($mid !== '' && strcasecmp($mid, $needle) === 0) {
                                $msgno = (int) ($ov->msgno ?? 0);
                                break 2;
                            }
                        }
                    }
                }
                if ($msgno < 1) {
                    $found = @imap_search($mbox, 'HEADER Message-ID "' . $needle . '"');
                    if (!$found) {
                        $found = @imap_search($mbox, 'HEADER Message-ID "<' . $needle . '>"');
                    }
                    if ($found && !empty($found[0])) {
                        $msgno = (int) $found[0];
                    }
                }
                if ($msgno < 1) {
                    continue;
                }
                email_imap_download_attachments($mbox, $msgno, $emailId, $pdo);
                $cnt->execute(array($emailId));
                $n = (int) $cnt->fetchColumn();
                if ($n > 0) {
                    return $n;
                }
            } finally {
                @imap_close($mbox);
            }
        }
        return 0;
    }
}

if (!function_exists('email_backfill_attachments_from_bridge')) {
    /**
     * If a stored message has no local attachments, try remote bridges then optional IMAP.
     */
    function email_backfill_attachments_from_bridge(PDO $pdo, int $emailId, string $messageId, bool $allowSlowImap = false): int
    {
        if ($emailId <= 0 || trim($messageId) === '') {
            return 0;
        }
        try {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM module_email_attachments WHERE email_id = ?');
            $cnt->execute(array($emailId));
            if ((int) $cnt->fetchColumn() > 0) {
                return 0;
            }
        } catch (Throwable $e) {
            return 0;
        }

        // Prefer remote HTTP (bounded timeout) before local IMAP scans.
        if (function_exists('email_get_remote_bridges') && function_exists('email_bridge_http_get')) {
            foreach (email_get_remote_bridges() as $bridge) {
                if (empty($bridge['enabled']) || empty($bridge['url']) || empty($bridge['api_key'])) {
                    continue;
                }
                try {
                    $url = rtrim((string) $bridge['url'], '/') . '/api/message.php?message_id=' . rawurlencode($messageId);
                    $payload = email_bridge_http_get($url, (string) $bridge['api_key'], 20);
                    $msg = is_array($payload['message'] ?? null) ? $payload['message'] : null;
                    if ($msg && !empty($msg['attachments']) && is_array($msg['attachments'])) {
                        $saved = email_store_bridge_attachments($pdo, $emailId, $msg['attachments']);
                        if ($saved > 0) {
                            return $saved;
                        }
                    }
                } catch (Throwable $e) {
                    // try next / fall through to IMAP
                }
            }
        }

        if ($allowSlowImap && function_exists('email_backfill_attachments_from_local_imap')) {
            $local = email_backfill_attachments_from_local_imap($pdo, $emailId, $messageId);
            if ($local > 0) {
                return $local;
            }
        }

        return 0;
    }
}

if (!function_exists('email_bridge_http_post')) {
    function email_bridge_http_post(string $url, string $apiKey, array $payload, int $timeout = 30): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required to send via remote mail bridges.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(4, max(1, (int) $timeout)),
            CURLOPT_TIMEOUT => max(1, (int) $timeout),
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
                'Authorization: Bearer ' . $apiKey,
            ),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Bridge send failed: ' . $err);
        }
        $raw = (string) $body;
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        $jsonStart = strpos($raw, '{');
        if ($jsonStart !== false && $jsonStart > 0) {
            $raw = substr($raw, $jsonStart);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('Mail bridge send returned invalid JSON.');
        }
        if ($code >= 400 || (($json['status'] ?? '') === 'error')) {
            throw new RuntimeException((string) ($json['message'] ?? ('HTTP ' . $code)));
        }
        return $json;
    }
}

if (!function_exists('email_bridge_send_mail')) {
    /**
     * Send outbound mail through the first usable remote bridge (or a preferred key).
     * @param array<int,array{path:string,name?:string}> $attachments
     * @return array{ok:bool, bridge?:string, from?:string, error?:string}
     */
    function email_bridge_send_mail(
        string $to,
        string $subject,
        string $htmlBody,
        string $fromName = '',
        string $preferredKey = '',
        array $attachments = []
    ): array {
        $tryLocal = static function (
            string $key,
            string $to,
            string $subject,
            string $htmlBody,
            string $fromName,
            array $attachments
        ): array {
            if ($key === '' || !function_exists('email_local_bridge_smtp_config') || !function_exists('email_smtp_send_simple')) {
                return array('ok' => false, 'error' => 'No local SMTP.');
            }
            $local = email_local_bridge_smtp_config($key);
            if (!$local) {
                return array('ok' => false, 'error' => 'No local SMTP for ' . $key);
            }
            try {
                $from = $local['mailbox'] !== '' ? $local['mailbox'] : $local['user'];
                $connectTimeout = $attachments !== [] ? 15 : 5;
                $readTimeout = $attachments !== [] ? 90 : 20;
                $ok = email_smtp_send_simple(
                    $local['host'],
                    $local['port'],
                    $local['user'],
                    $local['pass'],
                    $local['secure'],
                    $from,
                    $fromName !== '' ? $fromName : (string) $local['from_name'],
                    $to,
                    $subject,
                    $htmlBody,
                    $attachments,
                    $connectTimeout,
                    $readTimeout
                );
                if ($ok) {
                    return array(
                        'ok' => true,
                        'bridge' => $key,
                        'from' => $from,
                        'via' => 'local-smtp',
                    );
                }
                return array('ok' => false, 'error' => 'Local SMTP send failed for ' . $key);
            } catch (Throwable $e) {
                return array('ok' => false, 'error' => $e->getMessage());
            }
        };

        // Fast path: preferred mailbox SMTP only (skip Cloudflare HTTP).
        $lastError = 'No remote bridge configured.';
        $triedKeys = array();
        if ($preferredKey !== '') {
            $fast = $tryLocal($preferredKey, $to, $subject, $htmlBody, $fromName, $attachments);
            if (!empty($fast['ok'])) {
                return $fast;
            }
            $triedKeys[$preferredKey] = true;
            if (!empty($fast['error'])) {
                $lastError = (string) $fast['error'];
            }
        }

        $bridges = email_get_remote_bridges();
        if ($preferredKey !== '') {
            usort($bridges, static function ($a, $b) use ($preferredKey) {
                if (($a['key'] ?? '') === $preferredKey) {
                    return -1;
                }
                if (($b['key'] ?? '') === $preferredKey) {
                    return 1;
                }
                return 0;
            });
        }

        foreach ($bridges as $bridge) {
            if (empty($bridge['enabled']) || empty($bridge['url']) || empty($bridge['api_key'])) {
                continue;
            }
            $bridgeKey = (string) ($bridge['key'] ?? '');

            // Prefer local SMTP; never call Cloudflare HTTP when package SMTP exists.
            if (function_exists('email_local_bridge_smtp_config') && email_local_bridge_smtp_config($bridgeKey)) {
                if (!isset($triedKeys[$bridgeKey])) {
                    $triedKeys[$bridgeKey] = true;
                    $localAttempt = $tryLocal($bridgeKey, $to, $subject, $htmlBody, $fromName, $attachments);
                    if (!empty($localAttempt['ok'])) {
                        return $localAttempt;
                    }
                    if (!empty($localAttempt['error'])) {
                        $lastError = (string) $localAttempt['error'];
                    }
                }
                continue;
            }

            // HTTP bridge cannot carry binary attachments reliably.
            if ($attachments !== []) {
                $lastError = 'Attachments require local SMTP for ' . ($bridgeKey !== '' ? $bridgeKey : 'bridge');
                continue;
            }

            $triedKeys[$bridgeKey] = true;
            try {
                $payload = array(
                    'to' => $to,
                    'subject' => $subject,
                    'body' => $htmlBody,
                    'is_html' => true,
                );
                if ($fromName !== '') {
                    $payload['from_name'] = $fromName;
                }
                email_bridge_http_post(rtrim($bridge['url'], '/') . '/api/send.php', $bridge['api_key'], $payload, 6);
                return array(
                    'ok' => true,
                    'bridge' => $bridgeKey,
                    'from' => (string) ($bridge['mailbox'] ?? ''),
                    'via' => 'http',
                );
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'invalid JSON') !== false || stripos($msg, 'HTML') !== false) {
                    $lastError = 'Remote bridge blocked (Cloudflare) for ' . $bridgeKey . '.';
                } else {
                    $lastError = $msg;
                }
            }
        }

        foreach (array('ultimate', 'roadmaster') as $k) {
            if (isset($triedKeys[$k])) {
                continue;
            }
            $attempt = $tryLocal($k, $to, $subject, $htmlBody, $fromName, $attachments);
            if (!empty($attempt['ok'])) {
                return $attempt;
            }
            if (!empty($attempt['error'])) {
                $lastError = (string) $attempt['error'];
            }
        }

        return array('ok' => false, 'error' => $lastError);
    }
}

if (!function_exists('email_bridge_http_get')) {
    function email_bridge_http_get(string $url, string $apiKey, int $timeout = 25): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required to sync remote mail bridges.');
        }
        $apiKey = trim($apiKey);
        // Query fallback when proxies strip Authorization / X-Api-Key (Ultimate host).
        if ($apiKey !== '' && stripos($url, 'api_key=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'api_key=' . rawurlencode($apiKey);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            // Some hosts (Cloudflare / StackCDN) return HTML 403 to default PHP curl UA.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; UltitechMailBridge/1.0; +https://ultitech.io)',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'X-Api-Key: ' . $apiKey,
                'Authorization: Bearer ' . $apiKey,
            ),
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Bridge request failed: ' . $err);
        }
        $raw = (string) $body;
        // Strip UTF-8 BOM / leading PHP warnings so JSON can still parse.
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        $jsonStart = strpos($raw, '{');
        if ($jsonStart !== false && $jsonStart > 0) {
            $raw = substr($raw, $jsonStart);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $hint = ($code === 403)
                ? ' Bridge host returned HTTP 403 (WAF/CDN blocked Ultitech). Whitelist the Ultitech server IP or use company IMAP.'
                : '';
            throw new RuntimeException('Mail bridge is temporarily unavailable (HTTP ' . $code . ').' . $hint);
        }
        if ($code >= 400 || (($json['status'] ?? '') === 'error')) {
            throw new RuntimeException((string) ($json['message'] ?? ('HTTP ' . $code)));
        }
        return $json;
    }
}

if (!function_exists('email_has_enabled_remote_bridges')) {
    function email_has_enabled_remote_bridges(): bool
    {
        if (!function_exists('email_get_remote_bridges')) {
            $path = __DIR__ . '/email_remote_bridges.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (!function_exists('email_get_remote_bridges')) {
            return false;
        }
        foreach (email_get_remote_bridges() as $bridge) {
            if (!empty($bridge['enabled']) && !empty($bridge['url']) && !empty($bridge['api_key'])) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('email_has_company_imap')) {
    function email_has_company_imap(): bool
    {
        $settings = function_exists('email_get_imap_settings') ? email_get_imap_settings() : array();
        return !empty($settings['email_imap_host']) && !empty($settings['email_imap_user']);
    }
}

if (!function_exists('email_source_is_configured')) {
    /**
     * True when personal IMAP, company IMAP, or an enabled remote bridge exists.
     */
    function email_source_is_configured(?array $userSettings = null): bool
    {
        if (!empty($userSettings['imap_host']) && !empty($userSettings['imap_user'])) {
            return true;
        }
        if (email_has_company_imap()) {
            return true;
        }
        return email_has_enabled_remote_bridges();
    }
}

if (!function_exists('email_extract_mailbox_address')) {
    function email_extract_mailbox_address(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $value, $m)) {
            return strtolower($m[0]);
        }
        return strtolower($value);
    }
}

if (!function_exists('email_company_mailbox_addresses')) {
    /**
     * Mailboxes for the active company only (Ultimate vs Roadmaster).
     * Mixing both addresses made Ultimate show Roadmaster test mail and
     * poisoned the incremental sync cursor.
     *
     * @return list<string>
     */
    function email_company_mailbox_addresses(): array
    {
        static $cached = array();
        $preferred = function_exists('email_resolve_preferred_bridge')
            ? email_resolve_preferred_bridge()
            : '';
        $cacheKey = $preferred !== '' ? $preferred : '*';
        if (isset($cached[$cacheKey]) && is_array($cached[$cacheKey])) {
            return $cached[$cacheKey];
        }

        $addrs = array();
        if ($preferred === 'roadmaster') {
            $addrs[] = 'sales@roadmasterspares.com';
        } elseif ($preferred === 'ultimate') {
            $addrs[] = 'sales@ultimate.co.tz';
        } else {
            $addrs[] = 'sales@ultimate.co.tz';
            $addrs[] = 'sales@roadmasterspares.com';
        }

        $allowAddr = static function (string $addr) use ($preferred): bool {
            if ($addr === '') {
                return false;
            }
            if ($preferred === '') {
                return true;
            }
            if ($preferred === 'roadmaster') {
                return strpos($addr, 'roadmaster') !== false;
            }
            if ($preferred === 'ultimate') {
                return strpos($addr, 'ultimate') !== false;
            }
            return true;
        };

        if (function_exists('email_get_imap_settings')) {
            $imap = email_get_imap_settings();
            $imapUser = email_extract_mailbox_address((string) ($imap['email_imap_user'] ?? ''));
            if ($allowAddr($imapUser)) {
                $addrs[] = $imapUser;
            }
        }

        if (function_exists('email_get_remote_bridges')) {
            foreach (email_get_remote_bridges() as $bridge) {
                $key = (string) ($bridge['key'] ?? '');
                if ($preferred !== '' && $key !== '' && $key !== $preferred) {
                    continue;
                }
                $mb = email_extract_mailbox_address((string) ($bridge['mailbox'] ?? ''));
                if ($allowAddr($mb)) {
                    $addrs[] = $mb;
                }
            }
        }

        $localKeys = ($preferred === 'ultimate' || $preferred === 'roadmaster')
            ? array($preferred)
            : array('ultimate', 'roadmaster');

        if (function_exists('email_local_bridge_imap_config')) {
            foreach ($localKeys as $key) {
                $cfg = email_local_bridge_imap_config($key);
                if (!is_array($cfg)) {
                    continue;
                }
                $mb = email_extract_mailbox_address((string) ($cfg['email_imap_user'] ?? ''));
                if ($allowAddr($mb)) {
                    $addrs[] = $mb;
                }
            }
        }

        if (function_exists('email_local_bridge_smtp_config')) {
            foreach ($localKeys as $key) {
                $cfg = email_local_bridge_smtp_config($key);
                if (!is_array($cfg)) {
                    continue;
                }
                $mb = email_extract_mailbox_address((string) ($cfg['mailbox'] ?? $cfg['user'] ?? ''));
                if ($allowAddr($mb)) {
                    $addrs[] = $mb;
                }
            }
        }

        $cached[$cacheKey] = array_values(array_unique(array_filter($addrs)));
        return $cached[$cacheKey];
    }
}

if (!function_exists('email_company_mailbox_where_sql')) {
    /**
     * Keep this company's tenant inbox, but hide the other brand's mailbox.
     * Requiring a match on sales@ultimate.co.tz hid real Ultimate mail whose
     * To: header was empty, a personal address, or undisclosed-recipients.
     *
     * @return array{sql:string,params:list<string>}
     */
    function email_company_mailbox_where_sql(string $alias = 'e'): array
    {
        $preferred = function_exists('email_resolve_preferred_bridge')
            ? email_resolve_preferred_bridge()
            : '';
        $foreign = array();
        if ($preferred === 'ultimate') {
            $foreign[] = 'sales@roadmasterspares.com';
        } elseif ($preferred === 'roadmaster') {
            $foreign[] = 'sales@ultimate.co.tz';
        }
        if ($foreign === []) {
            return array('sql' => '1=1', 'params' => array());
        }
        $prefix = $alias !== '' ? $alias . '.' : '';
        $parts = array();
        $params = array();
        foreach ($foreign as $addr) {
            $parts[] = 'LOWER(' . $prefix . 'recipient_email) NOT LIKE ?';
            $params[] = '%' . $addr . '%';
            $parts[] = 'LOWER(' . $prefix . 'sender_email) NOT LIKE ?';
            $params[] = '%' . $addr . '%';
        }
        return array('sql' => '(' . implode(' AND ', $parts) . ')', 'params' => $params);
    }
}

if (!function_exists('email_is_company_mailbox')) {
    function email_is_company_mailbox(string $value): bool
    {
        $addr = email_extract_mailbox_address($value);
        if ($addr === '') {
            return false;
        }
        return in_array($addr, email_company_mailbox_addresses(), true);
    }
}

if (!function_exists('email_share_company_mailbox_rows')) {
    /**
     * Company inbox is shared (user_id=0). Reassign rows that were saved under
     * the staff member who clicked Sync so every user sees current mail.
     */
    function email_share_company_mailbox_rows(PDO $pdo): void
    {
        static $done = false;
        if ($done || !($pdo instanceof PDO)) {
            return;
        }
        $done = true;

        $addrs = email_company_mailbox_addresses();
        if ($addrs === []) {
            return;
        }

        $likes = array();
        $params = array();
        foreach ($addrs as $addr) {
            $likes[] = 'LOWER(recipient_email) LIKE ?';
            $params[] = '%' . $addr . '%';
            $likes[] = 'LOWER(sender_email) LIKE ?';
            $params[] = '%' . $addr . '%';
        }

        try {
            $sql = 'UPDATE module_emails SET user_id = 0 WHERE user_id <> 0 AND (' . implode(' OR ', $likes) . ')';
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } catch (Throwable $e) {
            error_log('email_share_company_mailbox_rows: ' . $e->getMessage());
        }
    }
}

if (!function_exists('email_existing_message_id_set')) {
    /**
     * @param list<string> $messageIds
     * @return array<string, true>
     */
    function email_existing_message_id_set(PDO $pdo, array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_filter(array_map('strval', $messageIds), static function ($id) {
            return $id !== '';
        })));
        if ($messageIds === []) {
            return array();
        }
        $found = array();
        foreach (array_chunk($messageIds, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT message_id FROM module_emails WHERE message_id IN ($placeholders)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: array() as $id) {
                $id = (string) $id;
                if ($id !== '') {
                    $found[$id] = true;
                }
            }
        }
        return $found;
    }
}

if (!function_exists('email_bridge_incremental_since')) {
    /**
     * Cursor for incremental sync: newest inbound mail minus overlap window.
     */
    function email_bridge_incremental_since(PDO $pdo, int $overlapHours = 36): ?string
    {
        try {
            $filter = function_exists('email_company_mailbox_where_sql')
                ? email_company_mailbox_where_sql('')
                : array('sql' => '1=1', 'params' => array());
            $st = $pdo->prepare(
                "SELECT MAX(created_at) FROM module_emails WHERE direction = 'inbound' AND " . $filter['sql']
            );
            $st->execute($filter['params']);
            $max = $st->fetchColumn();
            if (!$max) {
                return null;
            }
            $ts = strtotime((string) $max);
            if (!$ts) {
                return null;
            }
            return date('c', max(0, $ts - max(1, $overlapHours) * 3600));
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('email_sync_local_package_inbox')) {
    /**
     * Sync inbox via local mail-bridges/{key} ImapService (includes attachment payloads).
     * Also backfills attachments onto already-imported rows.
     *
     * @return array{new_count:int, attachments_backfilled:int}
     */
    function email_sync_local_package_inbox(PDO $pdo, int $limit = 40, int $userId = 0): array
    {
        $out = array('new_count' => 0, 'attachments_backfilled' => 0);
        if (!($pdo instanceof PDO) || !function_exists('imap_open')) {
            return $out;
        }
        // Company mailbox is shared: always store as user_id=0, not the person who clicked Sync.
        $userId = 0;
        if (function_exists('email_share_company_mailbox_rows')) {
            email_share_company_mailbox_rows($pdo);
        }
        $key = function_exists('email_resolve_preferred_bridge') ? email_resolve_preferred_bridge() : '';
        if ($key === '') {
            $key = 'ultimate';
        }
        $root = dirname(__DIR__, 3);
        $pkg = $root . DIRECTORY_SEPARATOR . 'mail-bridges' . DIRECTORY_SEPARATOR . $key;
        $cfgPath = $pkg . DIRECTORY_SEPARATOR . 'config.php';
        $imapClass = $pkg . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ImapService.php';
        if (!is_file($cfgPath) || !is_file($imapClass)) {
            return $out;
        }
        if (!class_exists('ImapService', false)) {
            require_once $imapClass;
        }
        if (!class_exists('ImapService')) {
            return $out;
        }

        $cfg = include $cfgPath;
        if (!is_array($cfg)) {
            return $out;
        }

        $imapHost = trim((string) ($cfg['imap_host'] ?? ''));
        $imapPort = (int) ($cfg['imap_port'] ?? 993);
        if ($imapPort < 1) {
            $imapPort = 993;
        }
        if ($imapHost === '' || (function_exists('email_imap_tcp_reachable') && !email_imap_tcp_reachable($imapHost, $imapPort, 3.0))) {
            return $out;
        }

        $pageLimit = max(1, min(100, $limit));
        $maxPages = 3;
        $allMessages = array();
        try {
            $imap = new ImapService($cfg);
            $imap->connect();
            $prevFingerprint = '';
            for ($page = 0; $page < $maxPages; $page++) {
                $offset = $page * $pageLimit;
                if (method_exists($imap, 'listMessages')) {
                    $messages = $imap->listMessages($pageLimit, null, $offset);
                } else {
                    $messages = array();
                }
                if (!is_array($messages) || $messages === []) {
                    break;
                }
                $fingerprints = array();
                foreach ($messages as $msg) {
                    if (is_array($msg)) {
                        $fingerprints[] = trim((string) ($msg['message_id'] ?? $msg['subject'] ?? ''));
                    }
                }
                $fingerprint = implode('|', $fingerprints);
                if ($page > 0 && $fingerprint !== '' && $fingerprint === $prevFingerprint) {
                    break;
                }
                $prevFingerprint = $fingerprint;
                foreach ($messages as $msg) {
                    $allMessages[] = $msg;
                }
                if (count($messages) < $pageLimit) {
                    break;
                }
            }
            $imap->close();
        } catch (Throwable $e) {
            return $out;
        }

        $messages = $allMessages;
        if (!is_array($messages) || $messages === []) {
            return $out;
        }

        $msgIds = array();
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $mid = trim((string) ($msg['message_id'] ?? ''));
            if ($mid !== '') {
                $msgIds[] = $mid;
            }
        }
        $existing = function_exists('email_existing_message_id_set')
            ? email_existing_message_id_set($pdo, $msgIds)
            : array();

        $ins = $pdo->prepare(
            "INSERT INTO module_emails (user_id, sender_email, recipient_email, subject, body, direction, status, created_at, message_id)
             VALUES (?, ?, ?, ?, ?, 'inbound', ?, ?, ?)"
        );

        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $msgId = trim((string) ($msg['message_id'] ?? ''));
            $sender = trim((string) ($msg['from'] ?? ''));
            if ($sender === '') {
                continue;
            }
            $subject = trim((string) ($msg['subject'] ?? '(no subject)'));
            $recipient = trim((string) ($msg['to'] ?? ($msg['mailbox'] ?? '')));
            $body = (string) ($msg['body'] ?? '');
            if ($body === '' && !empty($msg['body_html'])) {
                $body = (string) $msg['body_html'];
            }
            if ($body === '' && !empty($msg['body_text'])) {
                $body = nl2br(htmlspecialchars((string) $msg['body_text'], ENT_QUOTES, 'UTF-8'));
            }
            if ($body !== '' && stripos($body, 'cid:') !== false && !empty($msg['attachments']) && is_array($msg['attachments']) && function_exists('email_embed_cids_from_attachment_list')) {
                $body = email_embed_cids_from_attachment_list($body, $msg['attachments']);
            }
            $createdAt = date('Y-m-d H:i:s');
            if (!empty($msg['date'])) {
                $ts = strtotime((string) $msg['date']);
                if ($ts) {
                    $createdAt = date('Y-m-d H:i:s', $ts);
                }
            }
            $atts = (!empty($msg['attachments']) && is_array($msg['attachments'])) ? $msg['attachments'] : array();

            if ($msgId !== '' && isset($existing[$msgId])) {
                if ($atts !== []) {
                    try {
                        $find = $pdo->prepare('SELECT id FROM module_emails WHERE message_id = ? LIMIT 1');
                        $find->execute(array($msgId));
                        $eid = (int) $find->fetchColumn();
                        if ($eid > 0) {
                            $cnt = $pdo->prepare('SELECT COUNT(*) FROM module_email_attachments WHERE email_id = ?');
                            $cnt->execute(array($eid));
                            if ((int) $cnt->fetchColumn() === 0) {
                                $out['attachments_backfilled'] += email_store_bridge_attachments($pdo, $eid, $atts);
                            }
                        }
                    } catch (Throwable $e) {
                    }
                }
                continue;
            }

            $status = 'unread';
            if (function_exists('email_is_sender_blocked') && email_is_sender_blocked($sender, $pdo)) {
                $status = 'spam';
            }
            try {
                $ins->execute(array(
                    $userId,
                    $sender,
                    $recipient,
                    $subject,
                    $body,
                    $status,
                    $createdAt,
                    $msgId !== '' ? $msgId : null,
                ));
                $newId = (int) $pdo->lastInsertId();
                if ($newId > 0 && $atts !== [] && $status !== 'spam') {
                    email_store_bridge_attachments($pdo, $newId, $atts);
                }
                if ($msgId !== '') {
                    $existing[$msgId] = true;
                }
                $out['new_count']++;
            } catch (Throwable $e) {
            }
        }

        return $out;
    }
}

if (!function_exists('email_sync_remote_bridges')) {
    /**
     * Incremental bridge sync:
     * 1) Pull only messages since last known inbound date (with overlap)
     * 2) Batch-dedupe by message_id (one IN query, not N lookups)
     * 3) Insert new rows in a single transaction
     *
     * @return array{new_count:int, bridges:array, errors:array}
     */
    function email_sync_remote_bridges($pdo, int $limitPerBridge = 50, int $userId = 0, int $httpTimeout = 20): array
    {
        $result = array('new_count' => 0, 'bridges' => array(), 'errors' => array());
        if (!($pdo instanceof PDO)) {
            return $result;
        }
        // Company mailbox is shared: always store as user_id=0, not the person who clicked Sync.
        $userId = 0;
        if (function_exists('email_share_company_mailbox_rows')) {
            email_share_company_mailbox_rows($pdo);
        }

        $bridges = email_get_remote_bridges();
        $preferred = function_exists('email_resolve_preferred_bridge') ? email_resolve_preferred_bridge() : '';
        if ($preferred !== '') {
            $only = array();
            foreach ($bridges as $bridge) {
                if ((string) ($bridge['key'] ?? '') === $preferred) {
                    $only[] = $bridge;
                }
            }
            if ($only !== []) {
                $bridges = $only;
            }
        }

        // Do not pass `since`: a newer other-mailbox row used to skip months of this mailbox.
        // Newest-first pages catch up the gap. Offset is ignored by older bridges (we detect that).
        $limit = max(1, min(100, $limitPerBridge));
        $maxPages = ($httpTimeout <= 12) ? 1 : 3;

        foreach ($bridges as $bridge) {
            if (empty($bridge['enabled'])) {
                continue;
            }
            $key = $bridge['key'];
            try {
                $importedTotal = 0;
                $fetchedTotal = 0;
                $attBackfilled = 0;
                $prevFingerprint = '';

                for ($page = 0; $page < $maxPages; $page++) {
                    $offset = $page * $limit;
                    $qs = 'limit=' . $limit . '&offset=' . $offset;
                    $url = $bridge['url'] . '/api/messages.php?' . $qs;
                    $payload = email_bridge_http_get($url, $bridge['api_key'], max(5, $httpTimeout));
                    $messages = $payload['messages'] ?? array();
                    if (!is_array($messages) || $messages === []) {
                        if ($page === 0) {
                            $result['bridges'][$key] = array('imported' => 0, 'fetched' => 0, 'offset' => $offset);
                        }
                        break;
                    }

                    $fingerprints = array();
                    foreach ($messages as $msg) {
                        if (is_array($msg)) {
                            $fingerprints[] = trim((string) ($msg['message_id'] ?? $msg['subject'] ?? ''));
                        }
                    }
                    $fingerprint = implode('|', $fingerprints);
                    if ($page > 0 && $fingerprint !== '' && $fingerprint === $prevFingerprint) {
                        break;
                    }
                    $prevFingerprint = $fingerprint;
                    $fetchedTotal += count($messages);

                $candidates = array();
                $msgIds = array();
                foreach ($messages as $msg) {
                    if (!is_array($msg)) {
                        continue;
                    }
                    $msgId = trim((string) ($msg['message_id'] ?? ''));
                    $sender = trim((string) ($msg['from'] ?? ''));
                    $subject = trim((string) ($msg['subject'] ?? '(no subject)'));
                    if ($sender === '') {
                        continue;
                    }
                    $recipient = trim((string) ($msg['to'] ?? ($msg['mailbox'] ?? ($payload['mailbox'] ?? ''))));
                    $body = (string) ($msg['body'] ?? '');
                    if ($body === '' && !empty($msg['body_html'])) {
                        $body = (string) $msg['body_html'];
                    }
                    if ($body === '' && !empty($msg['body_text'])) {
                        $body = nl2br(htmlspecialchars((string) $msg['body_text'], ENT_QUOTES, 'UTF-8'));
                    }
                    if ($body !== '' && stripos($body, 'cid:') !== false && !empty($msg['attachments']) && is_array($msg['attachments']) && function_exists('email_embed_cids_from_attachment_list')) {
                        $body = email_embed_cids_from_attachment_list($body, $msg['attachments']);
                    }
                    $createdAt = date('Y-m-d H:i:s');
                    if (!empty($msg['date'])) {
                        $ts = strtotime((string) $msg['date']);
                        if ($ts) {
                            $createdAt = date('Y-m-d H:i:s', $ts);
                        }
                    }
                    $candidates[] = array(
                        'message_id' => $msgId,
                        'sender' => $sender,
                        'recipient' => $recipient,
                        'subject' => $subject,
                        'body' => $body,
                        'created_at' => $createdAt,
                        'attachments' => (!empty($msg['attachments']) && is_array($msg['attachments']))
                            ? $msg['attachments']
                            : array(),
                    );
                    if ($msgId !== '') {
                        $msgIds[] = $msgId;
                    }
                }

                $existingIds = email_existing_message_id_set($pdo, $msgIds);

                // Backfill attachments for already-imported messages (older syncs dropped them).
                foreach ($candidates as $c) {
                    $mid = (string) ($c['message_id'] ?? '');
                    if ($mid === '' || empty($c['attachments']) || !is_array($c['attachments'])) {
                        continue;
                    }
                    if (!isset($existingIds[$mid])) {
                        continue;
                    }
                    try {
                        $find = $pdo->prepare('SELECT id FROM module_emails WHERE message_id = ? LIMIT 1');
                        $find->execute(array($mid));
                        $eid = (int) $find->fetchColumn();
                        if ($eid <= 0) {
                            continue;
                        }
                        $cnt = $pdo->prepare('SELECT COUNT(*) FROM module_email_attachments WHERE email_id = ?');
                        $cnt->execute(array($eid));
                        if ((int) $cnt->fetchColumn() > 0) {
                            continue;
                        }
                        $attBackfilled += email_store_bridge_attachments($pdo, $eid, $c['attachments']);
                    } catch (Throwable $e) {
                    }
                }

                $toInsert = array();
                $seenInBatch = array();
                foreach ($candidates as $c) {
                    $mid = $c['message_id'];
                    if ($mid !== '') {
                        if (isset($existingIds[$mid]) || isset($seenInBatch[$mid])) {
                            continue;
                        }
                        $seenInBatch[$mid] = true;
                    } else {
                        // Fallback fingerprint for rare messages without Message-ID
                        $fp = strtolower($c['sender'] . '|' . $c['subject'] . '|' . $c['created_at']);
                        if (isset($seenInBatch[$fp])) {
                            continue;
                        }
                        $seenInBatch[$fp] = true;
                        $chk = $pdo->prepare('SELECT id FROM module_emails WHERE sender_email = ? AND subject = ? AND created_at = ? LIMIT 1');
                        $chk->execute(array($c['sender'], $c['subject'], $c['created_at']));
                        if ($chk->fetch()) {
                            continue;
                        }
                    }
                    $toInsert[] = $c;
                }

                if ($toInsert === []) {
                    break;
                }

                $ins = $pdo->prepare("INSERT INTO module_emails (user_id, sender_email, recipient_email, subject, body, direction, status, created_at, message_id) VALUES (?, ?, ?, ?, ?, 'inbound', ?, ?, ?)");
                $pdo->beginTransaction();
                try {
                    foreach ($toInsert as $c) {
                        $status = 'unread';
                        if (function_exists('email_is_sender_blocked') && email_is_sender_blocked($c['sender'], $pdo)) {
                            $status = 'spam';
                        }
                        $ins->execute(array(
                            $userId,
                            $c['sender'],
                            $c['recipient'],
                            $c['subject'],
                            $c['body'],
                            $status,
                            $c['created_at'],
                            $c['message_id'] !== '' ? $c['message_id'] : null,
                        ));
                        $newId = (int) $pdo->lastInsertId();
                        if ($newId > 0 && !empty($c['attachments']) && is_array($c['attachments']) && $status !== 'spam') {
                            email_store_bridge_attachments($pdo, $newId, $c['attachments']);
                        }
                        $importedTotal++;
                        $result['new_count']++;
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }

                if (count($messages) < $limit) {
                    break;
                }
                } // page loop

                $result['bridges'][$key] = array(
                    'imported' => $importedTotal,
                    'fetched' => $fetchedTotal,
                    'pages' => $maxPages,
                    'attachments_backfilled' => $attBackfilled,
                );
            } catch (Throwable $e) {
                $result['errors'][$key] = $e->getMessage();
            }
        }
        return $result;
    }
}
