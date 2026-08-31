<?php
/**
 * JSON: BOT / AI exchange rate (TSh per 1 unit of foreign currency).
 * GET ?currency=USD&ai=1  (ai=1 forces AI lookup)
 */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/bot_exchange_rates.php';
require_once __DIR__ . '/../includes/currency_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

$currencyRaw = trim((string) ($_GET['currency'] ?? ''));
$iso = expenses_currency_iso($currencyRaw);
$display = expenses_currency_display_code($currencyRaw);

if ($iso === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'currency required']);
    exit;
}

if ($iso === 'TZS') {
    echo json_encode([
        'ok' => true,
        'currency' => $display,
        'iso' => 'TZS',
        'rate' => 1.0,
        'source' => 'BOT',
        'as_of' => date('Y-m-d'),
        'via_ai' => false,
        'base' => 'TSh',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$forceAi = isset($_GET['ai']) && (string) $_GET['ai'] === '1';
$refresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';

if ($refresh && !$forceAi) {
    bot_exchange_rates_load(true);
}

$info = null;
if ($forceAi) {
    $ai = bot_exchange_rate_ai_lookup($iso);
    if (is_array($ai) && (float) ($ai['mean'] ?? 0) > 0) {
        $info = [
            'rate' => (float) $ai['mean'],
            'source' => (string) ($ai['source'] ?? 'BOT+AI'),
            'as_of' => (string) ($ai['as_of'] ?? date('Y-m-d')),
            'via_ai' => true,
        ];
    }
} else {
    $info = bot_get_exchange_rate($iso, true);
}

if ($info === null) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Exchange rate not found for ' . $display . '. Try AI search.',
        'currency' => $display,
        'iso' => $iso,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'currency' => $display,
    'iso' => $iso,
    'rate' => round((float) $info['rate'], 4),
    'source' => (string) ($info['source'] ?? 'BOT'),
    'as_of' => $info['as_of'] ?? null,
    'via_ai' => !empty($info['via_ai']),
    'base' => 'TSh',
], JSON_UNESCAPED_UNICODE);
