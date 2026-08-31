<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
require_once __DIR__ . '/../includes/ui-lib.php';
require_once __DIR__ . '/../includes/sales-reports-autofill.php';

salesReportsRequireAccess('edit');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$pdo = salesReportsBootstrap();
salesReportsEnsureAutofilledColumn($pdo);

$id = (int) ($_POST['report_id'] ?? $_GET['report_id'] ?? 0);
$force = !empty($_POST['force']) || !empty($_GET['force']);

if ($id <= 0) {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (int) ($body['report_id'] ?? 0);
    $force = $force || !empty($body['force']);
}

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid report ID']);
    exit;
}

$result = salesReportsApplyAutofill($pdo, $id, $force);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
