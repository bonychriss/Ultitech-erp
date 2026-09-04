<?php
/**
 * Employee My Payslips init — published slips for the logged-in user.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireAccess();
    echo json_encode(payrollDeskMyPayslipsPayload($pdo), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
