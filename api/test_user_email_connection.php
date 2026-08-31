<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/SimpleSMTP.php';

if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

// Ensure error reporting doesn't output HTML warnings
ini_set('display_errors', 0);
error_reporting(E_ALL);

$imap_host = trim($_POST['imap_host'] ?? '');
$imap_port = trim($_POST['imap_port'] ?? '993');
$imap_user = trim($_POST['imap_user'] ?? '');
$imap_pass = trim($_POST['imap_pass'] ?? '');
$imap_ssl  = trim($_POST['imap_ssl'] ?? 'ssl');

$smtp_host = trim($_POST['smtp_host'] ?? '');
$smtp_port = trim($_POST['smtp_port'] ?? '465');
$smtp_user = trim($_POST['smtp_user'] ?? '');
$smtp_pass = trim($_POST['smtp_pass'] ?? '');
$smtp_ssl  = trim($_POST['smtp_ssl'] ?? 'ssl');

if (empty($imap_host) || empty($imap_user) || empty($smtp_host) || empty($smtp_user)) {
    echo json_encode(['status' => 'error', 'message' => 'Host and Username fields are required.']);
    exit;
}

// 1. Test IMAP
$imapResult = ['success' => false, 'message' => 'Unknown IMAP Error'];
$sslFlag = ($imap_ssl === 'ssl' || $imap_ssl === 'tls') ? "/ssl" : "";
if ($imap_ssl === 'tls') $sslFlag .= "/tls"; // Often IMAP strings use /ssl or /tls
// Add /novalidate-cert to avoid self-signed cert issues during testing
$mailbox = "{{$imap_host}:$imap_port/imap$sslFlag/novalidate-cert}INBOX";

$imapErrors = [];
set_error_handler(function($errno, $errstr) use (&$imapErrors) {
    $imapErrors[] = $errstr;
});
$imapConn = @imap_open($mailbox, $imap_user, $imap_pass, OP_HALFOPEN, 1, [
    'DISABLE_AUTHENTICATOR' => 'GSSAPI'
]);
restore_error_handler();

if ($imapConn) {
    $imapResult = ['success' => true, 'message' => 'IMAP connection successful.'];
    imap_close($imapConn);
} else {
    $err = imap_last_error();
    if (!$err && !empty($imapErrors)) $err = implode(' | ', $imapErrors);
    $imapResult = ['success' => false, 'message' => 'IMAP Failed: ' . ($err ?: 'Could not connect or authenticate.')];
}

// 2. Test SMTP
$smtpResult = ['success' => false, 'message' => 'Unknown SMTP Error'];
try {
    $mailer = new SimpleSMTP($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_ssl);
    $mailer->setTimeouts(5, 5); // 5 seconds timeout for testing
    $smtpResult = $mailer->testConnection();
} catch (Exception $e) {
    $smtpResult = ['success' => false, 'message' => 'SMTP Failed: ' . $e->getMessage()];
} catch (Throwable $e) {
    $smtpResult = ['success' => false, 'message' => 'SMTP Failed: ' . $e->getMessage()];
}

if ($imapResult['success'] && $smtpResult['success']) {
    echo json_encode([
        'status' => 'success',
        'message' => 'Connection successful! Both IMAP and SMTP are working.'
    ]);
} else {
    $errors = [];
    if (!$imapResult['success']) $errors[] = $imapResult['message'];
    if (!$smtpResult['success']) $errors[] = $smtpResult['message'];
    
    echo json_encode([
        'status' => 'error',
        'message' => implode("\n\n", $errors)
    ]);
}
