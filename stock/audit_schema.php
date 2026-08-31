<?php
require_once __DIR__ . '/config/database.php';

$tables_to_check = [
    'users',
    'suppliers',
    'categories',
    'products',
    'product_images',
    'purchases',
    'shipments',
    'shipment_items',
    'shipment_costs',
    'stock',
    'product_batches',
    'stock_movements',
    'product_landed_costs',
    'hs_codes',
    'alerts'
];

$results = [];

foreach ($tables_to_check as $table) {
    try {
        $stmt = $pdo->prepare("DESCRIBE $table");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $col_details = [];
        foreach ($columns as $col) {
            $col_details[$col['Field']] = $col['Type'];
        }
        $results[$table] = ['exists' => true, 'columns' => $col_details];
    } catch (PDOException $e) {
        $results[$table] = ['exists' => false, 'error' => $e->getMessage()];
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>
