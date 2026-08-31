<?php
// modules/reports/export_stock.php — CSV column order must match headers (same logic as stock report).
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
requireLogin();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="stock_report_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
// UTF-8 BOM so Excel opens special characters correctly
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

$headers = ['Product Code', 'Name', 'Category', 'Location', 'Quantity', 'Unit Cost', 'Total Value'];
fputcsv($output, $headers);

$sql = 'SELECT p.product_code,
        p.name,
        c.name AS category,
        s.location,
        s.quantity,
        COALESCE(p.buying_price, p.unit_price, 0) AS unit_price,
        (s.quantity * COALESCE(p.buying_price, p.unit_price, 0)) AS total_value
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id
        LEFT JOIN categories c ON p.category_id = c.id
        ORDER BY p.name ASC';

try {
    $stmt = $pdo->query($sql);
} catch (Throwable $e) {
    error_log('stock export_stock.php: ' . $e->getMessage());
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        die('Export query failed: ' . htmlspecialchars($e->getMessage()));
    }
    fclose($output);
    exit;
}

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $qty = $row['quantity'];
    if ($qty === null || $qty === '') {
        $qtyOut = '';
    } else {
        $qtyOut = is_numeric($qty) ? 0 + (float) $qty : $qty;
    }
    $unit = isset($row['unit_price']) ? (float) $row['unit_price'] : 0.0;
    $total = isset($row['total_value']) ? (float) $row['total_value'] : 0.0;

    // Explicit column order — do not pass $row to fputcsv (assoc order can differ from headers).
    fputcsv($output, [
        (string) ($row['product_code'] ?? ''),
        (string) ($row['name'] ?? ''),
        (string) ($row['category'] ?? ''),
        (string) ($row['location'] ?? ''),
        $qtyOut,
        $unit,
        $total,
    ]);
}
fclose($output);
