<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
require_once __DIR__ . '/../includes/sales-reports-export.php';
salesReportsRequireAccess('edit');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$id = (int) ($_POST['report_id'] ?? $_GET['report_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid report ID']);
    exit;
}

$sectionsJson = (string) ($_POST['sections'] ?? '[]');
$contentHtml = (string) ($_POST['content_html'] ?? '');
$report = salesReportsGet($pdo, $id);
if ($report) {
    $contentHtml = salesReportsStripEditorPageChrome($contentHtml);
    $contentHtml = salesReportsEnsureCoverHtml($report, $contentHtml);
    $sections = json_decode($sectionsJson, true);
    if (!is_array($sections)) {
        $sections = [];
    }
    $sections = salesReportsApplyCoverToSections($report, $sections);
    $sectionsJson = salesReportsJsonEncode($sections);
}
$createVersion = !isset($_POST['autosave']) || $_POST['autosave'] !== '1';

$result = salesReportsSaveDocument($pdo, $id, $sectionsJson, $contentHtml, $createVersion);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
