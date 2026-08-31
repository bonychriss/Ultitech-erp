<?php
// stock/modules/products/download_import_template.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/paths.php';
requireLogin();

// Template download.
// Default: .xls (HTML table) so Excel shows spacing + header color.
// Optional: ?format=csv for plain CSV.
// Use ?type=spare_part|truck|general|products to generate the right columns and examples.
$typeRaw = strtolower(trim((string) ($_GET['type'] ?? 'spare_part')));
$isUltimateRequest = (
    (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
    || in_array($typeRaw, ['general', 'products', 'product'], true)
);
if ($typeRaw === 'truck' || $typeRaw === 'vehicle') {
    $type = 'truck';
} elseif ($isUltimateRequest || $typeRaw === 'general' || $typeRaw === 'products' || $typeRaw === 'product') {
    $type = 'general';
} else {
    $type = 'spare_part';
}
$format = strtolower(trim((string) ($_GET['format'] ?? 'xls')));
$format = ($format === 'csv') ? 'csv' : 'xls';

// Prefer logged-in company name for the download filename.
$companyLabel = '';
if (!empty($_SESSION['company_name'])) {
    $companyLabel = (string) $_SESSION['company_name'];
} elseif (!empty($_SESSION['company_slug'])) {
    $companyLabel = (string) $_SESSION['company_slug'];
}
if ($companyLabel === '' && isset($pdo) && function_exists('getCompanySettings')) {
    try {
        $settings = getCompanySettings($pdo);
        $companyLabel = (string) ($settings['company_name'] ?? $settings['name'] ?? '');
    } catch (Throwable $e) {
        $companyLabel = '';
    }
}
if ($companyLabel === '' && !empty($_GET['company_slug'])) {
    $companyLabel = (string) $_GET['company_slug'];
}
$companySlugSafe = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $companyLabel), '-'));
if ($companySlugSafe === '') {
    $companySlugSafe = 'company';
}

if ($type === 'truck') {
    $kind = 'trucks';
} elseif ($type === 'general') {
    $kind = 'products';
} else {
    $kind = 'spare-parts';
}
$filenameBase = $companySlugSafe . '-' . $kind . '-import-template-' . date('Y-m-d');
$ext = ($format === 'csv') ? '.csv' : '.xls';
$filename = $filenameBase . $ext;

$contentType = ($format === 'csv')
    ? 'text/csv; charset=utf-8'
    : 'application/vnd.ms-excel; charset=utf-8';
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

$headers = [];
if ($type === 'truck') {
    // Truck template (technical fields)
    $headers = [
        'Product Code', 'Truck name', 'OEM/Part number', 'Description', 'Part condition', 'Brand',
        'Category', 'Supplier',
        'Buying price', 'Selling price', 'Wholesale price',
        'Reorder level', 'Current stock', 'Location', 'Unit of measure',
        'VIN', 'Engine number', 'Chassis number', 'Model year', 'Mileage', 'Color',
        'Notes'
    ];
} elseif ($type === 'general') {
    // Ultimate / general products (no spare-parts or truck fields)
    $headers = [
        'Product Code',
        'Product name',
        'Description',
        'Brand',
        'Category',
        'Supplier',
        'Buying price',
        'Selling price',
        'Wholesale price',
        'Reorder level',
        'Current stock',
        'Location',
        'Unit of measure',
        'Condition',
    ];
} else {
    // Spare parts template
    $headers = [
        'Product Code',
        'Part name',
        'OEM/Part number',
        'Description',
        'Part condition',
        'Brand',
        'Compactibility truck model',
        'Category',
        'Supplier',
        'Buying price',
        'Selling price',
        'wholesale price',
        'Reorder level',
        'Current stock',
        'Location',
        'Unit of measure',
    ];
}

// Build example rows
$exampleRows = [];
if ($type === 'truck') {
    $exampleRows[] = [
        '', 'HOWO Truck - White', 'OEM-TRK-001', 'Example truck description', 'new', 'HOWO',
        'Trucks', 'Default Supplier',
        '0', '0', '',
        '0', '1', 'Yard', 'unit',
        'VIN123', 'ENG456', 'CHS789', '2022', '150000', 'White',
        'Truck example'
    ];
} elseif ($type === 'general') {
    $exampleRows[] = [
        '', '__DUMMY__ DELETE THIS ROW 1', 'Example description (remove this row)', 'Acme',
        'General', 'Default Supplier',
        '10000', '15000', '0',
        '5', '50', 'Shelf A1', 'pcs', 'new'
    ];
    $exampleRows[] = [
        '', '__DUMMY__ DELETE THIS ROW 2', 'Example description (remove this row)', 'Acme',
        'General', 'Default Supplier',
        '8000', '12000', '0',
        '3', '25', 'Shelf B2', 'pcs', 'used'
    ];
} else {
    // Two dummy guide rows (ignored by importer). User may delete them.
    $exampleRows[] = [
        '', '__DUMMY__ DELETE THIS ROW 1', 'OEM-12345', 'Example description (remove this row)', 'new', 'Bosch', 'Volvo FH16',
        'Braking System', 'Default Supplier',
        '25000', '35000', '0',
        '10', '100', 'Shelf A1', 'pcs'
    ];
    $exampleRows[] = [
        '', '__DUMMY__ DELETE THIS ROW 2', 'OEM-67890', 'Example description (remove this row)', 'used', 'Mann Filter', 'Scania R420',
        'Air Intake', 'Default Supplier',
        '15000', '22000', '0',
        '5', '50', 'Shelf B2', 'pcs'
    ];
}

if ($format === 'csv') {
    fputcsv($out, $headers);
    foreach ($exampleRows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

// XLS (HTML) output with fixed column widths + spaced headers (Excel will open this nicely)
$colWidths = [];
if ($type === 'truck') {
    $colWidths = [120, 220, 140, 260, 130, 120, 160, 180, 120, 120, 120, 120, 120, 150, 140, 140, 140, 120, 120, 120, 120, 220];
} elseif ($type === 'general') {
    $colWidths = [120, 220, 260, 120, 160, 180, 120, 120, 130, 120, 120, 150, 140, 120];
} else {
    $colWidths = [120, 200, 160, 260, 140, 120, 230, 180, 180, 120, 120, 130, 120, 120, 150, 140];
}

echo "<html><head><meta charset='utf-8'>";
echo "<style>
    body{font-family:Calibri,Arial,sans-serif;}
    table{border-collapse:collapse;}
    th,td{border:1px solid #d1d5db; padding:8px 10px; font-size:11pt; white-space:nowrap; vertical-align:middle;}
    th{background:#DCFCE7; font-weight:700; text-align:left;}
</style></head><body>";

echo "<table><colgroup>";
foreach ($colWidths as $w) echo "<col style='width:" . (int)$w . "px'>";
echo "</colgroup><thead><tr>";
foreach ($headers as $h) echo "<th style='background:#DCFCE7; font-weight:700; text-align:left;'>" . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . "</th>";
echo "</tr></thead><tbody>";

foreach ($exampleRows as $row) {
    echo "<tr>";
    foreach ($row as $cell) echo "<td>" . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . "</td>";
    echo "</tr>";
}

for ($i = 0; $i < 10000; $i++) {
    echo "<tr>";
    for ($c = 0; $c < count($headers); $c++) echo "<td></td>";
    echo "</tr>";
}

echo "</tbody></table></body></html>";
fclose($out);
exit;

