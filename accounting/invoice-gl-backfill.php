<?php
/**
 * One-time / repeatable backfill: post historical invoices & revenue to the general ledger.
 *
 * CLI:  php accounting/invoice-gl-backfill.php
 * Web:  /accounting/invoice-gl-backfill.php  (admin/finance only)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/invoice_gl_posting.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    requireLogin();
    if (!isAdmin() && !isFinance()) {
        http_response_code(403);
        die('Access denied.');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

global $pdo;

$dryRun = in_array('--dry-run', $argv ?? [], true) || isset($_GET['dry_run']);
echo ($dryRun ? "DRY RUN � no journal entries will be created\n\n" : "Posting historical records to general ledger...\n\n");

$stats = invoice_gl_backfill_all($pdo, ['dry_run' => $dryRun]);

echo "Invoices recognized:  {$stats['invoices_recognized']}\n";
echo "Invoice payments:     {$stats['invoice_payments']}\n";
echo "Revenue recognized:   {$stats['revenue_recognized']}\n";
echo "Revenue payments:     {$stats['revenue_payments']}\n";
echo "Skipped (existing):   {$stats['skipped']}\n";

if (!empty($stats['errors'])) {
    echo "\nErrors (" . count($stats['errors']) . "):\n";
    foreach ($stats['errors'] as $err) {
        echo "  - {$err}\n";
    }
} else {
    echo "\nCompleted with no errors.\n";
}

if (!$dryRun && invoice_gl_tables_ready($pdo)) {
    $jeCount = (int) $pdo->query('SELECT COUNT(*) FROM erp_journal_entries')->fetchColumn();
    echo "Total journal entries in GL: {$jeCount}\n";
}
