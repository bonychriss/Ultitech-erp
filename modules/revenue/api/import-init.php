<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';
require_once __DIR__ . '/../includes/revenue-import-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $createInit = revenue_build_create_init($pdo);
    $listUrl = function_exists('app_url')
        ? app_url('/revenue_entries.php?module=revenue')
        : '/revenue_entries.php?module=revenue';

    $payload = [
        'csrf_token' => function_exists('csrf_token') ? csrf_token() : '',
        'default_year' => (int) date('Y'),
        'template_columns' => revenue_import_template_columns(),
        'sub_accounts' => $createInit['sub_accounts'] ?? [],
        'default_sub_account_id' => $createInit['default_sub_account_id'] ?? 0,
        'financial_accounts' => $createInit['financial_accounts'] ?? [],
        'currencies' => $createInit['currencies'] ?? [],
        'default_currency' => $createInit['default_currency'] ?? 'TZS',
        'payment_modes' => $createInit['payment_modes'] ?? [],
        'vat_rates' => $createInit['vat_rates'] ?? [],
        'list_url' => $listUrl,
    ];

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
