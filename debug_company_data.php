<?php
/**
 * Find which MySQL database has ERP data and point Ultimate company at it.
 * Delete after fixing.
 */

require_once __DIR__ . '/includes/functions.php';

@ini_set('display_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/html; charset=UTF-8');

$controlPdo = (isset($control_pdo) && $control_pdo instanceof PDO) ? $control_pdo : ((isset($pdo) && $pdo instanceof PDO) ? $pdo : null);

function dcd_row($label, $value = null)
{
    if ($value === null) {
        echo '<tr><th colspan="2">' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th></tr>';
        return;
    }
    echo '<tr><th>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th><td>'
        . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
}

function dcd_probe_db($host, $dbName, $user, $pass)
{
    $out = array('ok' => false, 'error' => '', 'pdo' => null);
    $hosts = array_values(array_unique(array_filter(array(
        trim((string) $host),
        defined('DB_HOST') ? DB_HOST : '',
        'localhost',
    ))));
    foreach ($hosts as $h) {
        if ($h === '') {
            continue;
        }
        try {
            $dsn = 'mysql:host=' . $h . ';dbname=' . $dbName . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $user, $pass, array(PDO::ATTR_TIMEOUT => 5));
            $out['ok'] = true;
            $out['pdo'] = $pdo;
            $out['host'] = $h;
            return $out;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
    }
    return $out;
}

function dcd_table_count($pdo, $table)
{
    if (!($pdo instanceof PDO)) {
        return null;
    }
    try {
        $st = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

function dcd_has_table($pdo, $table)
{
    if (!($pdo instanceof PDO)) {
        return false;
    }
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE ?');
        $st->execute(array($table));
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Find ERP Data Database</title>';
echo '<style>body{font-family:Segoe UI,Arial,sans-serif;margin:24px;max-width:1100px}';
echo 'table{border-collapse:collapse;width:100%;margin:12px 0}th,td{border:1px solid #ddd;padding:8px;text-align:left}';
echo 'th{background:#f5f5f5}.hit{background:#ecfdf5}.miss{color:#991b1b}.btn{display:inline-block;margin:6px 8px 6px 0;padding:8px 14px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:6px}';
echo '.warn{background:#fffbeb;border:1px solid #fcd34d;padding:12px;border-radius:8px;margin:12px 0}</style></head><body>';
echo '<h1>Find where your ERP data lives</h1>';

if (!($controlPdo instanceof PDO)) {
    echo '<p class="miss">No database connection.</p></body></html>';
    exit;
}

if (isset($_GET['backfill']) && $_GET['backfill'] === 'yes') {
    $bf = backfillLegacyCompanyIdsForUltimate($controlPdo);
    $totalRows = 0;
    $tableCount = 0;
    foreach ($bf as $tbl => $n) {
        if ((int) $n > 0) {
            $tableCount++;
            $totalRows += (int) $n;
        }
    }
    echo '<p class="hit"><strong>Backfill complete.</strong> ' . (int) $tableCount . ' table(s), '
        . (int) $totalRows . ' row(s) assigned to Ultimate company. <a href="debug_company_data.php">Refresh scan</a></p>';
    if (!empty($bf)) {
        echo '<ul>';
        foreach ($bf as $tbl => $n) {
            if ((int) $n !== 0) {
                echo '<li><code>' . htmlspecialchars((string) $tbl, ENT_QUOTES, 'UTF-8') . '</code>: ' . (int) $n . '</li>';
            }
        }
        echo '</ul>';
    }
}

$dbUser = defined('DB_USER') ? DB_USER : '';
$dbPass = defined('DB_PASS') ? DB_PASS : '';
$controlDb = defined('DB_NAME') ? DB_NAME : '';

// One-click fix: point Ultimate company at a database
if (isset($_GET['point_ultimate']) && trim((string) $_GET['point_ultimate']) !== '') {
    $targetDb = trim((string) $_GET['point_ultimate']);
    $targetHost = trim((string) ($_GET['host'] ?? ''));
    try {
        if (columnExists('companies', 'db_host', $controlPdo)) {
            $st = $controlPdo->prepare("UPDATE companies SET db_name = ?, db_host = ? WHERE company_slug = 'ultimate'");
            $st->execute(array($targetDb, $targetHost !== '' ? $targetHost : null));
        } else {
            $st = $controlPdo->prepare("UPDATE companies SET db_name = ? WHERE company_slug = 'ultimate'");
            $st->execute(array($targetDb));
        }
        echo '<p class="hit"><strong>Updated Ultimate</strong> &mdash; db_name=<code>' . htmlspecialchars($targetDb, ENT_QUOTES, 'UTF-8') . '</code>'
            . ($targetHost !== '' ? ', db_host=<code>' . htmlspecialchars($targetHost, ENT_QUOTES, 'UTF-8') . '</code>' : '')
            . '. <a href="ultimate/payment-vouchers">Open payment vouchers</a> | <a href="debug_company_data.php">Rescan</a></p>';
    } catch (Throwable $e) {
        echo '<p class="miss">Update failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

echo '<div class="warn"><strong>Your current control DB is empty.</strong> '
    . '<code>' . htmlspecialchars($controlDb, ENT_QUOTES, 'UTF-8') . '</code> has <strong>0 payment vouchers</strong>. '
    . 'The app is connected correctly, but this database was newly created by setup &mdash; your old data is almost certainly in a <em>different</em> database name '
    . '(often <code>ultimate_trading_voucher</code> on local/XAMPP or an older StackCP export).</div>';

echo '<table>';
dcd_row('env.php DB_NAME (control)', $controlDb);
dcd_row('DB_HOST', defined('DB_HOST') ? DB_HOST : '');
dcd_row('DB_USER', $dbUser);
echo '</table>';

// Collect database names to probe
$probeNames = array();
try {
    foreach ($controlPdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $db) {
        $probeNames[] = (string) $db;
    }
} catch (Throwable $e) {
}
$extraCandidates = array(
    $controlDb,
    'ultimate_trading_voucher',
    'ultimate_trading-voucher',
    'ultimategeneraltrading',
    'roadmaster_db-3530393454a2',
);
if (tableExists('companies', $controlPdo)) {
    try {
        foreach ($controlPdo->query('SELECT db_name FROM companies WHERE TRIM(db_name) <> \'\'')->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $extraCandidates[] = (string) $n;
        }
    } catch (Throwable $e) {
    }
}
$probeNames = array_values(array_unique(array_filter(array_merge($probeNames, $extraCandidates), function ($n) {
    $n = (string) $n;
    return $n !== '' && !in_array($n, array('information_schema', 'performance_schema', 'mysql', 'sys'), true);
})));

$hostVariants = array(
    'default' => defined('DB_HOST') ? DB_HOST : 'localhost',
    'roadmaster' => 'sdb-83.hosting.stackcp.net',
);

echo '<h2>Database scan (looking for payment_vouchers + users)</h2>';
echo '<table><tr><th>Database</th><th>Host</th><th>users</th><th>payment_vouchers</th><th>Action</th></tr>';

$bestDb = '';
$bestHost = '';
$bestVouchers = -1;

foreach ($probeNames as $dbName) {
    foreach ($hostVariants as $hostKey => $host) {
        $probe = dcd_probe_db($host, $dbName, $dbUser, $dbPass);
        if (!$probe['ok']) {
            continue;
        }
        $tpdo = $probe['pdo'];
        $users = dcd_has_table($tpdo, 'users') ? dcd_table_count($tpdo, 'users') : null;
        $vouchers = dcd_has_table($tpdo, 'payment_vouchers') ? dcd_table_count($tpdo, 'payment_vouchers') : null;
        if ($vouchers === null && $users === null) {
            continue;
        }
        $vouchers = $vouchers === null ? 0 : $vouchers;
        $users = $users === null ? 0 : $users;
        $class = ($vouchers > 0) ? 'hit' : '';
        if ($vouchers > $bestVouchers) {
            $bestVouchers = $vouchers;
            $bestDb = $dbName;
            $bestHost = (string) $probe['host'];
        }
        $action = '';
        if ($vouchers > 0) {
            $q = 'debug_company_data.php?point_ultimate=' . rawurlencode($dbName) . '&host=' . rawurlencode((string) $probe['host']);
            $action = '<a class="btn" href="' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">Use for Ultimate</a>';
        }
        echo '<tr class="' . $class . '"><td>' . htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $probe['host'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($hostKey, ENT_QUOTES, 'UTF-8') . ')</td>';
        echo '<td>' . (int) $users . '</td><td><strong>' . (int) $vouchers . '</strong></td><td>' . $action . '</td></tr>';
    }
}

echo '</table>';

echo '<h2>Sales data scan (sales_orders / quotations)</h2>';
echo '<p>Set <code>$SALES_DB_NAME</code> in <code>includes/env.php</code> to the database below that has quotations.</p>';
echo '<table><tr><th>Database</th><th>Host</th><th>sales_orders</th><th>quotations</th></tr>';
$bestSalesDb = '';
$bestSalesHost = '';
$bestQuotes = -1;
foreach ($probeNames as $dbName) {
    foreach ($hostVariants as $hostKey => $host) {
        $probe = dcd_probe_db($host, $dbName, $dbUser, $dbPass);
        if (!$probe['ok']) {
            continue;
        }
        $tpdo = $probe['pdo'];
        if (!dcd_has_table($tpdo, 'sales_orders')) {
            continue;
        }
        $orders = dcd_table_count($tpdo, 'sales_orders');
        $quotes = null;
        try {
            $st = $tpdo->query("SELECT COUNT(*) FROM sales_orders WHERE status = 'quotation'");
            $quotes = (int) $st->fetchColumn();
        } catch (Throwable $e) {
            $quotes = null;
        }
        $class = ($quotes > 0) ? 'hit' : '';
        if ($quotes > $bestQuotes) {
            $bestQuotes = $quotes;
            $bestSalesDb = $dbName;
            $bestSalesHost = (string) $probe['host'];
        }
        echo '<tr class="' . $class . '"><td>' . htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $probe['host'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . (int) $orders . '</td><td><strong>' . (int) $quotes . '</strong></td></tr>';
    }
}
echo '</table>';
if ($bestQuotes > 0) {
    echo '<p class="hit"><strong>Sales DB:</strong> Add to <code>includes/env.php</code>: '
        . '<code>$SALES_DB_NAME = \'' . htmlspecialchars($bestSalesDb, ENT_QUOTES, 'UTF-8') . '\';</code> '
        . ' (' . (int) $bestQuotes . ' quotations in <code>' . htmlspecialchars($bestSalesDb, ENT_QUOTES, 'UTF-8') . '</code>)</p>';
} else {
    echo '<p class="miss">No database with <code>sales_orders</code> found. Import your local <code>ultimate_trading_voucher</code> or <code>new_trading_voucher_db</code> dump into StackCP.</p>';
}

if ($bestVouchers > 0) {
    $q = 'debug_company_data.php?point_ultimate=' . rawurlencode($bestDb) . '&host=' . rawurlencode($bestHost);
    echo '<p class="hit"><strong>Recommended:</strong> Database <code>' . htmlspecialchars($bestDb, ENT_QUOTES, 'UTF-8')
        . '</code> on <code>' . htmlspecialchars($bestHost, ENT_QUOTES, 'UTF-8') . '</code> has '
        . (int) $bestVouchers . ' vouchers. '
        . '<a class="btn" href="' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">Point Ultimate here</a></p>';
} else {
    echo '<p class="miss"><strong>No database with payment_vouchers was found</strong> using this MySQL user on sdb-86 or sdb-83.</p>';
    echo '<ul>';
    echo '<li>In <strong>phpMyAdmin</strong>, check the left sidebar for databases with tables like <code>payment_vouchers</code>, <code>users</code>.</li>';
    echo '<li>If data is only on your PC (XAMPP <code>ultimate_trading_voucher</code>), <strong>export SQL</strong> and import into '
        . '<code>' . htmlspecialchars($controlDb, ENT_QUOTES, 'UTF-8') . '</code> on StackCP.</li>';
    echo '<li>Or change <code>env.php</code> <code>DB_NAME</code> to the database that actually contains your tables.</li>';
    echo '</ul>';
}

echo '<h2>Option: also set control DB in env.php</h2>';
echo '<p>If your full ERP should use the database that has all tables, update <code>env.php</code> on the server:</p>';
echo '<pre>DB_NAME = \'THE_DATABASE_WITH_DATA\';\nDB_HOST = \'sdb-86.hosting.stackcp.net\';  // or correct host</pre>';

echo '<h2>Tables in current control DB (<code>' . htmlspecialchars($controlDb, ENT_QUOTES, 'UTF-8') . '</code>)</h2>';
echo '<p>Row counts for key tables (shows what was auto-created vs imported):</p>';
echo '<table><tr><th>Table</th><th>Rows</th></tr>';
$keyTables = array(
    'users', 'companies', 'payment_vouchers', 'voucher_items', 'voucher_attachments',
    'approval_logs', 'financial_accounts', 'attendance_records', 'tasks', 'meetings',
    'notifications', 'company_settings', 'company_modules',
);
try {
    foreach ($controlPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
        $tbl = (string) $tbl;
        $cnt = dcd_table_count($controlPdo, $tbl);
        $highlight = in_array($tbl, $keyTables, true) ? ' style="font-weight:600"' : '';
        echo '<tr' . $highlight . '><td>' . htmlspecialchars($tbl, ENT_QUOTES, 'UTF-8') . '</td><td>' . ($cnt === null ? 'n/a' : (int) $cnt) . '</td></tr>';
    }
} catch (Throwable $e) {
    dcd_row('Error', $e->getMessage());
}
echo '</table>';

echo '<h2>Import your real data (required)</h2>';
echo '<p>Your StackCP account only has one application database, and it is <strong>empty of ERP records</strong>. '
    . 'Data on your PC is in <strong>XAMPP</strong> database <code>ultimate_trading_voucher</code> (see local <code>env.local.php</code>).</p>';
echo '<ol>';
echo '<li>On <strong>local XAMPP</strong>: open phpMyAdmin &rarr; database <code>ultimate_trading_voucher</code> &rarr; Export &rarr; SQL &rarr; download file.</li>';
echo '<li>On <strong>ultitech.io</strong>: StackCP phpMyAdmin &rarr; database <code>' . htmlspecialchars($controlDb, ENT_QUOTES, 'UTF-8') . '</code> &rarr; Import &rarr; choose the SQL file.</li>';
echo '<li>If import errors on <code>companies</code> or <code>users</code>, use Export on local with <strong>custom</strong> and exclude those two tables (keep production companies/users).</li>';
echo '<li>After import, run <a href="debug_company_data.php?backfill=yes">backfill company_id</a> then reload payment vouchers.</li>';
echo '<li>Update <code>env.php</code> only if StackCP gave you a <em>different</em> database name than <code>' . htmlspecialchars($controlDb, ENT_QUOTES, 'UTF-8') . '</code>.</li>';
echo '</ol>';
echo '<p>Full guide: <code>IMPORT_PRODUCTION_DATA.md</code> in the project folder.</p>';

echo '<p>Then re-run this page. Delete <code>debug_company_data.php</code> when done.</p>';
echo '</body></html>';
