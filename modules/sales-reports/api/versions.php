<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
salesReportsRequireAccess('view');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$id = (int) ($_GET['report_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid report ID']);
    exit;
}
$versions = salesReportsVersions($pdo, $id);
echo json_encode(['success' => true, 'versions' => $versions], JSON_UNESCAPED_UNICODE);
