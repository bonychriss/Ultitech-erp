<?php
/**
 * Link Chart of Accounts (financial_accounts) to General Ledger (erp_accounts).
 *
 * CLI:  php accounting/fa-gl-sync.php [--force]
 * Web:  /accounting/fa-gl-sync.php  (admin/finance only)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fa_gl_linking.php';

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

$force = in_array('--force', $argv ?? [], true) || isset($_GET['force']);
echo ($force ? "Force re-linking all accounts...\n\n" : "Linking Chart of Accounts to General Ledger...\n\n");

$stats = fa_gl_sync_all($pdo, ['force' => $force]);

echo "Linked/updated: {$stats['linked']}\n";
echo "Skipped:        {$stats['skipped']}\n";

if (!empty($stats['errors'])) {
    echo "\nErrors (" . count($stats['errors']) . "):\n";
    foreach ($stats['errors'] as $err) {
        echo "  - {$err}\n";
    }
} else {
    echo "\nCompleted with no errors.\n";
}

if (fa_gl_has_gl_link_column($pdo)) {
    echo "\nCurrent links:\n";
    $rows = $pdo->query(
        'SELECT fa.id, fa.name, fa.gl_account_id, ea.code AS gl_code, ea.name AS gl_name
         FROM financial_accounts fa
         LEFT JOIN erp_accounts ea ON ea.id = fa.gl_account_id
         WHERE fa.gl_account_id IS NOT NULL AND fa.gl_account_id > 0
         ORDER BY fa.id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $faName = fa_gl_strip_code((string) ($r['name'] ?? ''));
        $glLabel = trim((string) ($r['gl_code'] ?? '') . ' ' . (string) ($r['gl_name'] ?? ''));
        echo sprintf("  FA #%d %-28s -> GL #%d %s\n", (int) $r['id'], $faName, (int) $r['gl_account_id'], $glLabel);
    }
}
