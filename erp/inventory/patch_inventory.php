<?php
require_once '../../includes/functions.php';
global $pdo;

try {
    // 1. Add remaining_quantity if missing
    // It seems 'current_stock' didn't exist either in the DESCRIBE output above? 
    // Output was: id, product_id, batch_number, quantity, manufacturing_date, expiry_date, status, created_at
    // So current schema has NO tracking of remaining qty unless 'quantity' IS the remaining qty?
    // And NO cost_price.

    // Let's assume 'quantity' is the original quantity. We need 'remaining_quantity'.
    // If strict compliance, we should use 'quantity' as initial and 'remaining_quantity' as current.
    
    $pdo->exec("ALTER TABLE erp_product_batches 
        ADD COLUMN remaining_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER quantity,
        ADD COLUMN cost_price DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER remaining_quantity,
        ADD COLUMN received_date DATE NULL AFTER cost_price
    ");
    
    // Initialize remaining_quantity = quantity for existing records
    $pdo->exec("UPDATE erp_product_batches SET remaining_quantity = quantity WHERE remaining_quantity = 0");
    
    // Initialize received_date = created_at date
    $pdo->exec("UPDATE erp_product_batches SET received_date = DATE(created_at) WHERE received_date IS NULL");

    echo "Table erp_product_batches patched successfully.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Columns already exist.";
    } else {
        die("Error patching table: " . $e->getMessage());
    }
}
