<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = json_decode($rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireFinanceOrAdmin();
    $id = (int) ($jsonBody['id'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
    payrollDeskApproveRun($pdo, $id);
} catch (Throwable $e) {
    payrollDeskJsonResponse(false, null, $e->getMessage(), 500);
}
