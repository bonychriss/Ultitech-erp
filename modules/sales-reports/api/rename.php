<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
salesReportsRequireAccess('edit');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['report_name'] ?? ''));
if ($id <= 0 || $name === '') {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}
$ok = salesReportsUpdate($pdo, $id, ['report_name' => $name]);
echo json_encode(['success' => $ok]);
