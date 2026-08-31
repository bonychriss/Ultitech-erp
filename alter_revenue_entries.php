<?php
require 'includes/config.php';
try {
    $pdo->exec("ALTER TABLE revenue_entries ADD COLUMN account_id INT NULL AFTER attachment");
    echo "Added account_id to revenue_entries\n";
} catch (PDOException $e) {
    echo "Error or column exists: " . $e->getMessage() . "\n";
}
