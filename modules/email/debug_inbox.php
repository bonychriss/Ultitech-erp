<?php
/**
 * Email inbox diagnostics � compare localhost vs live.
 *
 * Open while logged in (admin recommended):
 *   /modules/email/debug_inbox.php
 *   /ultimate/modules/email/debug_inbox.php  (company path)
 *
 * Optional query flags:
 *   ?probe=1     Hit remote bridges and report fetch counts
 *   ?share=1     Reassign company mailbox rows to user_id=0
 *   ?json=1      Machine-readable JSON instead of HTML
 *
 * Remove or restrict this file after debugging on production.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('max_execution_time', '45');
@set_time_limit(45);
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/includes/email_bootstrap.php';
require_once __DIR__ . '/includes/email_remote_bridges.php';

requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = function_exists('isAdmin') ? (bool) isAdmin() : false;

// probe=1|bridges = HTTP bridges only (fast, safe on live)
// probe=imap     = IMAP only (can hang if port 993 blocked � use after bridges)
// probe=sync     = run bridge sync import sample
// probe=all      = bridges + imap + sync (slow; may blank-page on live)
$probeRaw = strtolower(trim((string) ($_GET['probe'] ?? '')));
$probe = in_array($probeRaw, ['1', 'true', 'bridges', 'imap', 'sync', 'all'], true);
$probeBridges = in_array($probeRaw, ['1', 'true', 'bridges', 'all'], true);
$probeImap = in_array($probeRaw, ['imap', 'all'], true);
$probeSync = in_array($probeRaw, ['sync', 'all'], true);

$doShare = isset($_GET['share']) && ($_GET['share'] === '1' || $_GET['share'] === 'true');
$asJson = isset($_GET['json']) && ($_GET['json'] === '1' || $_GET['json'] === 'true');

if ($probe && !$isAdmin) {
    http_response_code(403);
    echo $asJson
        ? json_encode(['status' => 'error', 'message' => 'probe requires an admin account.'])
        : 'probe requires an admin account.';
    exit;
}

/**
 * Fast bridge GET that cannot hang past $timeout seconds.
 * @return array{ok:bool,http_code?:int,ms?:int,payload?:array,error?:string,raw_preview?:string}
 */
function email_debug_bridge_fetch(string $url, string $apiKey, int $timeout = 8): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl missing'];
    }
    $timeout = max(2, min(20, $timeout));
    $apiKey = trim($apiKey);
    if ($apiKey !== '' && stripos($url, 'api_key=') === false) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'api_key=' . rawurlencode($apiKey);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; UltitechMailBridge/1.0; +https://ultitech.io)',
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Api-Key: ' . $apiKey,
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    $started = microtime(true);
    $body = curl_exec($ch);
    $ms = (int) round((microtime(true) - $started) * 1000);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'http_code' => $code, 'ms' => $ms, 'error' => $err !== '' ? $err : 'curl_exec failed'];
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
        return [
            'ok' => false,
            'http_code' => $code,
            'ms' => $ms,
            'error' => 'Non-JSON response (HTTP ' . $code . ')',
            'raw_preview' => substr(preg_replace('/\s+/', ' ', $raw), 0, 240),
        ];
    }
    if ($code >= 400 || (($json['status'] ?? '') === 'error')) {
        return [
            'ok' => false,
            'http_code' => $code,
            'ms' => $ms,
            'error' => (string) ($json['message'] ?? ('HTTP ' . $code)),
            'payload' => $json,
        ];
    }
    return ['ok' => true, 'http_code' => $code, 'ms' => $ms, 'payload' => $json];
}

/**
 * TCP reachability check � avoids long IMAP hangs when port is firewalled.
 */
function email_debug_tcp_check(string $host, int $port, float $timeoutSec = 3.0): array
{
    $host = trim($host);
    if ($host === '' || $port < 1) {
        return ['ok' => false, 'error' => 'invalid host/port'];
    }
    $errno = 0;
    $errstr = '';
    $started = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
    $ms = (int) round((microtime(true) - $started) * 1000);
    if (!$fp) {
        return ['ok' => false, 'ms' => $ms, 'error' => trim($errstr !== '' ? $errstr : ('errno ' . $errno))];
    }
    fclose($fp);
    return ['ok' => true, 'ms' => $ms];
}

function email_debug_mask(string $value, int $keepStart = 4, int $keepEnd = 2): string
{
    $value = trim($value);
    if ($value === '') {
        return '(empty)';
    }
    $len = strlen($value);
    if ($len <= ($keepStart + $keepEnd)) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, $keepStart) . str_repeat('*', max(3, $len - $keepStart - $keepEnd)) . substr($value, -$keepEnd);
}

function email_debug_ok(bool $ok): string
{
    return $ok ? 'OK' : 'FAIL';
}

function email_debug_db_name(?PDO $pdo): string
{
    if (!($pdo instanceof PDO)) {
        return '(none)';
    }
    try {
        return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        return '(error: ' . $e->getMessage() . ')';
    }
}

$report = [
    'generated_at' => gmdate('c'),
    'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
    'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'php_version' => PHP_VERSION,
    'imap_extension' => extension_loaded('imap'),
    'curl_extension' => extension_loaded('curl'),
    'server_outbound_ip' => null,
    'session' => [
        'user_id' => $userId,
        'username' => (string) ($_SESSION['username'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? ''),
        'is_admin' => $isAdmin,
        'company_id' => (int) ($_SESSION['company_id'] ?? 0),
        'company_slug' => (string) ($_SESSION['company_slug'] ?? ''),
        'company_name' => (string) ($_SESSION['company_name'] ?? ''),
    ],
    'checks' => [],
    'issues' => [],
    'hints' => [],
];

// Public IP of this PHP host (whitelist this on ultimate.co.tz Cloudflare/WAF).
if (function_exists('curl_init')) {
    try {
        $chIp = curl_init('https://api.ipify.org?format=text');
        curl_setopt_array($chIp, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'UltitechMailDebug/1.0',
        ]);
        $ipBody = curl_exec($chIp);
        curl_close($chIp);
        $ipBody = trim((string) $ipBody);
        if ($ipBody !== '' && filter_var($ipBody, FILTER_VALIDATE_IP)) {
            $report['server_outbound_ip'] = $ipBody;
            $report['hints'][] = 'Whitelist this Ultitech outbound IP on ultimate.co.tz WAF/Cloudflare for /staff/ultimate/* : ' . $ipBody;
        }
    } catch (Throwable $e) {
    }
}

global $pdo, $control_pdo;

$emailDb = function_exists('email_module_pdo') ? email_module_pdo() : null;
$report['database'] = [
    'global_pdo_db' => email_debug_db_name($pdo instanceof PDO ? $pdo : null),
    'control_pdo_db' => email_debug_db_name(isset($control_pdo) && $control_pdo instanceof PDO ? $control_pdo : null),
    'email_module_pdo_db' => email_debug_db_name($emailDb instanceof PDO ? $emailDb : null),
    'email_module_pdo_ok' => $emailDb instanceof PDO,
];

if (!($emailDb instanceof PDO)) {
    $report['issues'][] = 'email_module_pdo() returned null � inbox cannot load messages from any DB.';
} else {
    ensure_email_module_schema($emailDb);
}

$userSettings = null;
if ($emailDb instanceof PDO) {
    try {
        $st = $emailDb->prepare('SELECT imap_host, imap_user, imap_port, smtp_host, smtp_user FROM module_email_user_settings WHERE user_id = ?');
        $st->execute([$userId]);
        $userSettings = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $report['issues'][] = 'Could not read module_email_user_settings: ' . $e->getMessage();
    }
}

$mailConfigured = function_exists('email_source_is_configured')
    ? email_source_is_configured(is_array($userSettings) ? $userSettings : null)
    : false;

$report['mail_source'] = [
    'configured' => $mailConfigured,
    'personal_imap' => [
        'present' => !empty($userSettings['imap_host']) && !empty($userSettings['imap_user']),
        'host' => (string) ($userSettings['imap_host'] ?? ''),
        'user' => (string) ($userSettings['imap_user'] ?? ''),
        'port' => (string) ($userSettings['imap_port'] ?? ''),
    ],
    'company_imap' => [],
    'bridges' => [],
    'local_packages' => [],
    'company_mailboxes' => function_exists('email_company_mailbox_addresses')
        ? email_company_mailbox_addresses()
        : [],
    'preferred_bridge' => function_exists('email_resolve_preferred_bridge')
        ? email_resolve_preferred_bridge()
        : '',
];

$companyImap = function_exists('email_get_imap_settings') ? email_get_imap_settings() : [];
$report['mail_source']['company_imap'] = [
    'present' => !empty($companyImap['email_imap_host']) && !empty($companyImap['email_imap_user']),
    'host' => (string) ($companyImap['email_imap_host'] ?? ''),
    'user' => (string) ($companyImap['email_imap_user'] ?? ''),
    'port' => (string) ($companyImap['email_imap_port'] ?? ''),
    'ssl' => (string) ($companyImap['email_imap_ssl'] ?? ''),
    'pass_set' => !empty($companyImap['email_imap_pass']),
];

$bridges = function_exists('email_get_remote_bridges') ? email_get_remote_bridges() : [];
foreach ($bridges as $b) {
    $report['mail_source']['bridges'][] = [
        'key' => (string) ($b['key'] ?? ''),
        'enabled' => !empty($b['enabled']),
        'url' => (string) ($b['url'] ?? ''),
        'mailbox' => (string) ($b['mailbox'] ?? ''),
        'api_key_masked' => email_debug_mask((string) ($b['api_key'] ?? ''), 3, 2),
        'api_key_set' => trim((string) ($b['api_key'] ?? '')) !== '',
    ];
}

foreach (['ultimate', 'roadmaster'] as $key) {
    $localImap = function_exists('email_local_bridge_imap_config') ? email_local_bridge_imap_config($key) : null;
    $cfgPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'mail-bridges' . DIRECTORY_SEPARATOR . $key . DIRECTORY_SEPARATOR . 'config.php';
    $report['mail_source']['local_packages'][] = [
        'key' => $key,
        'config_file_exists' => is_file($cfgPath),
        'imap_config_ok' => is_array($localImap),
        'imap_user' => is_array($localImap) ? (string) ($localImap['email_imap_user'] ?? '') : '',
        'imap_host' => is_array($localImap) ? (string) ($localImap['email_imap_host'] ?? '') : '',
    ];
}

if (!$mailConfigured) {
    $report['issues'][] = 'Mail source not configured on this environment (no personal IMAP, company IMAP, or enabled remote bridge). Live often lacks bridge settings that exist on localhost.';
}

// Schema / row counts
$report['storage'] = [
    'has_module_emails' => false,
    'has_attachments' => false,
    'has_user_settings' => false,
    'has_system_settings' => false,
    'totals' => [],
    'by_user_id' => [],
    'visible_to_you' => [],
    'recent_inbound' => [],
    'recent_missing_for_you' => [],
];

if ($emailDb instanceof PDO) {
    $report['storage']['has_module_emails'] = email_connection_has_table($emailDb, 'module_emails');
    $report['storage']['has_attachments'] = email_connection_has_table($emailDb, 'module_email_attachments');
    $report['storage']['has_user_settings'] = email_connection_has_table($emailDb, 'module_email_user_settings');
    $report['storage']['has_system_settings'] = email_connection_has_table($emailDb, 'system_settings');

    if ($report['storage']['has_module_emails']) {
        try {
            $totals = $emailDb->query("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) AS inbound,
                SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) AS outbound,
                SUM(CASE WHEN direction='inbound' AND status='unread' THEN 1 ELSE 0 END) AS unread,
                SUM(CASE WHEN user_id=0 THEN 1 ELSE 0 END) AS shared_user0,
                SUM(CASE WHEN user_id<>0 THEN 1 ELSE 0 END) AS private_user,
                MAX(created_at) AS newest_created_at,
                MIN(created_at) AS oldest_created_at
                FROM module_emails")->fetch(PDO::FETCH_ASSOC) ?: [];
            $report['storage']['totals'] = $totals;

            $byUser = $emailDb->query("SELECT user_id, COUNT(*) AS cnt, MAX(created_at) AS newest
                FROM module_emails
                GROUP BY user_id
                ORDER BY cnt DESC
                LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $report['storage']['by_user_id'] = $byUser;

            $vis = $emailDb->prepare("SELECT
                COUNT(*) AS visible_total,
                SUM(CASE WHEN direction='inbound' AND status NOT IN ('trash','archived','spam') THEN 1 ELSE 0 END) AS visible_inbox,
                MAX(created_at) AS newest_visible
                FROM module_emails
                WHERE user_id = ? OR user_id = 0");
            $vis->execute([$userId]);
            $report['storage']['visible_to_you'] = $vis->fetch(PDO::FETCH_ASSOC) ?: [];

            $recent = $emailDb->query("SELECT id, user_id, sender_email, recipient_email, LEFT(subject, 80) AS subject,
                direction, status, created_at, message_id
                FROM module_emails
                WHERE direction = 'inbound'
                ORDER BY created_at DESC
                LIMIT 15")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $report['storage']['recent_inbound'] = $recent;

            $missing = $emailDb->prepare("SELECT id, user_id, sender_email, recipient_email, LEFT(subject, 80) AS subject,
                status, created_at
                FROM module_emails
                WHERE direction = 'inbound'
                  AND user_id <> 0
                  AND user_id <> ?
                  AND status NOT IN ('trash','archived','spam')
                ORDER BY created_at DESC
                LIMIT 20");
            $missing->execute([$userId]);
            $report['storage']['recent_missing_for_you'] = $missing->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($report['storage']['recent_missing_for_you'])) {
                $report['issues'][] = 'Found inbound company-looking mail owned by OTHER user_ids (not you and not 0). Those rows are hidden from your inbox filter (user_id = you OR 0). This is the usual live-vs-local mismatch when Sync ran as an admin.';
                $report['hints'][] = 'Open this page with ?share=1 as admin (or open inbox after deploying the share fix) to reassign company mailbox rows to user_id=0.';
            }

            if ((int) ($totals['total'] ?? 0) === 0) {
                $report['issues'][] = 'module_emails is empty on this database. Live may be pointing at a different tenant DB than localhost, or sync never succeeded here.';
                $report['hints'][] = 'Confirm email_module_pdo_db matches the Ultimate tenant DB. Then click Sync or run ?probe=1.';
            }
        } catch (Throwable $e) {
            $report['issues'][] = 'Storage query failed: ' . $e->getMessage();
        }
    } else {
        $report['issues'][] = 'module_emails table missing in the resolved email DB.';
    }

    // Bridge settings raw keys (presence only)
    if ($report['storage']['has_system_settings']) {
        try {
            $keys = $emailDb->query("SELECT setting_key,
                CASE
                  WHEN setting_key LIKE '%_api_key' OR setting_key LIKE '%_pass' THEN '(set)'
                  ELSE LEFT(setting_value, 120)
                END AS setting_value_preview
                FROM system_settings
                WHERE setting_key LIKE 'email_bridge_%' OR setting_key LIKE 'email_imap_%' OR setting_key LIKE 'email_smtp_%'
                ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $report['system_settings_email'] = $keys;
            if ($keys === [] && empty($bridges) && empty($companyImap['email_imap_host'])) {
                $report['issues'][] = 'No email_bridge_* / email_imap_* rows in this DB system_settings. Localhost may have bridges configured; live does not.';
            }
        } catch (Throwable $e) {
            $report['system_settings_email'] = ['error' => $e->getMessage()];
        }
    }
}

// Share migration
$report['share_action'] = null;
if ($doShare) {
    if (!$isAdmin) {
        $report['share_action'] = ['status' => 'denied', 'message' => 'share=1 requires admin'];
    } elseif (!($emailDb instanceof PDO) || !function_exists('email_share_company_mailbox_rows')) {
        $report['share_action'] = ['status' => 'error', 'message' => 'Share helper unavailable'];
    } else {
        try {
            $before = (int) $emailDb->query('SELECT COUNT(*) FROM module_emails WHERE user_id <> 0')->fetchColumn();
            email_share_company_mailbox_rows($emailDb);
            // Reset static guard by re-including is not possible; run SQL directly for count after
            $afterPrivate = (int) $emailDb->query('SELECT COUNT(*) FROM module_emails WHERE user_id <> 0')->fetchColumn();
            $shared = (int) $emailDb->query('SELECT COUNT(*) FROM module_emails WHERE user_id = 0')->fetchColumn();
            $report['share_action'] = [
                'status' => 'ok',
                'private_before' => $before,
                'private_after' => $afterPrivate,
                'shared_user0' => $shared,
                'note' => 'Company mailbox rows matching known company addresses were set to user_id=0 (function runs once per request).',
            ];
            $report['hints'][] = 'Reload the inbox after share=1. If private_after is still high, those rows may be personal IMAP mail, not company mailbox.';
        } catch (Throwable $e) {
            $report['share_action'] = ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}

// Live bridge / IMAP probe (default probe=1 skips IMAP � IMAP often hangs forever on live firewalls)
$report['probe'] = null;
if ($probe) {
    $probeOut = [
        'mode' => $probeRaw,
        'note' => 'Default probe=1 tests HTTP bridges only (8s timeout each). Use probe=imap or probe=sync separately. probe=all can blank-page on live.',
        'bridges' => [],
        'local_packages' => [],
        'company_imap' => null,
        'sync_result' => null,
    ];

    if ($probeBridges) {
        if ($bridges) {
            foreach ($bridges as $b) {
                if (empty($b['enabled']) || empty($b['url']) || empty($b['api_key'])) {
                    $probeOut['bridges'][] = [
                        'key' => $b['key'] ?? '',
                        'status' => 'skipped',
                        'reason' => 'disabled or missing credentials',
                    ];
                    continue;
                }
                $url = rtrim((string) $b['url'], '/') . '/api/messages.php?limit=5';
                $entry = [
                    'key' => (string) ($b['key'] ?? ''),
                    'url' => $url,
                ];
                $res = email_debug_bridge_fetch($url, (string) $b['api_key'], 8);
                $entry['ms'] = $res['ms'] ?? null;
                $entry['http_code'] = $res['http_code'] ?? null;
                if (empty($res['ok'])) {
                    $entry['status'] = 'error';
                    $entry['error'] = (string) ($res['error'] ?? 'unknown');
                    if (!empty($res['raw_preview'])) {
                        $entry['raw_preview'] = $res['raw_preview'];
                    }
                    $report['issues'][] = 'Bridge ' . ($b['key'] ?? '?') . ' probe failed: ' . $entry['error'];
                } else {
                    $payload = $res['payload'] ?? [];
                    $msgs = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
                    $entry['status'] = 'ok';
                    $entry['mailbox'] = (string) ($payload['mailbox'] ?? ($b['mailbox'] ?? ''));
                    $entry['fetched'] = count($msgs);
                    $entry['sample'] = [];
                    $newestSample = null;
                    foreach (array_slice($msgs, 0, 5) as $m) {
                        if (!is_array($m)) {
                            continue;
                        }
                        $date = (string) ($m['date'] ?? '');
                        $entry['sample'][] = [
                            'from' => (string) ($m['from'] ?? ''),
                            'to' => (string) ($m['to'] ?? ''),
                            'subject' => substr((string) ($m['subject'] ?? ''), 0, 80),
                            'date' => $date,
                            'message_id' => substr((string) ($m['message_id'] ?? ''), 0, 80),
                        ];
                        if ($date !== '' && ($newestSample === null || strtotime($date) > strtotime((string) $newestSample))) {
                            $newestSample = $date;
                        }
                    }
                    $entry['newest_sample_date'] = $newestSample;
                    $dbNewest = (string) ($report['storage']['totals']['newest_created_at'] ?? '');
                    if ($newestSample && $dbNewest && strtotime($newestSample) > strtotime($dbNewest) + 60) {
                        $report['issues'][] = 'Bridge ' . ($b['key'] ?? '') . ' has newer mail (' . $newestSample . ') than DB (' . $dbNewest . ') � sync is not importing on live.';
                    }
                }
                $probeOut['bridges'][] = $entry;
            }
        } else {
            $probeOut['bridges'][] = ['status' => 'skipped', 'reason' => 'No bridges configured'];
        }
    } else {
        $probeOut['bridges'][] = ['status' => 'skipped', 'reason' => 'Not requested (use probe=1 or probe=bridges)'];
    }

    if ($probeImap) {
        $imapLogin = isset($_GET['imap_login']) && ($_GET['imap_login'] === '1' || $_GET['imap_login'] === 'true');
        $imapTargets = [];
        foreach (['ultimate', 'roadmaster'] as $key) {
            $cfg = function_exists('email_local_bridge_imap_config') ? email_local_bridge_imap_config($key) : null;
            if (is_array($cfg)) {
                $imapTargets[] = ['label' => 'local_package:' . $key, 'cfg' => $cfg];
            } else {
                $probeOut['local_packages'][] = ['key' => $key, 'status' => 'missing'];
            }
        }
        if (!empty($companyImap['email_imap_host'])) {
            $imapTargets[] = ['label' => 'company_imap', 'cfg' => $companyImap];
        }

        foreach ($imapTargets as $target) {
            $cfg = $target['cfg'];
            $host = (string) ($cfg['email_imap_host'] ?? '');
            $port = (int) ($cfg['email_imap_port'] ?? 993);
            $user = (string) ($cfg['email_imap_user'] ?? '');
            $row = [
                'key' => $target['label'],
                'host' => $host,
                'port' => $port,
                'user' => $user,
            ];
            $tcp = email_debug_tcp_check($host, $port, 3.0);
            $row['tcp'] = $tcp;
            if (empty($tcp['ok'])) {
                $row['status'] = 'tcp_blocked';
                $row['error'] = (string) ($tcp['error'] ?? 'connect failed');
                $row['note'] = 'Ultitech cannot reach this mail host on port ' . $port . '. Localhost can; live cannot. Fix firewall or keep using HTTP bridge (after fixing Ultimate 403).';
                $report['issues'][] = $target['label'] . ' TCP ' . $host . ':' . $port . ' blocked/unreachable from live (' . $row['error'] . ').';
                if (strpos($target['label'], 'local_package:') === 0) {
                    $probeOut['local_packages'][] = $row;
                } else {
                    $probeOut['company_imap'] = $row;
                }
                continue;
            }

            // Default: TCP-only. Full imap_open can still hang on TLS even after TCP succeeds.
            if (!$imapLogin) {
                $row['status'] = 'tcp_ok';
                $row['note'] = 'Port reachable. Skip imap_open by default (add &imap_login=1 to test login � may hang on some hosts).';
                if (strpos($target['label'], 'local_package:') === 0) {
                    $probeOut['local_packages'][] = $row;
                } else {
                    $probeOut['company_imap'] = $row;
                }
                continue;
            }

            if (!function_exists('imap_open')) {
                $row['status'] = 'imap_extension_missing';
                if (strpos($target['label'], 'local_package:') === 0) {
                    $probeOut['local_packages'][] = $row;
                } else {
                    $probeOut['company_imap'] = $row;
                }
                continue;
            }
            if (session_status() !== PHP_SESSION_NONE) {
                unset($_SESSION['imap_unreachable']);
            }
            if (function_exists('imap_timeout')) {
                @imap_timeout(IMAP_OPENTIMEOUT, 4);
                @imap_timeout(IMAP_READTIMEOUT, 6);
            }
            $mbox = @email_open_imap_mailbox($cfg);
            if (!$mbox) {
                $row['status'] = 'imap_open_failed';
                $row['imap_last_error'] = function_exists('imap_last_error') ? (string) imap_last_error() : '';
                $report['issues'][] = $target['label'] . ' IMAP open failed after TCP ok (credentials or SSL).';
            } else {
                $row['status'] = 'ok';
                $row['inbox_message_count'] = (int) @imap_num_msg($mbox);
                @imap_close($mbox);
            }
            if (strpos($target['label'], 'local_package:') === 0) {
                $probeOut['local_packages'][] = $row;
            } else {
                $probeOut['company_imap'] = $row;
            }
        }
    } else {
        $probeOut['local_packages'][] = ['status' => 'skipped', 'reason' => 'IMAP skipped (use probe=imap for TCP-only check).'];
        $probeOut['company_imap'] = ['status' => 'skipped', 'reason' => 'Use probe=imap'];
    }

    if ($probeSync && $emailDb instanceof PDO && function_exists('email_sync_remote_bridges')) {
        try {
            $sync = email_sync_remote_bridges($emailDb, 8, 0, 8);
            $probeOut['sync_result'] = $sync;
            if ((int) ($sync['new_count'] ?? 0) === 0 && empty($sync['errors'])) {
                $report['hints'][] = 'Bridge sync ran with 0 new messages. Either already imported, since-cursor skips them, or bridge returned empty.';
            }
            if (!empty($sync['errors'])) {
                foreach ($sync['errors'] as $k => $err) {
                    $report['issues'][] = "Sync bridge {$k}: {$err}";
                }
            }
        } catch (Throwable $e) {
            $probeOut['sync_result'] = ['status' => 'error', 'message' => $e->getMessage()];
            $report['issues'][] = 'email_sync_remote_bridges threw: ' . $e->getMessage();
        }
    } elseif ($probe && !$probeSync) {
        $probeOut['sync_result'] = ['status' => 'skipped', 'reason' => 'Use probe=sync to import a sample now'];
    }

    $report['probe'] = $probeOut;

    // Recompute verdict after probe issues were appended
    if ($report['issues'] !== []) {
        $report['verdict'] = 'Problems found � see issues[]. Typical live causes: (1) bridge HTTP failing, (2) outbound IMAP blocked, (3) sync not importing newer bridge mail, (4) wrong tenant database.';
    }
}

// Inbox filter simulation (same as index.php)
$report['inbox_simulation'] = null;
if ($emailDb instanceof PDO && $mailConfigured) {
    try {
        $st = $emailDb->prepare("SELECT COUNT(*) FROM module_emails e
            WHERE (e.user_id = ? OR e.user_id = 0)
              AND e.direction = 'inbound'
              AND e.status NOT IN ('trash', 'archived', 'spam')");
        $st->execute([$userId]);
        $count = (int) $st->fetchColumn();
        $report['inbox_simulation'] = [
            'would_show_count' => $count,
            'filter' => 'user_id = current OR 0; inbound; not trash/archived/spam',
            'configured_gate' => true,
        ];
        if ($count === 0) {
            $report['issues'][] = 'Inbox query for your user returns 0 rows. Either no shared mail, or all recent mail is under another user_id.';
        }
    } catch (Throwable $e) {
        $report['inbox_simulation'] = ['error' => $e->getMessage()];
    }
} elseif (!$mailConfigured) {
    $report['inbox_simulation'] = [
        'would_show_count' => 0,
        'configured_gate' => false,
        'note' => 'index.php only loads emails when mailConfigured=true. Unconfigured live shows empty inbox even if DB has rows.',
    ];
    $report['issues'][] = 'configured=false on this host � the React inbox will refuse to list DB rows until a mail source is configured.';
}

// High-level verdict
if ($report['issues'] === []) {
    $report['verdict'] = 'No hard failures detected. If messages still missing, compare newest_created_at / bridge sample dates with the missing mail, and confirm live uses the same tenant DB as localhost.';
} else {
    $report['verdict'] = 'Problems found � see issues[]. Typical live causes: (1) bridge/IMAP not configured on live DB, (2) outbound IMAP/HTTP blocked, (3) wrong tenant database, (4) messages saved under admin user_id instead of 0.';
}

$report['checks'] = [
    'email_db' => email_debug_ok($emailDb instanceof PDO),
    'mail_configured' => email_debug_ok($mailConfigured),
    'imap_extension' => email_debug_ok(extension_loaded('imap')),
    'curl_extension' => email_debug_ok(extension_loaded('curl')),
    'bridges_present' => email_debug_ok($bridges !== []),
    'visible_inbox_gt_zero' => email_debug_ok(((int) ($report['inbox_simulation']['would_show_count'] ?? 0)) > 0),
];

if ($asJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function email_debug_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function email_debug_pre($data): string
{
    return '<pre>' . email_debug_h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
}

$self = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'debug_inbox.php'), '?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email inbox debug</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 1.5rem; background: #0b1220; color: #e2e8f0; line-height: 1.45; }
        h1, h2 { color: #f8fafc; }
        a { color: #93c5fd; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 12px; padding: 1rem 1.25rem; margin: 1rem 0; }
        .ok { color: #4ade80; } .fail { color: #f87171; } .warn { color: #fbbf24; }
        pre { background: #020617; padding: 0.75rem; border-radius: 8px; overflow: auto; font-size: 12px; }
        ul { margin: 0.4rem 0 0.4rem 1.2rem; }
        .actions a { display: inline-block; margin-right: 0.75rem; margin-top: 0.35rem; padding: 0.4rem 0.75rem; background: #1e293b; border-radius: 999px; text-decoration: none; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border-bottom: 1px solid #1f2937; padding: 0.4rem 0.35rem; text-align: left; vertical-align: top; }
        th { color: #94a3b8; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Email inbox debug</h1>
    <p><?= email_debug_h($report['host'] . $report['request_uri']) ?> � <?= email_debug_h($report['generated_at']) ?> UTC</p>
    <p><strong>Verdict:</strong> <?= email_debug_h($report['verdict']) ?></p>

    <div class="card actions">
        <strong>Actions</strong><br>
        <a href="<?= email_debug_h($self) ?>">Refresh</a>
        <a href="<?= email_debug_h($self) ?>?probe=1">Probe bridges (fast)</a>
        <a href="<?= email_debug_h($self) ?>?probe=imap">Probe IMAP TCP only</a>
        <a href="<?= email_debug_h($self) ?>?probe=sync">Run sync sample (bridges)</a>
        <a href="<?= email_debug_h($self) ?>?share=1">Share company rows (user_id?0)</a>
        <a href="<?= email_debug_h($self) ?>?json=1">JSON</a>
        <a href="<?= email_debug_h($self) ?>?probe=1&amp;json=1">Probe JSON</a>
    </div>

    <div class="card">
        <h2>Quick checks</h2>
        <ul>
            <?php foreach ($report['checks'] as $name => $status): ?>
                <li><span class="<?= $status === 'OK' ? 'ok' : 'fail' ?>"><?= email_debug_h($status) ?></span> <?= email_debug_h($name) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($report['issues']): ?>
    <div class="card">
        <h2 class="fail">Issues</h2>
        <ul>
            <?php foreach ($report['issues'] as $issue): ?>
                <li><?= email_debug_h($issue) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($report['hints']): ?>
    <div class="card">
        <h2 class="warn">Hints</h2>
        <ul>
            <?php foreach ($report['hints'] as $hint): ?>
                <li><?= email_debug_h($hint) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Session / company</h2>
        <p><strong>Ultitech server outbound IP:</strong>
            <span class="warn"><?= email_debug_h($report['server_outbound_ip'] ?: '(unknown � upload latest debug_inbox.php)') ?></span>
            � whitelist this on <code>ultimate.co.tz</code> Cloudflare / StackCDN / firewall for path <code>/staff/ultimate/*</code>
        </p>
        <?= email_debug_pre($report['session']) ?>
    </div>

    <div class="card">
        <h2>Database resolution</h2>
        <p>If <code>email_module_pdo_db</code> differs between localhost and live, you are looking at different mail stores.</p>
        <?= email_debug_pre($report['database']) ?>
    </div>

    <div class="card">
        <h2>Mail sources</h2>
        <?= email_debug_pre($report['mail_source']) ?>
    </div>

    <div class="card">
        <h2>Storage</h2>
        <?= email_debug_pre([
            'schema' => [
                'module_emails' => $report['storage']['has_module_emails'],
                'attachments' => $report['storage']['has_attachments'],
                'user_settings' => $report['storage']['has_user_settings'],
                'system_settings' => $report['storage']['has_system_settings'],
            ],
            'totals' => $report['storage']['totals'],
            'by_user_id' => $report['storage']['by_user_id'],
            'visible_to_you' => $report['storage']['visible_to_you'],
            'inbox_simulation' => $report['inbox_simulation'],
        ]) ?>
    </div>

    <div class="card">
        <h2>Recent inbound (any user_id)</h2>
        <?php if (empty($report['storage']['recent_inbound'])): ?>
            <p class="fail">No inbound rows.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>id</th><th>user_id</th><th>from</th><th>to</th><th>subject</th><th>status</th><th>created_at</th></tr>
                </thead>
                <tbody>
                <?php foreach ($report['storage']['recent_inbound'] as $row): ?>
                    <tr>
                        <td><?= (int) $row['id'] ?></td>
                        <td class="<?= ((int) $row['user_id'] === 0 || (int) $row['user_id'] === $userId) ? 'ok' : 'fail' ?>"><?= (int) $row['user_id'] ?></td>
                        <td><?= email_debug_h($row['sender_email']) ?></td>
                        <td><?= email_debug_h($row['recipient_email']) ?></td>
                        <td><?= email_debug_h($row['subject']) ?></td>
                        <td><?= email_debug_h($row['status']) ?></td>
                        <td><?= email_debug_h($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>Green <code>user_id</code> = visible to you (0 or your id). Red = hidden from your inbox.</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($report['storage']['recent_missing_for_you'])): ?>
    <div class="card">
        <h2 class="fail">Hidden from you (owned by other users)</h2>
        <?= email_debug_pre($report['storage']['recent_missing_for_you']) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($report['system_settings_email'])): ?>
    <div class="card">
        <h2>Email-related system_settings</h2>
        <?= email_debug_pre($report['system_settings_email']) ?>
    </div>
    <?php endif; ?>

    <?php if ($report['share_action']): ?>
    <div class="card">
        <h2>Share action</h2>
        <?= email_debug_pre($report['share_action']) ?>
    </div>
    <?php endif; ?>

    <?php if ($report['probe']): ?>
    <div class="card">
        <h2>Probe results</h2>
        <?= email_debug_pre($report['probe']) ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>How to compare localhost vs live</h2>
        <ol>
            <li>Open this same URL on both environments while logged into Ultimate.</li>
            <li>Compare <code>email_module_pdo_db</code>, <code>mail_source.configured</code>, and bridge list.</li>
            <li>On live, use <strong>Probe</strong> � if bridges/IMAP fail here but work locally, the host cannot reach the mail server.</li>
            <li>If recent rows exist but <code>user_id</code> is red, run <strong>Share company rows</strong>.</li>
            <li>Delete or lock this script after you finish debugging production.</li>
        </ol>
    </div>
</body>
</html>
