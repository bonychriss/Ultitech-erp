<?php
require_once '../config.php';
global $pdo;

echo "--- erp_product_batches ---\n";
$stmt = $pdo->query("DESCRIBE erp_product_batches");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- erp_stock_movements ---\n";
$stmt = $pdo->query("DESCRIBE erp_stock_movements");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
