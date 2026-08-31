<?php
require_once 'config/database.php';
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active'");
    echo "Column 'status' added successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
