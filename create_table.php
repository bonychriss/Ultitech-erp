<?php
require_once 'includes/config.php';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_payroll_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_percentage BOOLEAN DEFAULT TRUE,
        type ENUM('allowance', 'deduction') NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "Table created successfully";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
