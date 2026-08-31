<?php
require_once __DIR__ . '/../../core/Database.php';

use Core\Database;

echo "<h1>CRM Module Installation</h1>";

try {
    $pdo = Database::getInstance();
    
    // 1. Customers Table
    $sql = "CREATE TABLE IF NOT EXISTS crm_customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(100),
        phone VARCHAR(50),
        address TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    $pdo->exec($sql);
    echo "<li>Created 'crm_customers' table.</li>";

    // 2. Leads Table
    $sql = "CREATE TABLE IF NOT EXISTS crm_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        customer_id INT NULL, -- If converted from customer or linked
        title VARCHAR(255) NOT NULL,
        contact_name VARCHAR(255),
        email VARCHAR(100),
        phone VARCHAR(50),
        status ENUM('new', 'contacted', 'qualified', 'proposal', 'won', 'lost') DEFAULT 'new',
        source VARCHAR(100),
        assigned_to INT,
        estimated_value DECIMAL(15,2) DEFAULT 0,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    $pdo->exec($sql);
    echo "<li>Created 'crm_leads' table.</li>";

    echo "<h3>CRM Installation Complete!</h3>";
    echo "<p><a href='../../index.php'>Go to Dashboard</a></p>";

} catch (Exception $e) {
    echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
}
