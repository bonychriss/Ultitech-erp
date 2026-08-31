<?php
// erp/sales/setup_sales_inventory.php
require_once '../../includes/functions.php';

try {
    global $pdo;
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Setting up Sales & Inventory Tables...\n<br>";

    // 1. Sales Orders
    $sqlSO = "CREATE TABLE IF NOT EXISTS erp_sales_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(50) NOT NULL UNIQUE,
        customer_id INT NOT NULL,
        order_date DATE NOT NULL,
        total_amount DECIMAL(15,2) DEFAULT 0.00,
        status ENUM('draft', 'confirmed', 'done', 'cancelled') DEFAULT 'confirmed',
        invoice_status ENUM('to_invoice', 'invoiced', 'no') DEFAULT 'to_invoice',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES erp_customers(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sqlSO);
    echo "Table 'erp_sales_orders' ready.\n<br>";

    // 2. Sales Order Items
    $sqlSOI = "CREATE TABLE IF NOT EXISTS erp_sales_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        description TEXT,
        quantity DECIMAL(10,2) NOT NULL,
        unit_price DECIMAL(15,2) NOT NULL,
        total DECIMAL(15,2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES erp_sales_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sqlSOI);
    echo "Table 'erp_sales_order_items' ready.\n<br>";

    // 3. Delivery Orders
    $sqlDO = "CREATE TABLE IF NOT EXISTS erp_delivery_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_number VARCHAR(50) NOT NULL UNIQUE,
        sales_order_id INT,
        customer_id INT,
        date DATE NOT NULL,
        status ENUM('draft', 'done', 'cancelled') DEFAULT 'draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sales_order_id) REFERENCES erp_sales_orders(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sqlDO);
    echo "Table 'erp_delivery_orders' ready.\n<br>";

    // 4. Stock Moves
    $sqlSM = "CREATE TABLE IF NOT EXISTS erp_stock_moves (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        quantity DECIMAL(10,2) NOT NULL,
        move_type ENUM('in', 'out', 'internal') NOT NULL,
        reference VARCHAR(100),
        origin_document VARCHAR(100),
        status ENUM('draft', 'reserved', 'done', 'cancelled') DEFAULT 'draft',
        date DATE NOT NULL,
        delivery_order_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (delivery_order_id) REFERENCES erp_delivery_orders(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sqlSM);
    echo "Table 'erp_stock_moves' ready.\n<br>";

    echo "Setup Complete!";

} catch (PDOException $e) {
    die("Setup Failed: " . $e->getMessage());
}
