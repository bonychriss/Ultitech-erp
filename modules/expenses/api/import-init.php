<?php

require_once '../../../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../includes/balances_integration.php';
require_once __DIR__ . '/../includes/currency_helpers.php';
require_once __DIR__ . '/../includes/import_helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $ctx = expenses_build_desk_form_init($pdo);
    expenses_balances_bootstrap();
    $aiAvailable = function_exists('balances_ai_is_connected') && balances_ai_is_connected();
    $payload = array_merge($ctx, [
        'csrf_token' => csrf_token(),
        'default_year' => (int) date('Y'),
        'template_columns' => expenses_import_template_columns(),
        'ai_available' => $aiAvailable,
    ]);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        throw new RuntimeException('Could not encode import options: ' . json_last_error_msg());
    }
    echo $json;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
