<?php
require_once __DIR__ . '/../includes/sales-reports-export.php';
require_once __DIR__ . '/../includes/sales-reports-data.php';
require_once __DIR__ . '/../includes/report-domain-data.php';

$id = (int) ($_GET['id'] ?? 0);
$format = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));

if ($id <= 0) {
    http_response_code(400);
    die('Invalid report ID');
}

salesReportsRequireAccess('export');
$pdo = salesReportsBootstrap();
$report = salesReportsGet($pdo, $id);
if (!$report) {
    http_response_code(404);
    die('Report not found');
}

$doc = salesReportsGetDocument($pdo, $id);
$sections = [];
if (!empty($doc['sections_json'])) {
    $decoded = json_decode((string) $doc['sections_json'], true);
    if (is_array($decoded)) {
        $sections = $decoded;
    }
}
$contentHtml = salesReportsUiMergeDocumentHtml($doc, $sections);
$contentHtml = reportEngineRefreshLiveBlocks($pdo, $report, $contentHtml);

match ($format) {
    'pdf' => salesReportsExportPdf($report, $contentHtml),
    'word', 'docx' => salesReportsExportWord($report, $contentHtml),
    'excel', 'csv' => salesReportsExportExcel($report, $pdo),
    'print' => (static function () use ($report, $contentHtml) {
        echo salesReportsExportHtml($report, $contentHtml, true);
        echo '<script>window.onload=function(){window.print();}</script>';
    })(),
    default => (static function () {
        http_response_code(400);
        die('Unknown format');
    })(),
};
