<?php
/**
 * One-shot: renumber revenue voucher_number by entry_date month sequence.
 * Admin/Finance only. Safe to re-run (idempotent when already correct).
 */
require_once dirname(__DIR__, 3) . '/includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (!function_exists('renumberRevenueVouchersByEntryMonth')) {
    require_once dirname(__DIR__, 3) . '/includes/revenue_sync.php';
}

try {
    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database unavailable');
    }
    $result = renumberRevenueVouchersByEntryMonth($pdo);
    echo json_encode([
        'ok' => true,
        'updated' => (int) ($result['updated'] ?? 0),
        'months' => (int) ($result['months'] ?? 0),
        'message' => 'Voucher numbers renumbered by entry month.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
