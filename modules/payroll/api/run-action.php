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

    $runId = (int) ($jsonBody['id'] ?? $jsonBody['runId'] ?? $_POST['id'] ?? 0);
    $action = (string) ($jsonBody['action'] ?? $_POST['action'] ?? '');
    $payslipId = (int) ($jsonBody['payslipId'] ?? $jsonBody['payslip_id'] ?? $_POST['payslip_id'] ?? 0);

    $result = payrollDeskRunAction($pdo, $runId, $action, $payslipId);
    payrollDeskJsonResponse(true, $result, (string) ($result['message'] ?? 'Updated.'));
} catch (Throwable $e) {
    payrollDeskJsonResponse(false, null, $e->getMessage(), 500);
}
