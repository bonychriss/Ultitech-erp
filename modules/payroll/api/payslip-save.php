<?php
/**
 * Save edited payslip values.
 */
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

    $id = (int) ($jsonBody['id'] ?? $jsonBody['payslipId'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
    $result = payrollDeskSavePayslip($pdo, $id, $jsonBody);
    payrollDeskJsonResponse(true, $result, 'Payslip updated successfully.');
} catch (InvalidArgumentException $e) {
    payrollDeskJsonResponse(false, null, $e->getMessage(), 400);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $code = str_contains($msg, 'Access denied') || str_contains($msg, 'Cannot edit') ? 403 : 500;
    if (str_contains($msg, 'not found')) {
        $code = 404;
    }
    payrollDeskJsonResponse(false, null, $msg, $code);
}
