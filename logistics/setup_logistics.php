<?php
require_once '../../includes/functions.php';
global $pdo;

$sql = "
CREATE TABLE IF NOT EXISTS erp_delivery_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_number VARCHAR(50) NOT NULL UNIQUE,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    delivery_date DATE NOT NULL,
    status ENUM('draft', 'scheduled', 'delivered', 'cancelled') DEFAULT 'draft',
    driver_name VARCHAR(100) NULL,
    vehicle_reg VARCHAR(50) NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES erp_sales_orders(id),
    FOREIGN KEY (customer_id) REFERENCES erp_customers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS erp_delivery_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT NOT NULL,
    so_item_id INT NOT NULL, -- Link to Sales Order Item
    product_id INT NOT NULL,
    quantity_delivered DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (delivery_id) REFERENCES erp_delivery_notes(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES erp_products(id)
    -- FOREIGN KEY (so_item_id) REFERENCES erp_sales_order_items(id) -- Optional strict constraint
);
";

try {
    $pdo->exec($sql);
    echo "Logistics tables (Delivery Notes) created successfully.";
} catch (PDOException $e) {
    die("Error creating tables: " . $e->getMessage());
}
