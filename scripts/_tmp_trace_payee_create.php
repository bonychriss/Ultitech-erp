<?php
/**
 * Trace where a Manage-Payees-style create would land vs what Create Voucher lists.
 */
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/ultitech_erp/ultimate/employee/manage-payees.php';
$_SERVER['REQUEST_URI'] = '/ultitech_erp/ultimate/employee/manage-payees.php?module=voucher&company_slug=ultimate';
$_GET['company_slug'] = 'ultimate';
$_GET['module'] = 'voucher';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['company_id'] = 1;
$_SESSION['company_slug'] = 'ultimate';
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'Local Test';

require __DIR__ . '/../includes/functions.php';

echo "company_url(employee)=" . (function_exists('company_url') ? company_url('employee') : 'n/a') . PHP_EOL;
echo "company_url(api)=" . (function_exists('company_url') ? company_url('employee/payees-ui/api/index.php') : 'n/a') . PHP_EOL;

require_once __DIR__ . '/../employee/payees-ui/lib.php';
$assets = payeesUiLoadReactAssets();
echo "injected apiUrl=" . ($assets['apiUrl'] ?? 'null') . PHP_EOL;

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
global $pdo;
echo "pdo_db=" . ($pdo instanceof PDO ? $pdo->query('SELECT DATABASE()')->fetchColumn() : 'none') . PHP_EOL;

// Simulate API create of a unique name then check both DBs
$name = 'momo_local_trace_' . date('His');
try {
    $created = createOrReactivatePayee($pdo, $name, 'Supplier', '', 'trace');
    echo "created=" . json_encode($created) . PHP_EOL;
} catch (Throwable $e) {
    echo "create_err=" . $e->getMessage() . PHP_EOL;
}

foreach ([
    'control' => defined('DB_NAME') ? DB_NAME : '',
    'data' => defined('DATA_DB_NAME') ? DATA_DB_NAME : '',
] as $label => $dbName) {
    if ($dbName === '') continue;
    $c = connectToTenantDatabase($dbName);
    if (!($c instanceof PDO)) {
        echo "{$label}: connect fail\n";
        continue;
    }
    $st = $c->prepare('SELECT id,name,type,is_active FROM payees WHERE name = ?');
    $st->execute([$name]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    echo "{$label}({$dbName}) has_name=" . ($row ? json_encode($row) : 'NO') . PHP_EOL;
}

// Check existing momo / momo variants everywhere
$dbs = [DB_NAME, DATA_DB_NAME];
if (defined('ROADMASTER_DB_NAME')) $dbs[] = ROADMASTER_DB_NAME;
foreach (array_unique(array_filter($dbs)) as $dbName) {
    $c = connectToTenantDatabase($dbName);
    if (!($c instanceof PDO)) continue;
    try {
        if (!$c->query("SHOW TABLES LIKE 'payees'")->fetchColumn()) continue;
        $rows = $c->query("SELECT id,name,type,is_active,created_at FROM payees WHERE LOWER(name) LIKE '%momo%' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo "SCAN {$dbName} momo_rows=" . count($rows) . PHP_EOL;
        foreach ($rows as $r) echo '  ' . json_encode($r) . PHP_EOL;
    } catch (Throwable $e) {
        echo "SCAN {$dbName} ERR " . $e->getMessage() . PHP_EOL;
    }
}

// What create-voucher would inject
$payees = fetchActivePayees($pdo);
$momo = array_values(array_filter($payees, static fn($p) => stripos((string)$p['name'], 'momo') !== false));
echo 'create_voucher_list_momo=' . json_encode($momo) . PHP_EOL;
