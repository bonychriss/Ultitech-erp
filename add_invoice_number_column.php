<?php
require_once 'includes/functions.php';

try {
    global $pdo;
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM erp_outstanding_invoices LIKE 'invoice_number'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $pdo->exec("ALTER TABLE erp_outstanding_invoices ADD COLUMN invoice_number VARCHAR(50) DEFAULT NULL AFTER entity_name");
        echo "SUCCESS: Column 'invoice_number' added successfully!\n";
    } else {
        echo "INFO: Column 'invoice_number' already exists.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
