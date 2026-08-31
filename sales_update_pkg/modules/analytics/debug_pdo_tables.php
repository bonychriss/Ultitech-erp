<?php
$_GET['company_slug'] = 'roadmaster';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: text/plain');

global $pdo, $control_pdo;

echo "Active DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "Control DB: " . $control_pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

try {
    $st = $pdo->query("SHOW TABLES LIKE 'payees'");
    $res = $st->fetch(PDO::FETCH_NUM);
    echo "Direct SHOW TABLES LIKE 'payees' on Active DB: " . ($res ? "FOUND" : "NOT FOUND") . "\n";
} catch (Exception $e) {
    echo "Direct SHOW TABLES error: " . $e->getMessage() . "\n";
}

$hasPayees = erp_connection_has_table($pdo, 'payees') ? "YES" : "NO";
echo "erp_connection_has_table(Active DB, 'payees'): $hasPayees\n";

$erpDb = erp_data_pdo();
echo "erp_data_pdo resolved database: " . $erpDb->query('SELECT DATABASE()')->fetchColumn() . "\n";
