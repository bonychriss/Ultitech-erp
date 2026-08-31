<?php
// stock/modules/products/download_import_report.php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$savedFile = $_SESSION['import_check_file'] ?? '';
if (!$savedFile || !is_file($savedFile)) {
    die("No import file found in session.");
}

// Reuse the validation logic from the main import page
// For a production app, these functions should be in a shared utility file.
// Since we are in a rapid dev phase, I'll include the essential readers/validators.

function norm_key($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/\s+/', '_', $s);
    $s = preg_replace('/[^a-z0-9_]/', '', $s);
    return $s;
}

function get_any(array $row, array $keys, $default = null) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') return $row[$k];
    }
    return $default;
}

function read_rows_from_csv($tmpPath) {
    $rows = [];
    $fh = @fopen($tmpPath, 'r');
    if (!$fh) return [];
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return []; }
    $map = []; foreach ($header as $i => $h) $map[$i] = norm_key($h);
    while (($line = fgetcsv($fh)) !== false) {
        if (count(array_filter($line, function ($x) { return trim((string) $x) !== ''; })) === 0) {
            continue;
        }
        $row = []; foreach ($line as $i => $v) { $k = $map[$i] ?? ('col_' . $i); $row[$k] = $v; }
        $rows[] = $row;
    }
    fclose($fh);
    return ['rows' => $rows, 'header' => $header];
}

function read_rows_from_html_xls($tmpPath) {
    $rows = []; $headerRaw = [];
    $html = @file_get_contents($tmpPath);
    if ($html === false || trim($html) === '') return [];
    $offset = 0; $header = null; $map = [];
    while (preg_match('~<tr\b[^>]*>(.*?)</tr>~is', $html, $m, 0, $offset)) {
        $rowHtml = $m[1]; $offset = $offset + strlen($m[0]);
        if (!preg_match_all('~<(td|th)\b[^>]*>(.*?)</\1>~is', $rowHtml, $cm)) continue;
        $cells = []; foreach ($cm[2] as $cellHtml) { $cells[] = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($cellHtml))), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
        if ($header === null) { if (empty(array_filter($cells))) continue; $header = $cells; $headerRaw = $cells; foreach ($header as $i => $h) $map[$i] = norm_key($h); continue; }
        if (empty(array_filter($cells))) continue;
        $row = []; foreach ($cells as $i => $v) { $k = $map[$i] ?? ('col_' . $i); $row[$k] = $v; }
        $rows[] = $row;
    }
    return ['rows' => $rows, 'header' => $headerRaw];
}

function validate_row_full(PDO $pdo, array $r) {
    $issues = [];
    $name = trim((string)get_any($r, ['name', 'part_name', 'partname'], ''));
    $category = trim((string)get_any($r, ['category'], ''));
    $code = trim((string)get_any($r, ['product_code', 'productcode'], ''));
    
    if ($name !== '' && stripos($name, '__DUMMY__') === 0) return ['skip' => true];
    if ($name === '') $issues[] = 'Missing Part Name';
    if ($category === '') $issues[] = 'Missing Category';
    
    if ($code !== '') {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE product_code = ? LIMIT 1");
        $stmt->execute([$code]);
        if ($stmt->fetchColumn()) $issues[] = "Duplicate Code: $code";
    } else if ($name !== '') {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn()) $issues[] = "Already Exists: $name";
    }
    return $issues;
}

$ext = strtolower(pathinfo($savedFile, PATHINFO_EXTENSION));
$data = ($ext === 'csv') ? read_rows_from_csv($savedFile) : read_rows_from_html_xls($savedFile);
$rows = $data['rows'] ?? [];
$header = $data['header'] ?? [];

// Output as XLS
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="import_report_' . date('Ymd_His') . '.xls"');

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>';
echo '<table border="1">';

// Header
echo '<tr style="background-color: #f1f5f9; font-weight: bold;">';
foreach ($header as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
echo '<th style="background-color: #dbeafe; color: #1e40af;">Validation Status</th>';
echo '<th style="background-color: #fee2e2; color: #991b1b;">Issues / How to Fix</th>';
echo '</tr>';

// Body
foreach ($rows as $r) {
    $issues = validate_row_full($pdo, $r);
    $status = empty($issues) ? 'VALID' : 'ERROR';
    $statusColor = empty($issues) ? '#dcfce7' : '#fee2e2';
    $textColor = empty($issues) ? '#166534' : '#991b1b';
    
    echo '<tr>';
    foreach ($r as $v) echo '<td>' . htmlspecialchars((string)$v) . '</td>';
    echo '<td style="background-color: ' . $statusColor . '; color: ' . $textColor . '; font-weight: bold;">' . $status . '</td>';
    echo '<td style="color: #991b1b;">' . htmlspecialchars(implode(" | ", $issues)) . '</td>';
    echo '</tr>';
}

echo '</table></body></html>';
