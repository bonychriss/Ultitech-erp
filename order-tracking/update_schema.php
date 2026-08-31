<?php
require_once '../includes/functions.php';

try {
    // Add 'shipper' column if not exists
    $cols = $pdo->query("SHOW COLUMNS FROM order_tracking LIKE 'shipper'")->fetchAll();
    if (count($cols) == 0) {
        $pdo->exec("ALTER TABLE order_tracking ADD COLUMN shipper VARCHAR(100) AFTER shipment_date");
        echo "Added 'shipper' column.<br>";
    } else {
        echo "'shipper' column already exists.<br>";
    }

    // Add 'ecc' column if not exists
    $cols = $pdo->query("SHOW COLUMNS FROM order_tracking LIKE 'ecc'")->fetchAll();
    if (count($cols) == 0) {
        // ECC: Estimated Cost of Clearance. DECIMAL or VARCHAR? Using DECIMAL(15,2) for money.
        $pdo->exec("ALTER TABLE order_tracking ADD COLUMN ecc DECIMAL(15,2) DEFAULT 0.00 AFTER shipper");
        echo "Added 'ecc' column.<br>";
    } else {
        echo "'ecc' column already exists.<br>";
    }

    echo "Schema update complete.";

} catch (PDOException $e) {
    die("Error updating schema: " . $e->getMessage());
}
?>