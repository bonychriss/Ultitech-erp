<?php
require_once __DIR__ . '/../includes/sales-reports-ai.php';
salesReportsRequireAccess('edit');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$id = (int) ($_POST['report_id'] ?? $_GET['report_id'] ?? 0);
$section = trim((string) ($_POST['section'] ?? 'executive_summary'));
$instruction = trim((string) ($_POST['instruction'] ?? ''));

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid report ID']);
    exit;
}

$report = salesReportsGet($pdo, $id);
if (!$report) {
    echo json_encode(['success' => false, 'error' => 'Report not found']);
    exit;
}

$result = salesReportsGenerateAiText($pdo, $report, $section, $instruction);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
