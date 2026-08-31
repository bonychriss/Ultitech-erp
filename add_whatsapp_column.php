<?php
require_once 'includes/config.php';

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(20) NULL AFTER email");
    echo "Column 'whatsapp_number' added successfully.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Column 'whatsapp_number' already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>
