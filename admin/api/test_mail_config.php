<?php
header('Content-Type: application/json');
error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('default_socket_timeout', '15');
set_time_limit(45);

function mail_test_smtp_security_hint(int $port, string $secure): ?string
{
    if ($port === 465 && $secure !== 'ssl') {
        return 'Port 465 requires SSL security. Select SSL, or switch to Port 587 with TLS.';
    }
    if ($port === 587 && $secure === 'ssl') {
        return 'Port 587 requires TLS (STARTTLS), not SSL. Select TLS, or switch to Port 465 with SSL.';
    }
    if ($port === 465 && $secure === 'tls') {
        return 'Port 465 uses implicit SSL, not STARTTLS. Select SSL for Port 465.';
    }
    return null;
}

function mail_test_tcp_reachable(string $host, int $port, string $secure = 'ssl', int $timeout = 10): array
{
    $useSsl = ($secure === 'ssl' || $port === 465);
    $protocol = $useSsl ? 'ssl://' : 'tcp://';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ]);
    $errno = 0;
    $errstr = '';
    $conn = @stream_socket_client(
        $protocol . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($conn) {
        fclose($conn);
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => trim($errstr) !== '' ? $errstr : 'Connection failed', 'errno' => $errno];
}

function mail_test_cross_host_message(string $host, int $port, array $tcpResult): ?string
{
    if (!empty($tcpResult['ok'])) {
        return null;
    }
    $appHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'your ERP server'));
    $mailRoot = preg_replace('/^mail\./i', '', $host);
    $sameDomain = ($mailRoot !== '' && stripos($appHost, $mailRoot) !== false);
    if ($sameDomain) {
        return null;
    }
    $errno = (int) ($tcpResult['errno'] ?? 0);
    $extra = ($errno === 110 || stripos((string) ($tcpResult['error'] ?? ''), 'timed out') !== false)
        ? ' Shared hosts (including StackCP / ultitech.io) often block outbound SMTP ports. Ask your ERP host to allow connections to ' . $host . ':' . $port . ', or host mail on the same server as the ERP.'
        : '';
    return 'Your ERP on ' . $appHost . ' could not reach ' . $host . ':' . $port . ' from the server.' . $extra;
}

try {
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/SimpleSMTP.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
        exit;
    }

    if (!isAdmin()) {
        echo json_encode(['status' => 'error', 'message' => 'Administrator access required.']);
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $testType = strtolower(trim((string) ($_POST['test_type'] ?? '')));

    if ($testType === 'smtp') {
        $host = trim((string) ($_POST['email_smtp_host'] ?? ''));
        $port = (int) trim((string) ($_POST['email_smtp_port'] ?? '465'));
        $user = trim((string) ($_POST['email_smtp_user'] ?? ''));
        $pass = (string) ($_POST['email_smtp_pass'] ?? '');
        $secure = strtolower(trim((string) ($_POST['email_smtp_secure'] ?? 'ssl')));

        if ($host === '' || $user === '' || $pass === '') {
            echo json_encode(['status' => 'error', 'message' => 'SMTP host, username, and password are required.']);
            exit;
        }

        if ($secure === 'none') {
            $secure = '';
        }

        if ($hint = mail_test_smtp_security_hint($port, $secure)) {
            echo json_encode(['status' => 'error', 'message' => $hint]);
            exit;
        }

        $tcp = mail_test_tcp_reachable($host, $port, $secure, 12);
        if (empty($tcp['ok'])) {
            $message = 'Could not reach ' . $host . ':' . $port . ' from this server: ' . ($tcp['error'] ?? 'Connection failed');
            if ($crossHost = mail_test_cross_host_message($host, $port, $tcp)) {
                $message .= ' ' . $crossHost;
            }
            echo json_encode(['status' => 'error', 'message' => $message]);
            exit;
        }

        $smtp = new SimpleSMTP($host, $port, $user, $pass, $secure);
        $smtp->setTimeouts(15, 15);
        $result = $smtp->testConnection();

        if (!empty($result['success'])) {
            echo json_encode(['status' => 'success', 'message' => $result['message']]);
        } else {
            echo json_encode(['status' => 'error', 'message' => $result['message'] ?? 'SMTP connection failed.']);
        }
        exit;
    }

    if ($testType === 'imap') {
        if (!function_exists('imap_open')) {
            echo json_encode([
                'status' => 'error',
                'message' => 'The PHP IMAP extension is not enabled. Enable php_imap in your PHP configuration to test IMAP.',
            ]);
            exit;
        }

        $host = trim((string) ($_POST['email_imap_host'] ?? ''));
        $port = trim((string) ($_POST['email_imap_port'] ?? '993'));
        $user = trim((string) ($_POST['email_imap_user'] ?? ''));
        $pass = (string) ($_POST['email_imap_pass'] ?? '');
        $ssl = trim((string) ($_POST['email_imap_ssl'] ?? 'ssl'));

        if ($host === '' || $user === '' || $pass === '') {
            echo json_encode(['status' => 'error', 'message' => 'IMAP host, username, and password are required.']);
            exit;
        }

        if ($ssl === 'notls' || $ssl === 'none') {
            $ssl = 'notls';
        }

        $imapPortInt = (int) $port;
        $tcp = mail_test_tcp_reachable($host, $imapPortInt > 0 ? $imapPortInt : 993, $ssl === 'ssl' ? 'ssl' : 'tcp', 12);
        if (empty($tcp['ok'])) {
            $message = 'Could not reach ' . $host . ':' . $port . ' from this server: ' . ($tcp['error'] ?? 'Connection failed');
            if ($crossHost = mail_test_cross_host_message($host, (int) $port, $tcp)) {
                $message .= ' ' . $crossHost;
            }
            echo json_encode(['status' => 'error', 'message' => $message]);
            exit;
        }

        if (function_exists('imap_timeout')) {
            @imap_timeout(IMAP_OPENTIMEOUT, 12);
            @imap_timeout(IMAP_READTIMEOUT, 12);
            @imap_timeout(IMAP_WRITETIMEOUT, 12);
            @imap_timeout(IMAP_CLOSETIMEOUT, 12);
        }

        $mboxPath = '{' . $host . ':' . $port . '/imap/' . $ssl . '/novalidate-cert}INBOX';
        $mbox = @imap_open($mboxPath, $user, $pass, OP_HALFOPEN, 1);

        if (!$mbox) {
            $error = function_exists('imap_last_error') ? (string) imap_last_error() : 'Unknown IMAP error.';
            if (function_exists('imap_errors')) {
                @imap_errors();
                @imap_alerts();
            }
            $message = $error !== ''
                ? 'IMAP connection failed: ' . $error
                : 'IMAP connection failed. Check host, port, security, username, and password.';
            echo json_encode(['status' => 'error', 'message' => $message]);
            exit;
        }

        @imap_close($mbox);
        echo json_encode(['status' => 'success', 'message' => 'IMAP connection and authentication successful.']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid test type. Use smtp or imap.']);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
