<?php

declare(strict_types=1);

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/revenue-lib.php';

try {
    revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }

    if (is_file(dirname(__DIR__, 3) . '/includes/bot_exchange_rates.php')) {
        require_once dirname(__DIR__, 3) . '/includes/bot_exchange_rates.php';
    }

    $currencyRaw = strtoupper(trim((string) ($_GET['currency'] ?? '')));
    if ($currencyRaw === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'currency required']);
        exit;
    }

    if ($currencyRaw === 'TZS') {
        echo json_encode([
            'ok' => true,
            'currency' => 'TZS',
            'iso' => 'TZS',
            'rate' => 1.0,
            'source' => 'BOT',
            'as_of' => date('Y-m-d'),
            'via_ai' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!function_exists('bot_get_exchange_rate')) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Exchange rate service unavailable.']);
        exit;
    }

    $info = bot_get_exchange_rate($currencyRaw, true);
    if ($info === null) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'error' => 'Exchange rate not found for ' . $currencyRaw . '.',
            'currency' => $currencyRaw,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'currency' => $currencyRaw,
        'iso' => $currencyRaw,
        'rate' => round((float) ($info['rate'] ?? 0), 4),
        'source' => (string) ($info['source'] ?? 'BOT'),
        'as_of' => $info['as_of'] ?? null,
        'via_ai' => !empty($info['via_ai']),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
