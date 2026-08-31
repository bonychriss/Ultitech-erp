<?php
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
require_once __DIR__ . '/../../includes/user-avatar.php';
require_once __DIR__ . '/../../modules/balances/functions.php';
require_once __DIR__ . '/../load-data.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();

$voucherId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($voucherId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid voucher id']);
    exit;
}

$moduleQs = '';
if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
    $moduleQs = '?module=' . rawurlencode((string) $_GET['module']);
}

$result = vv_load_view_payload($pdo, $voucherId, [
    'returnFinance' => isset($_GET['return']) && $_GET['return'] === 'finance',
    'moduleQs' => $moduleQs,
]);

if (!$result['ok']) {
    http_response_code((int) ($result['code'] ?? 500));
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Error']);
    exit;
}

echo json_encode(['ok' => true, 'data' => $result['payload']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
