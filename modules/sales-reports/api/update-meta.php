<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
require_once __DIR__ . '/../includes/sales-reports-data.php';
require_once __DIR__ . '/../includes/sales-reports-format.php';
require_once __DIR__ . '/../includes/ui-lib.php';

salesReportsRequireAccess('edit');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$id = (int) ($_POST['report_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid report ID']);
    exit;
}

$fields = [];
foreach (['start_date', 'end_date', 'prepared_by', 'department', 'branch', 'status', 'description'] as $col) {
    if (isset($_POST[$col])) {
        $fields[$col] = $_POST[$col];
    }
}

$ok = salesReportsUpdate($pdo, $id, $fields);
if ($ok && $fields !== []) {
    $report = salesReportsGet($pdo, $id);
    if ($report) {
        $doc = salesReportsGetDocument($pdo, $id);
        $sections = json_decode((string) ($doc['sections_json'] ?? '[]'), true) ?: [];
        $coverKeys = ['start_date', 'end_date', 'prepared_by', 'department'];
        $shouldRefreshCover = (bool) array_intersect(array_keys($fields), $coverKeys);

        if ($shouldRefreshCover && $sections !== []) {
            $changed = false;
            foreach ($sections as &$section) {
                if (($section['key'] ?? '') === 'cover') {
                    $section['content'] = salesReportsBuildDepartmentCoverHtml($report);
                    $changed = true;
                    break;
                }
            }
            unset($section);

            if ($changed) {
                $contentHtml = salesReportsRefreshCoverInHtml((string) ($doc['content_html'] ?? ''), $report);
                if ($contentHtml === (string) ($doc['content_html'] ?? '')) {
                    $contentHtml = salesReportsUiHtmlFromSections($sections);
                }
                salesReportsSaveDocument($pdo, $id, salesReportsJsonEncode($sections), $contentHtml, false);
            }
        }
    }
}

echo json_encode(['success' => $ok]);
