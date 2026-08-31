<?php
require_once '../../includes/functions.php';
global $pdo;

try {
    // Create Departments Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Insert default departments if empty
    $count = $pdo->query("SELECT COUNT(*) FROM erp_departments")->fetchColumn();
    if ($count == 0) {
        $pdo->exec("INSERT INTO erp_departments (name) VALUES ('Sales'), ('Operations'), ('Finance'), ('HR'), ('IT')");
    }

    echo "Departments table created successfully.";
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}
