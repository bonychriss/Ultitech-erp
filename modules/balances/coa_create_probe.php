<?php
/**
 * Deployment probe for coa_create.php — remove after verification.
 * URL: /ultimate/modules/balances/coa_create_probe.php
 */
header('Content-Type: text/plain; charset=utf-8');
echo "COA_CREATE_PROBE\n";
echo 'PHP ' . PHP_VERSION . "\n";

try {
    require_once __DIR__ . '/config/database.php';
    echo "bootstrap: OK\n";
} catch (Throwable $e) {
    echo "bootstrap: FAIL " . $e->getMessage() . "\n";
    exit(1);
}

global $pdo;
if ($pdo instanceof PDO) {
    try {
        $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        echo "pdo database: {$db}\n";
    } catch (Throwable $e) {
        echo "pdo database: unknown\n";
    }
    echo 'financial_accounts: ' . (function_exists('balances_connection_has_financial_accounts') && balances_connection_has_financial_accounts($pdo) ? 'yes' : 'no') . "\n";
    try {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM erp_account_categories')->fetchColumn();
        echo "erp_account_categories rows: {$n}\n";
    } catch (Throwable $e) {
        echo 'erp_account_categories: ' . $e->getMessage() . "\n";
    }
} else {
    echo "pdo: missing\n";
}

$main = __DIR__ . '/coa_create.php';
$src = is_file($main) ? (string) file_get_contents($main, false, null, 0, 12000) : '';
echo 'coa_create file: ' . (is_file($main) ? 'yes' : 'no') . "\n";
echo 'build marker: ' . (strpos($src, 'BALANCES_COA_CREATE_BUILD') !== false ? 'NEW' : 'OLD') . "\n";
echo 'balances_resolve_pdo: ' . (strpos((string) @file_get_contents(__DIR__ . '/functions.php', false, null, 0, 8000), 'balances_connection_has_financial_accounts') !== false ? 'NEW' : 'OLD') . "\n";
