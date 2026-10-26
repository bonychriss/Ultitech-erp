<?php
/**
 * CLI: php modules/revenue/scripts/renumber-vouchers-cli.php [company_slug]
 */
$slug = strtolower(trim((string) ($argv[1] ?? 'ultimate')));
$_GET['company_slug'] = $slug;
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/' . $slug . '/modules/revenue/scripts/renumber-vouchers-cli.php';
$_SERVER['SCRIPT_NAME'] = '/' . $slug . '/modules/revenue/scripts/renumber-vouchers-cli.php';

require_once dirname(__DIR__, 3) . '/includes/config.php';
require_once dirname(__DIR__, 3) . '/includes/revenue_sync.php';

if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "No PDO\n");
    exit(1);
}

$result = renumberRevenueVouchersByEntryMonth($pdo);
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
