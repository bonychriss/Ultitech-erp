<?php
require_once __DIR__ . '/../includes/functions.php';
global $pdo;

echo "<h1>Live Schema Diagnostic</h1>";

function describeTable($tableName) {
    global $pdo;
    echo "<h2>Table: $tableName</h2>";
    try {
        $stmt = $pdo->query("DESCRIBE $tableName");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            foreach ($column as $value) {
                echo "<td>$value</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } catch (PDOException $e) {
        echo "<p style='color: red;'>Error describing $tableName: " . $e->getMessage() . "</p>";
    }
}

describeTable('products');
describeTable('customers');
describeTable('payment_vouchers');
describeTable('invoices');
