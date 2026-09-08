<?php
/**
 * Fetch payslip for edit form.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireFinanceOrAdmin();
    $id = (int) ($_GET['id'] ?? 0);
    echo json_encode(payrollDeskGetPayslipEditPayload($pdo, $id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $code = str_contains($msg, 'Access denied') || str_contains($msg, 'Cannot edit') ? 403 : 500;
    if (str_contains($msg, 'not found')) {
        $code = 404;
    }
    http_response_code($code);
    echo json_encode(['error' => $msg]);
}
