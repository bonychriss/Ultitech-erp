<?php
require_once '../../includes/functions.php';
global $pdo;

$sql = "
CREATE TABLE IF NOT EXISTS erp_sales_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    quote_id INT NULL,
    customer_id INT NOT NULL,
    order_date DATE NOT NULL,
    delivery_date DATE NULL,
    subtotal DECIMAL(15,2) DEFAULT 0.00,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    total_amount DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('draft', 'confirmed', 'cancelled') DEFAULT 'draft',
    delivery_status ENUM('pending', 'partial', 'delivered') DEFAULT 'pending',
    invoice_status ENUM('not_invoiced', 'partial', 'invoiced') DEFAULT 'not_invoiced',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES erp_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS erp_sales_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    description TEXT,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    total DECIMAL(15,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES erp_sales_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES erp_products(id)
);
";

try {
    $pdo->exec($sql);
    echo "Sales Order tables created successfully.";
} catch (PDOException $e) {
    die("Error creating tables: " . $e->getMessage());
}
