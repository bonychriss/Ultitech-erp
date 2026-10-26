<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET['company_slug'] = 'ultimate';
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['company_id'] = 1;
$_SESSION['company_slug'] = 'ultimate';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../stock/config/database.php';
global $pdo;
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "DB={$db}\n";
foreach (['suppliers', 'stocks_suppliers', 'payees'] as $t) {
    $has = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    echo "{$t}: " . ($has ? 'yes' : 'no') . "\n";
    if (!$has) continue;
    $rows = $pdo->query("SELECT id, name FROM {$t} WHERE LOWER(name) LIKE '%momo%' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo "  momo=" . json_encode($rows) . "\n";
    $recent = $pdo->query("SELECT id, name FROM {$t} ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "  recent=" . json_encode($recent) . "\n";
}
