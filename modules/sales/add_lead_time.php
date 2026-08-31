<?php
// modules/sales/add_lead_time.php
require_once dirname(__DIR__, 2) . '/includes/config.php';

try {
    // Check if column exists in sales_orders
    $stmt = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'lead_time'");
    $exists = $stmt->fetch();

    if (!$exists) {
        $pdo->exec("ALTER TABLE sales_orders ADD COLUMN lead_time VARCHAR(255) DEFAULT NULL AFTER total_amount");
        echo "Successfully added 'lead_time' column to 'sales_orders' table.\n";
    } else {
        echo "'lead_time' column already exists in 'sales_orders' table.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
