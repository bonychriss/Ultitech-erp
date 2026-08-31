<?php
/**
 * One-time repair after SQL import when data does not appear in UI.
 * Visit: repair_import_visibility.php?confirm=yes
 * Delete this file after use.
 */

require_once __DIR__ . '/includes/functions.php';

@ini_set('display_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/html; charset=UTF-8');

$controlPdo = (isset($control_pdo) && $control_pdo instanceof PDO) ? $control_pdo : ((isset($pdo) && $pdo instanceof PDO) ? $pdo : null);
$confirmed = isset($_GET['confirm']) && strtolower((string) $_GET['confirm']) === 'yes';

function riv_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function riv_count_by_company($pdo, $table)
{
    if (!($pdo instanceof PDO) || !tableExists($table, $pdo) || !columnExists($table, 'company_id', $pdo)) {
        return array();
    }
    try {
        $sql = 'SELECT COALESCE(company_id, 0) AS company_id, COUNT(*) AS c FROM `' . str_replace('`', '``', $table) . '` GROUP BY COALESCE(company_id,0) ORDER BY company_id ASC';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        return array();
    }
}

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Repair Imported Data Visibility</title>';
echo '<style>body{font-family:Segoe UI,Arial,sans-serif;margin:24px;max-width:1000px}';
echo 'table{border-collapse:collapse;width:100%;margin:10px 0}th,td{border:1px solid #ddd;padding:8px;text-align:left}';
echo 'th{background:#f5f5f5}.ok{color:#166534}.fail{color:#991b1b}.warn{background:#fffbeb;border:1px solid #fcd34d;padding:12px;border-radius:8px;margin:12px 0}.btn{display:inline-block;margin:6px 8px 6px 0;padding:10px 16px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:8px}</style></head><body>';
echo '<h1>Repair Imported Data Visibility</h1>';

if (!($controlPdo instanceof PDO)) {
    echo '<p class="fail">No database connection. Check env.php and includes/config.php</p></body></html>';
    exit;
}

$controlDb = defined('DB_NAME') ? DB_NAME : '';
$controlHost = defined('DB_HOST') ? DB_HOST : '';

$ultimateId = 1;
if (tableExists('companies', $controlPdo)) {
    try {
        $st = $controlPdo->query("SELECT id FROM companies WHERE company_slug = 'ultimate' ORDER BY id ASC LIMIT 1");
        $ultimateId = (int) ($st->fetchColumn() ?: 1);
        if ($ultimateId <= 0) {
            $ultimateId = 1;
        }
    } catch (Throwable $e) {
        $ultimateId = 1;
    }
}

echo '<table>';
echo '<tr><th>Control DB</th><td><code>' . riv_h($controlDb) . '</code></td></tr>';
echo '<tr><th>Control Host</th><td><code>' . riv_h($controlHost) . '</code></td></tr>';
echo '<tr><th>Ultimate company_id</th><td>' . (int) $ultimateId . '</td></tr>';
echo '</table>';

echo '<h2>Before repair</h2>';
echo '<table><tr><th>Table</th><th>Total rows</th><th>Rows by company_id</th></tr>';
$watchTables = array('payment_vouchers', 'voucher_items', 'financial_accounts', 'account_transactions', 'users');
foreach ($watchTables as $tbl) {
    $total = null;
    if (tableExists($tbl, $controlPdo)) {
        try {
            $total = (int) $controlPdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $tbl) . '`')->fetchColumn();
        } catch (Throwable $e) {
            $total = null;
        }
    }
    $dist = riv_count_by_company($controlPdo, $tbl);
    $parts = array();
    foreach ($dist as $row) {
        $parts[] = ((int) $row['company_id']) . ' => ' . ((int) $row['c']);
    }
    echo '<tr><td><code>' . riv_h($tbl) . '</code></td><td>' . ($total === null ? 'n/a' : (int) $total) . '</td><td>' . riv_h(implode(', ', $parts)) . '</td></tr>';
}
echo '</table>';

if (!$confirmed) {
    echo '<div class="warn"><strong>This repair will:</strong><ul>';
    echo '<li>Ensure Ultimate company points to control DB (<code>' . riv_h($controlDb) . '</code>).</li>';
    echo '<li>Backfill NULL/0 <code>company_id</code> rows to Ultimate.</li>';
    echo '<li>Move rows with invalid company_id in key tables to Ultimate (common after cross-environment import).</li>';
    echo '</ul></div>';
    echo '<a class="btn" href="repair_import_visibility.php?confirm=yes">Run repair now</a>';
    echo '<a class="btn" href="debug_company_data.php">Open data scan</a>';
    echo '</body></html>';
    exit;
}

$changes = array();
$errors = array();

try {
    if (tableExists('companies', $controlPdo)) {
        if (columnExists('companies', 'db_host', $controlPdo)) {
            $st = $controlPdo->prepare("SELECT db_name, db_host FROM companies WHERE company_slug = 'ultimate' LIMIT 1");
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: array();
            $changes[] = 'Ultimate DB mapping kept as-is: db_name=' . (string) ($row['db_name'] ?? '')
                . ', db_host=' . (string) ($row['db_host'] ?? '');
        } else {
            $st = $controlPdo->prepare("SELECT db_name FROM companies WHERE company_slug = 'ultimate' LIMIT 1");
            $st->execute();
            $dbName = (string) ($st->fetchColumn() ?: '');
            $changes[] = 'Ultimate DB mapping kept as-is: db_name=' . $dbName;
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Failed reading companies mapping: ' . $e->getMessage();
}

try {
    if (function_exists('backfillLegacyCompanyIdsForUltimate')) {
        $bf = backfillLegacyCompanyIdsForUltimate($controlPdo, $ultimateId);
        $sum = 0;
        foreach ($bf as $n) {
            if ((int) $n > 0) {
                $sum += (int) $n;
            }
        }
        $changes[] = 'Backfill NULL/0 company_id rows updated: ' . (int) $sum;
    }
} catch (Throwable $e) {
    $errors[] = 'Backfill failed: ' . $e->getMessage();
}

$fixTables = array('payment_vouchers', 'voucher_items', 'financial_accounts', 'account_transactions', 'users');
foreach ($fixTables as $tbl) {
    try {
        if (!tableExists($tbl, $controlPdo) || !columnExists($tbl, 'company_id', $controlPdo)) {
            continue;
        }
        $sql = 'UPDATE `' . str_replace('`', '``', $tbl) . '` t
                LEFT JOIN companies c ON c.id = t.company_id
                SET t.company_id = ?
                WHERE t.company_id IS NOT NULL AND t.company_id <> 0 AND c.id IS NULL';
        $st = $controlPdo->prepare($sql);
        $st->execute(array($ultimateId));
        if ((int) $st->rowCount() > 0) {
            $changes[] = $tbl . ': moved ' . (int) $st->rowCount() . ' orphan company_id rows to Ultimate';
        }
    } catch (Throwable $e) {
        $errors[] = $tbl . ' repair failed: ' . $e->getMessage();
    }
}

echo '<h2>Repair result</h2>';
if (!empty($changes)) {
    echo '<ul class="ok">';
    foreach ($changes as $line) {
        echo '<li>' . riv_h($line) . '</li>';
    }
    echo '</ul>';
}
if (!empty($errors)) {
    echo '<ul class="fail">';
    foreach ($errors as $line) {
        echo '<li>' . riv_h($line) . '</li>';
    }
    echo '</ul>';
}

echo '<h2>After repair</h2>';
echo '<table><tr><th>Table</th><th>Total rows</th><th>Rows by company_id</th></tr>';
foreach ($watchTables as $tbl) {
    $total = null;
    if (tableExists($tbl, $controlPdo)) {
        try {
            $total = (int) $controlPdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $tbl) . '`')->fetchColumn();
        } catch (Throwable $e) {
            $total = null;
        }
    }
    $dist = riv_count_by_company($controlPdo, $tbl);
    $parts = array();
    foreach ($dist as $row) {
        $parts[] = ((int) $row['company_id']) . ' => ' . ((int) $row['c']);
    }
    echo '<tr><td><code>' . riv_h($tbl) . '</code></td><td>' . ($total === null ? 'n/a' : (int) $total) . '</td><td>' . riv_h(implode(', ', $parts)) . '</td></tr>';
}
echo '</table>';

echo '<p><a class="btn" href="ultimate/payment-vouchers">Open Payment Vouchers</a> <a class="btn" href="debug_company_data.php">Re-scan DB</a></p>';
echo '<p class="warn"><strong>Delete</strong> <code>repair_import_visibility.php</code> after verification.</p>';
echo '</body></html>';
