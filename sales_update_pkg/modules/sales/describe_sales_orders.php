<?php
require_once 'c:/xampp/htdocs/includes/config.php';

try {
    $stmt = $pdo->query("DESCRIBE sales_orders");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns in sales_orders: " . implode(", ", $columns) . "<br>";
} catch (PDOException $e) {
    echo "Error describing table: " . $e->getMessage();
}
?>
