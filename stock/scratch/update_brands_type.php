<?php
require_once 'c:/xampp/htdocs/public_html/stock/config/database.php';

try {
    $sql = "ALTER TABLE brands ADD COLUMN brand_type VARCHAR(50) DEFAULT 'spare_part'";
    $pdo->exec($sql);
    echo "Column 'brand_type' added to 'brands' table.\n";
} catch (PDOException $e) {
    echo "Error updating table: " . $e->getMessage() . "\n";
}
?>
