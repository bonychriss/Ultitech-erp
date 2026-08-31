<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
salesReportsRequireAccess('restore_version');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$id = (int) ($_POST['report_id'] ?? 0);
$version = (int) ($_POST['version'] ?? 0);
if ($id <= 0 || $version <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}
echo json_encode(salesReportsRestoreVersion($pdo, $id, $version), JSON_UNESCAPED_UNICODE);
