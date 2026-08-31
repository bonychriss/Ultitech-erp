<?php
require_once '../../includes/functions.php';
global $pdo;

$sql = "
CREATE TABLE IF NOT EXISTS erp_product_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    batch_number VARCHAR(50) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- Initial Qty
    remaining_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- Current Qty
    cost_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    received_date DATE NOT NULL,
    expiry_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES erp_products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS erp_stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    type ENUM('in', 'out') NOT NULL,
    reference_type VARCHAR(50) NOT NULL, -- e.g. 'grn', 'invoice', 'adjustment'
    reference_id INT NOT NULL,
    created_by INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES erp_products(id) ON DELETE CASCADE
);
";

try {
    $pdo->exec($sql);
    echo "Inventory tables (Batches & Movements) created successfully.";
} catch (PDOException $e) {
    die("Error creating tables: " . $e->getMessage());
}
