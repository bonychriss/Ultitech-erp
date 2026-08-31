<?php
/**
 * Step-by-step probe for revenue_entries.php 500. Delete after use.
 * https://ultitech.io/ultimate/revenue_entries_probe.php?module=revenue
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

function step($msg)
{
    echo $msg . "\n";
    if (function_exists('flush')) {
        @flush();
    }
}

step('1. PHP ' . PHP_VERSION);

try {
    require_once __DIR__ . '/includes/functions.php';
    step('2. functions.php OK');
} catch (Throwable $e) {
    step('2. FAIL functions: ' . $e->getMessage());
    exit;
}

try {
    require_once __DIR__ . '/includes/revenue_ledger.php';
    step('3. revenue_ledger.php OK');
} catch (Throwable $e) {
    step('3. FAIL ledger: ' . $e->getMessage());
    exit;
}

try {
    requireLogin();
    step('4. requireLogin OK (logged in)');
} catch (Throwable $e) {
    step('4. FAIL login: ' . $e->getMessage());
    exit;
}

if (!isFinance() && !isAdmin()) {
    step('4b. FAIL access: not finance/admin');
    exit;
}
step('4b. access OK role=' . ($_SESSION['role'] ?? ''));

$revPdo = function_exists('revenue_resolve_pdo') ? revenue_resolve_pdo() : null;
if (!($revPdo instanceof PDO)) {
    step('5. FAIL revenue_resolve_pdo');
    exit;
}
$db = (string) $revPdo->query('SELECT DATABASE()')->fetchColumn();
$cnt = (int) $revPdo->query('SELECT COUNT(*) FROM revenue_entries')->fetchColumn();
step('5. PDO OK db=' . $db . ' rows=' . $cnt);

try {
    $renHasInvoices = function_exists('tableExists') && tableExists('invoices', $revPdo);
    $renHasCustomers = function_exists('tableExists') && tableExists('customers', $revPdo);
    step('6. tables invoices=' . ($renHasInvoices ? 'yes' : 'no') . ' customers=' . ($renHasCustomers ? 'yes' : 'no'));
} catch (Throwable $e) {
    step('6. FAIL tableExists: ' . $e->getMessage());
}

try {
    $st = $revPdo->query('SELECT re.*, NULL AS linked_invoice_number FROM revenue_entries re ORDER BY re.id DESC LIMIT 3');
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    step('7. simple list OK count=' . count($rows));
} catch (Throwable $e) {
    step('7. FAIL simple list: ' . $e->getMessage());
}

try {
    ob_start();
    $employeeHeaderTitle = 'Probe';
    $employeeHeaderSubtitle = '';
    $employeeHeaderCenterHtml = '';
    require __DIR__ . '/includes/header_employee.php';
    $hdr = ob_get_clean();
    step('8. header_employee OK bytes=' . strlen($hdr));
} catch (Throwable $e) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    step('8. FAIL header: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

$mainFile = __DIR__ . '/revenue_entries.php';
$build = is_file($mainFile) ? (strpos((string) file_get_contents($mainFile, false, null, 0, 8000), 'revenue_resolve_pdo') !== false ? 'NEW' : 'OLD') : 'MISSING';
step('9. revenue_entries.php on disk: ' . $build . ' size=' . (is_file($mainFile) ? filesize($mainFile) : 0));

step('DONE — if all OK but main page 500, upload latest revenue_entries.php (NEW build).');
