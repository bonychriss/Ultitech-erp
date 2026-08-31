<?php
require_once 'includes/config.php';

try {
    // Update Sales Order ID 13 to TZS
    $stmt = $pdo->prepare("UPDATE sales_orders SET currency = 'TZS' WHERE id = 13");
    $stmt->execute();
    
    echo "Successfully updated currency for Sales Order ID 13 to TZS.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
