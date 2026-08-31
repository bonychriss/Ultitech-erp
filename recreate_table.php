<?php
require_once 'includes/config.php';
try {
    $pdo->exec("DROP TABLE IF EXISTS erp_payroll_settings");
    $pdo->exec("CREATE TABLE erp_payroll_settings (
        id INT(11) NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_percentage tinyint(1) DEFAULT 1,
        type ENUM('allowance', 'deduction') NOT NULL,
        is_active tinyint(1) DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Table recreated successfully";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
