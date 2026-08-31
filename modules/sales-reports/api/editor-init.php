<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
require_once __DIR__ . '/../includes/ui-lib.php';

salesReportsRequireAccess('view');
header('Content-Type: application/json; charset=utf-8');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid report ID']);
    exit;
}

$pdo = salesReportsBootstrap();
$config = salesReportsUiBuildEditorConfig($pdo, $id);
if (!$config) {
    echo json_encode(['success' => false, 'error' => 'Report not found']);
    exit;
}

echo json_encode(['success' => true, 'data' => $config], JSON_UNESCAPED_UNICODE);
