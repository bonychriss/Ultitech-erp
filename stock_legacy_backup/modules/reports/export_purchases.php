<?php
// modules/reports/export_purchases.php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="purchase_report_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Date', 'PO Number', 'Supplier', 'Product', 'Status', 'Quantity', 'Total Amount']);

$sql = "SELECT p.created_at, p.purchase_no, s.name as supplier_name, pr.name as product_name, p.status, p.quantity, p.total_amount 
        FROM purchases p 
        JOIN suppliers s ON p.supplier_id = s.id 
        JOIN products pr ON p.product_id = pr.id 
        WHERE DATE(p.created_at) BETWEEN ? AND ?";
$params = [$start_date, $end_date];

if ($status != '') {
    $sql .= " AND p.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, $row);
}
fclose($output);
?>
