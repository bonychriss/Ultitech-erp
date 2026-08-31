<?php
require_once '../../includes/config.php';

try {
    // We will change the column to VARCHAR(50) to allow for flexibility and easier validtion in PHP
    // instead of strict ENUM in MySQL which is harder to maintain.
    $sql = "ALTER TABLE financial_accounts MODIFY COLUMN type VARCHAR(50) NOT NULL";
    $pdo->exec($sql);
    echo "Successfully updated 'type' column to VARCHAR(50).";
} catch (PDOException $e) {
    echo "Error updating table: " . $e->getMessage();
}
?>
