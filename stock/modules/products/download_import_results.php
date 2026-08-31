<?php
// stock/modules/products/download_import_results.php
require_once __DIR__ . '/../../../includes/functions.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
requireLogin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$map = $_SESSION['bulk_import_last_map'] ?? [];
if (!is_array($map)) $map = [];

$filename = 'bulk_import_results_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fputcsv($out, ['line', 'name', 'category', 'generated_product_code', 'image_key']);
foreach ($map as $row) {
    fputcsv($out, [
        $row['line'] ?? '',
        $row['name'] ?? '',
        $row['category'] ?? '',
        $row['generated_product_code'] ?? '',
        $row['image_key'] ?? '',
    ]);
}
fclose($out);
exit;

