<?php
require_once '../../includes/functions.php';
global $pdo;

try {
    echo "Patching erp_delivery_notes...\n";

    // 1. Add order_id
    try {
        $pdo->exec("ALTER TABLE erp_delivery_notes ADD COLUMN order_id INT NULL AFTER id");
        echo "Added order_id.\n";
    } catch (PDOException $e) { echo "order_id likely exists or error: " . $e->getMessage() . "\n"; }

    // 2. Add delivery_date (or should we use 'date'?)
    // My code uses 'delivery_date'. Let's add it or rename 'date'.
    // Safer to add 'delivery_date' and sync it, or just alter code?
    // Altering table is cleaner for the new flow.
    try {
        $pdo->exec("ALTER TABLE erp_delivery_notes ADD COLUMN delivery_date DATE NULL AFTER date");
        echo "Added delivery_date.\n";
        // Sync old date
        $pdo->exec("UPDATE erp_delivery_notes SET delivery_date = date WHERE delivery_date IS NULL");
    } catch (PDOException $e) { echo "delivery_date issue: " . $e->getMessage() . "\n"; }

    // 3. Add vehicle_reg if missing (table has vehicle_number)
    try {
        $pdo->exec("ALTER TABLE erp_delivery_notes ADD COLUMN vehicle_reg VARCHAR(50) NULL AFTER vehicle_number");
         echo "Added vehicle_reg.\n";
    } catch (PDOException $e) { echo "vehicle_reg issue: " . $e->getMessage() . "\n"; }

    // 4. Ensure delivery_number is NOT NULL (it was YES NULL in describe)
    $pdo->exec("ALTER TABLE erp_delivery_notes MODIFY COLUMN delivery_number VARCHAR(50) NOT NULL DEFAULT 'DN-TMP'");

    echo "Patch complete.\n";

} catch (PDOException $e) {
    die("Fatal Error patching table: " . $e->getMessage());
}
