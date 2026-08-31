<?php
// modules/reports/export_stock.php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="stock_report_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Product Code', 'Name', 'Category', 'Location', 'Quantity', 'Unit Price', 'Total Value']);

$sql = "SELECT p.product_code, p.name, c.name as category, s.quantity, p.unit_price, (s.quantity * p.unit_price) as total_value, s.location 
        FROM products p 
        LEFT JOIN stock s ON p.id = s.product_id 
        LEFT JOIN categories c ON p.category_id = c.id 
        ORDER BY p.name ASC";
$stmt = $pdo->query($sql);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, $row);
}
fclose($output);
?>
