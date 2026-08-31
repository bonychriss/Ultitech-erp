<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
salesReportsRequireAccess('delete');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}
echo json_encode(['success' => salesReportsDelete($pdo, $id)]);
