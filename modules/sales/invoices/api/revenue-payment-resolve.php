<?php

declare(strict_types=1);

/**
 * Resolve (and sync) a revenue entry id for an invoice so the list Pay action can open the modal.
 */

require_once __DIR__ . '/../includes/invoices-view-lib.php';
require_once dirname(__DIR__, 4) . '/includes/revenue_sync.php';

invoicesViewDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $invoiceId = (int) ($_GET['invoice_id'] ?? $_GET['id'] ?? 0);
    if ($invoiceId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invoice id is required.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $revEntryId = (int) syncInvoiceToRevenue($salesDb, $invoiceId);
    if ($revEntryId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Could not prepare revenue entry for this invoice.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'invoice_id' => $invoiceId,
        'revenue_entry_id' => $revEntryId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
