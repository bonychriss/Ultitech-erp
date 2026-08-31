<?php
/**
 * One-time control database setup for multi-company login.
 * Visit: setup_multicompany_db.php?confirm=yes
 * Delete this file after successful setup.
 */

@ini_set('display_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/html; charset=UTF-8');

$confirmed = isset($_GET['confirm']) && strtolower((string) $_GET['confirm']) === 'yes';

echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Multi-Company DB Setup</title>';
echo '<style>body{font-family:Segoe UI,Arial,sans-serif;margin:24px;background:#f8fafc}';
echo 'table{border-collapse:collapse;background:#fff;width:100%;max-width:900px;margin:12px 0}';
echo 'th,td{border:1px solid #e5e7eb;padding:8px 10px;text-align:left}th{background:#f3f4f6;width:220px}';
echo '.ok{color:#166534}.fail{color:#991b1b}a.btn{display:inline-block;margin-top:12px;padding:10px 16px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:8px}</style></head><body>';
echo '<h1>Multi-Company Control DB Setup</h1>';

if (!$confirmed) {
    echo '<p>This will create <code>companies</code>, <code>users</code>, and related control tables in your control database, then seed default companies if empty.</p>';
    echo '<p><strong>Only run once.</strong> Delete this file after success.</p>';
    echo '<a class="btn" href="setup_multicompany_db.php?confirm=yes">Run setup now</a>';
    echo '</body></html>';
    exit;
}

require_once __DIR__ . '/includes/functions.php';

$controlPdo = (isset($control_pdo) && $control_pdo instanceof PDO) ? $control_pdo : ((isset($pdo) && $pdo instanceof PDO) ? $pdo : null);

echo '<table>';
echo '<tr><th>Control DB</th><td>' . htmlspecialchars((string) (defined('DB_NAME') ? DB_NAME : ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
echo '<tr><th>DB Host</th><td>' . htmlspecialchars((string) (defined('DB_HOST') ? DB_HOST : ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';

if (!($controlPdo instanceof PDO)) {
    echo '<tr><th>Status</th><td class="fail">No PDO connection</td></tr></table></body></html>';
    exit;
}

$beforeCompanies = function_exists('tableExists') && tableExists('companies', $controlPdo);
$beforeUsers = function_exists('tableExists') && tableExists('users', $controlPdo);

$schemaOk = function_exists('ensureMultiCompanyControlSchema') ? ensureMultiCompanyControlSchema() : false;
$schemaError = function_exists('getLastControlSchemaError') ? getLastControlSchemaError() : '';

$afterCompanies = function_exists('tableExists') && tableExists('companies', $controlPdo);
$afterUsers = function_exists('tableExists') && tableExists('users', $controlPdo);

echo '<tr><th>Schema run</th><td class="' . ($schemaOk ? 'ok' : 'fail') . '">' . ($schemaOk ? 'completed' : htmlspecialchars($schemaError !== '' ? $schemaError : 'see server error_log', ENT_QUOTES, 'UTF-8')) . '</td></tr>';
echo '<tr><th>companies (before)</th><td>' . ($beforeCompanies ? 'yes' : 'no') . '</td></tr>';
echo '<tr><th>companies (after)</th><td class="' . ($afterCompanies ? 'ok' : 'fail') . '">' . ($afterCompanies ? 'yes' : 'no') . '</td></tr>';
echo '<tr><th>users (before)</th><td>' . ($beforeUsers ? 'yes' : 'no') . '</td></tr>';
echo '<tr><th>users (after)</th><td class="' . ($afterUsers ? 'ok' : 'fail') . '">' . ($afterUsers ? 'yes' : 'no') . '</td></tr>';
echo '</table>';

if ($afterCompanies) {
    echo '<h2>Companies</h2><table><tr><th>ID</th><th>Name</th><th>Slug</th><th>db_name</th><th>Status</th></tr>';
    try {
        $rows = $controlPdo->query('SELECT id, company_name, company_slug, db_name, status FROM companies ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            echo '<tr><td>' . (int) $row['id'] . '</td><td>' . htmlspecialchars((string) $row['company_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['company_slug'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['db_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
    } catch (Exception $e) {
        echo '<tr><td colspan="5" class="fail">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    echo '</table>';
}

if ($afterUsers) {
    echo '<h2>Users (control)</h2><table><tr><th>ID</th><th>Email</th><th>Role</th><th>company_id</th></tr>';
    try {
        $rows = $controlPdo->query('SELECT id, email, role, company_id FROM users ORDER BY id ASC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            echo '<tr><td>' . (int) $row['id'] . '</td><td>' . htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['role'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . (int) $row['company_id'] . '</td></tr>';
        }
    } catch (Exception $e) {
        echo '<tr><td colspan="4" class="fail">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    echo '</table>';
    echo '<p>Default admin (only if users table was empty): <code>admin@ultimatetrading.com</code> / <code>admin123</code></p>';
}

echo '<h2>Accessible databases</h2><table><tr><th>Database</th></tr>';
try {
    $dbs = $controlPdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) ?: array();
    foreach ($dbs as $db) {
        $name = (string) $db;
        if ($name === 'information_schema' || $name === 'performance_schema' || $name === 'mysql') {
            continue;
        }
        echo '<tr><td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
} catch (Exception $e) {
    echo '<tr><td class="fail">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</td></tr>';
}
echo '</table>';

echo '<p><strong>Next:</strong> Open <a href="debug_db_connections.php">debug_db_connections.php</a> to verify tenant DB connections. Update each company <code>db_name</code> in phpMyAdmin if names differ from seed values.</p>';
echo '<p class="fail"><strong>Delete</strong> setup_multicompany_db.php and debug scripts when finished.</p>';
echo '</body></html>';
