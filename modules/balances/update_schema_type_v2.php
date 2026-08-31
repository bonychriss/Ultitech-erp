<?php
require_once '../../includes/config.php';

try {
    $sql = "ALTER TABLE financial_accounts MODIFY COLUMN type VARCHAR(50) NOT NULL";
    $pdo->exec($sql);
    echo "Successfully updated 'type' column to VARCHAR(50).";
} catch (PDOException $e) {
    echo "Error updating table: " . $e->getMessage();
}
?>
