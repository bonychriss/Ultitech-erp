<?php
require_once 'includes/config.php';
try {
    $pdo->exec("ALTER TABLE payroll_tax_bands ADD COLUMN is_active TINYINT(1) DEFAULT 1");
    echo "Column is_active added successfully.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Column already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>
