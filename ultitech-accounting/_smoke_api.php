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

echo 'mode=' . ultitech_accounting_mode() . "\n";
echo 'api=' . ultitech_accounting_api_base() . "\n";
echo 'secret_len=' . strlen(ultitech_accounting_bridge_secret()) . "\n";
echo 'internal=' . (ultitech_accounting_uses_internal_api() ? '1' : '0') . "\n";

$res = ultitech_accounting_dispatch_api('GET', '/up');
echo 'up_status=' . ($res['status'] ?? 0) . "\n";
echo 'up_body=' . substr((string) ($res['body'] ?? ''), 0, 200) . "\n";
if (($res['error'] ?? '') !== '') {
    echo 'error=' . $res['error'] . "\n";
}
