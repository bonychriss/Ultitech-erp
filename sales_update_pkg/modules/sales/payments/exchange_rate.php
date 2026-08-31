<?php
/**
 * JSON: BOT mean exchange rate for payment currency (TZS per 1 unit).
 * GET ?currency=USD
 */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/bot_exchange_rates.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('requireLogin')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Auth unavailable']);
    exit;
}

requireLogin();

$currency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
if ($currency === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'currency required']);
    exit;
}

$refresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
if ($refresh) {
    bot_exchange_rates_load(true);
}

$info = bot_get_exchange_rate($currency, true);
if ($info === null) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Rate not found for ' . $currency,
        'currency' => $currency,
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'currency' => $currency,
    'rate' => round((float) $info['rate'], 4),
    'source' => (string) $info['source'],
    'as_of' => $info['as_of'],
    'via_ai' => !empty($info['via_ai']),
], JSON_UNESCAPED_UNICODE);
