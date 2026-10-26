<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET['company_slug'] = 'ultimate';
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['company_id'] = 1;
$_SESSION['company_slug'] = 'ultimate';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../stock/config/database.php';

global $pdo;
echo 'DB=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
foreach (['stocks_suppliers', 'payees'] as $table) {
    echo "=== {$table} ===\n";
    try {
        $rows = $pdo->query("SELECT * FROM {$table} WHERE LOWER(name) LIKE '%momo%' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        echo 'count=' . count($rows) . PHP_EOL;
        foreach ($rows as $r) {
            echo json_encode([
                'id' => $r['id'] ?? null,
                'name' => $r['name'] ?? null,
                'type' => $r['type'] ?? null,
                'is_active' => $r['is_active'] ?? null,
            ]) . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo 'ERR ' . $e->getMessage() . PHP_EOL;
    }
}
