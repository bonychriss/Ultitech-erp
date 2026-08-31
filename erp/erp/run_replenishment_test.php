<?php
require_once 'includes/functions.php';
require_once 'includes/ReplenishmentService.php';

global $pdo;

// 1. Ensure PO Tables Exist
try {
    $pdo->query("SELECT 1 FROM erp_purchase_orders LIMIT 1");
} catch (Exception $e) {
    // Create Tables
    $sql = "CREATE TABLE IF NOT EXISTS erp_purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_number VARCHAR(50) NOT NULL UNIQUE,
        supplier_id INT NOT NULL,
        order_date DATE,
        expected_date DATE,
        total_amount DECIMAL(10,2) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'draft',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES erp_suppliers(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sql);
    echo "Created erp_purchase_orders table.<br>";
}

try {
    $pdo->query("SELECT 1 FROM erp_purchase_order_items LIMIT 1");
} catch (Exception $e) {
    // Create Tables
    $sql = "CREATE TABLE IF NOT EXISTS erp_purchase_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity DECIMAL(10,2),
        unit_cost DECIMAL(10,2),
        total_cost DECIMAL(10,2),
        FOREIGN KEY (po_id) REFERENCES erp_purchase_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES erp_products(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sql);
    echo "Created erp_purchase_order_items table.<br>";
}

// 2. Run Replenishment
echo "<h3>Running Replenishment Service...</h3>";
$service = new ReplenishmentService($pdo);
$result = $service->run();

echo "<pre>";
print_r($result);
echo "</pre>";

if (!empty($result['po_ids'])) {
    echo "Generated PO IDs: " . implode(', ', $result['po_ids']);
    // Link to view them if view-po.php exists
}
