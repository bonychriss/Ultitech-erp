<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
require_once __DIR__ . '/../includes/ui-lib.php';

salesReportsRequireAccess('view');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$config = salesReportsUiBuildConfig($pdo, [
    'search' => $_GET['search'] ?? '',
    'status' => $_GET['status'] ?? '',
]);

echo json_encode(['success' => true, 'data' => $config], JSON_UNESCAPED_UNICODE);
