<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

$voucherId = (int) ($_POST['voucher_id'] ?? 0);
$result = togglePaymentVoucherReference($voucherId, (int) ($_SESSION['user_id'] ?? 0));

if (!$result['ok']) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
