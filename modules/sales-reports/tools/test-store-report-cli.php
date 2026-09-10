<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sales-reports-lib.php';
require_once dirname(__DIR__) . '/includes/report-engine.php';
require_once dirname(__DIR__) . '/includes/report-domain-autofill.php';
require_once dirname(__DIR__) . '/includes/sales-reports-export.php';

// Use tenant DB that has stock data
$tenant = connectToTenantDatabase('new_trading_voucher-35313030c7e2');
if (!$tenant instanceof PDO) {
    fwrite(STDERR, "Failed to connect tenant DB\n");
    exit(1);
}
$GLOBALS['pdo'] = $tenant;
$pdo = $tenant;
$_SESSION['company_name'] = 'Ultimate Trading';
$_SESSION['company_id'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Store Report Test';

$filters = [
    'start_date' => '2026-03-01',
    'end_date' => '2026-03-31',
];

echo "=== KPI snapshot (Mar 2026) ===\n";
$snap = reportDomainStoreSnapshot($pdo, $filters);
$kpis = $snap['kpis'] ?? [];
foreach ([
    'total_products', 'total_units', 'inventory_value', 'normal_stock_count', 'low_stock_count',
    'out_of_stock_count', 'reorder_required_count', 'movement_count', 'movement_in_qty',
    'movement_out_qty', 'purchase_count', 'purchase_value', 'pending_po_count',
    'pending_delivery_count', 'delayed_delivery_count', 'supplier_count', 'category_count',
] as $k) {
    echo str_pad($k, 28) . ': ' . ($kpis[$k] ?? 'null') . "\n";
}

echo "\nstock_status rows: " . count($snap['stock_status'] ?? []) . "\n";
echo "suppliers: " . count($snap['purchases_by_supplier'] ?? []) . "\n";
echo "pending deliveries: " . count($snap['pending_deliveries'] ?? []) . "\n";
echo "period comparison: " . count($snap['period_comparison'] ?? []) . "\n";
echo "exceptions: " . count($snap['exceptions'] ?? []) . "\n";

echo "\n=== Create + autofill report ===\n";
$id = salesReportsCreate($pdo, [
    'report_domain' => 'procurement',
    'report_name' => 'March 2026 Store Report',
    'report_type' => 'management',
    'template_key' => 'standard',
    'start_date' => '2026-03-01',
    'end_date' => '2026-03-31',
    'prepared_by' => 'Store Report Test',
    'department' => 'Store / Inventory',
    'status' => 'draft',
    'filters' => [],
]);
echo "Created ID: {$id}\n";
$row = $pdo->query('SELECT id, company_id, deleted_at FROM sales_reports WHERE id=' . (int) $id)->fetch(PDO::FETCH_ASSOC);
echo 'Raw row: ' . json_encode($row) . "\n";
echo 'salesReportsCompanyId=' . salesReportsCompanyId() . "\n";
$check = salesReportsGet($pdo, $id);
echo 'Get: ' . ($check ? 'OK' : 'MISSING') . "\n";

$result = reportEngineApplyAutofill($pdo, $id, true);
echo 'Autofill success: ' . (!empty($result['success']) ? 'yes' : 'no') . "\n";
echo 'Message: ' . ($result['message'] ?? $result['error'] ?? '') . "\n";

$html = (string) ($result['content_html'] ?? '');
echo 'HTML length: ' . strlen($html) . "\n";
echo 'Has cover: ' . (str_contains($html, 'sr-cover-page') ? 'yes' : 'no') . "\n";
echo 'Has STORE REPORT: ' . (str_contains($html, 'STORE') ? 'yes' : 'no') . "\n";
echo 'Sections filled: ' . count($result['sections'] ?? []) . "\n";

$checks = [
    'Executive Summary' => 'Executive Summary',
    'Purchase' => 'Purchase',
    'Stock Status' => 'Stock Status',
    'Supplier' => 'Supplier',
    'Period Comparison' => 'Period Comparison',
    'Recommendations' => 'Recommendations',
];
foreach ($checks as $label => $needle) {
    echo "Contains {$label}: " . (stripos($html, $needle) !== false ? 'yes' : 'no') . "\n";
}

$outDir = dirname(__DIR__, 3) . '/reports';
$htmlPath = $outDir . '/Store_Report_March_2026_preview.html';
$exportHtml = salesReportsExportHtml([
    'report_domain' => 'procurement',
    'report_name' => 'March 2026 Store Report',
    'start_date' => '2026-03-01',
    'end_date' => '2026-03-31',
], $html);
file_put_contents($htmlPath, $exportHtml);
echo "Wrote preview: {$htmlPath}\n";

// Try PDF
$autoloadCandidates = [
    dirname(__DIR__, 3) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/meeting/vendor/autoload.php',
];
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
if (class_exists('\\Mpdf\\Mpdf')) {
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 12]);
    $mpdf->WriteHTML($exportHtml);
    $pdfPath = $outDir . '/Store_Report_March_2026.pdf';
    $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);
    echo "Wrote PDF: {$pdfPath} (" . filesize($pdfPath) . " bytes)\n";
} else {
    echo "mPDF not available for CLI PDF write\n";
}

echo "DONE\n";
