<?php
require_once __DIR__ . '/../includes/sales-reports-data.php';
require_once __DIR__ . '/../includes/report-domain-data.php';
salesReportsRequireAccess('view');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$id = (int) ($_GET['report_id'] ?? 0);
$source = trim((string) ($_GET['source'] ?? ''));
$mode = trim((string) ($_GET['mode'] ?? 'live'));

if ($id <= 0 || $source === '') {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$report = salesReportsGet($pdo, $id);
if (!$report) {
    echo json_encode(['success' => false, 'error' => 'Report not found']);
    exit;
}

$domain = reportEngineReportDomain($report);
$filters = reportEngineFiltersFromReport($report);
$data = reportEngineFetchErpData($pdo, $domain, $source, $filters);

$modeAttr = $mode === 'snapshot' ? 'snapshot' : 'live';
$html = '<div class="sr-erp-block" data-erp-source="' . htmlspecialchars($source, ENT_QUOTES) . '" data-erp-mode="' . $modeAttr . '" contenteditable="' . ($modeAttr === 'snapshot' ? 'true' : 'false') . '">' . ($data['html'] ?? '') . '</div>';

echo json_encode([
    'success' => true,
    'html' => $html,
    'data' => $data,
    'mode' => $modeAttr,
], JSON_UNESCAPED_UNICODE);
