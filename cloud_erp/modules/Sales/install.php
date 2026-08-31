<?php
require_once __DIR__ . '/../../core/Database.php';
use Core\Database;
echo "<h1>Sales Installer</h1>";
try {
    $pdo = Database::getInstance();
    
    // Sales Orders
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_orders (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        company_id INT NOT NULL, 
        customer_id INT, 
        order_date DATE, 
        status ENUM('draft', 'confirmed', 'invoiced', 'cancelled') DEFAULT 'draft', 
        total_amount DECIMAL(15,2) DEFAULT 0, 
        user_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");
    
    // Sales Order Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        order_id INT NOT NULL, 
        description VARCHAR(255), 
        quantity DECIMAL(10,2), 
        unit_price DECIMAL(15,2), 
        total DECIMAL(15,2), 
        FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    echo "<li>Sales Tables Created.</li>";
    echo "<h3><a href='../../index.php'>Success! Go to Dashboard</a></h3>";
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }