<?php
/**
 * Send WhatsApp Cloud API notification for the next voucher approval step.
 * POST JSON: { "voucher_id": 123 }  OR form: voucher_id=
 */
if (!defined('APP_BASE_PATH')) {
    $docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
    $base = '';
    if ($docRoot !== '' && strncmp($appRoot, $docRoot, strlen($docRoot)) === 0) {
        $base = trim(substr($appRoot, strlen($docRoot)), '/');
    }
    define('APP_BASE_PATH', $base);
}

require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}

$voucherId = (int) ($body['voucher_id'] ?? $body['id'] ?? ($_GET['id'] ?? 0));
if ($voucherId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid voucher id']);
    exit;
}

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

global $pdo;
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$companyId = (int) (function_exists('currentCompanyId') ? (currentCompanyId() ?? 0) : 0);
try {
    if ($companyId > 0 && function_exists('columnExists') && columnExists('payment_vouchers', 'company_id')) {
        $stmt = $pdo->prepare('SELECT * FROM payment_vouchers WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$voucherId, $companyId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM payment_vouchers WHERE id = ? LIMIT 1');
        $stmt->execute([$voucherId]);
    }
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load voucher']);
    exit;
}

if (!$voucher) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Voucher not found']);
    exit;
}

$who = (string) ($_SESSION['full_name'] ?? '');
$result = sendVoucherWhatsAppNotify($voucher, $who);

if (!empty($result['ok'])) {
    echo json_encode([
        'ok' => true,
        'sent' => true,
        'role' => $result['role'] ?? '',
        'name' => $result['name'] ?? '',
        'message_id' => $result['message_id'] ?? null,
        'message' => 'WhatsApp sent to ' . ($result['role'] ?? 'recipient') . '.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Not configured or send failed — return fallback wa.me link so UI can open it
http_response_code(!empty($result['fallback_link']) ? 200 : 422);
echo json_encode([
    'ok' => false,
    'sent' => false,
    'error' => $result['error'] ?? 'Send failed',
    'fallback_link' => $result['fallback_link'] ?? null,
    'role' => $result['role'] ?? '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
