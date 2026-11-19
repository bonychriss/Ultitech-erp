<?php
declare(strict_types=1);

$token = (string) ($_GET['token'] ?? '');
if (! hash_equals('ua-extract-c323a98f82f3f5f7', $token)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$env = dirname(__DIR__) . '/env.php';
if (is_file($env)) {
    include $env;
}
require dirname(__DIR__) . '/includes/ultitech_accounting_bridge.php';

$now = time();
$result = ultitech_accounting_exchange_session([
    'erp_user_id' => 1,
    'username' => 'smoke',
    'full_name' => 'Smoke Test',
    'email' => 'smoke@ultitech.io',
    'role' => 'admin',
    'erp_company_id' => 1,
    'company_slug' => 'ultimate',
    'company_name' => 'ULTIMATE GENERAL TRADING',
    'company_logo_url' => '',
    'erp_db' => 'new_trading_voucher-35313030c7e2',
    'back_url' => 'https://ultitech.io/ultimate/accounting.php?engine=classic',
    'iat' => $now,
    'exp' => $now + 180,
    'nonce' => bin2hex(random_bytes(4)),
]);

echo 'ok=' . (! empty($result['ok']) ? '1' : '0') . "\n";
if (! empty($result['error'])) {
    echo 'error=' . $result['error'] . "\n";
}
if (! empty($result['data']['token'])) {
    echo 'token_len=' . strlen((string) $result['data']['token']) . "\n";
}
if (! empty($result['data']['company_id'])) {
    echo 'company_id=' . (int) $result['data']['company_id'] . "\n";
}
