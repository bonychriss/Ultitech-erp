<?php

require_once __DIR__ . '/../includes/invoices-lib.php';
require_once __DIR__ . '/../includes/quote-direct-create.php';

invoicesDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = sales_process_direct_quote_create($_POST);
    $websiteQuote = substr(trim((string) ($_POST['website_quote'] ?? '')), 0, 64);
    if ($websiteQuote !== '') {
        $quoteDbs = [$GLOBALS['pdo'] ?? null];
        if (function_exists('sales_pdo')) {
            $quoteDbs[] = sales_pdo();
        }
        $updated = [];
        foreach ($quoteDbs as $quoteDb) {
            if (!($quoteDb instanceof PDO) || in_array($quoteDb, $updated, true)) {
                continue;
            }
            $updated[] = $quoteDb;
            try {
                $quoteDb->prepare('UPDATE website_quote_requests SET status = \'quoted\' WHERE quote_number = ? AND LOWER(TRIM(COALESCE(status, \'\'))) NOT IN (\'deleted\', \'quoted\', \'accepted\', \'rejected\', \'closed\')')
                    ->execute([$websiteQuote]);
            } catch (Throwable $e) {
                error_log('create-quote website_quote status: ' . $e->getMessage());
            }
        }
    }
    echo json_encode([
        'ok' => true,
        'order_id' => $result['order_id'],
        'redirect' => $result['redirect'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
