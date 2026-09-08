<?php
/**
 * Payslip view-init meta for React fallback (when META is not injected).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireAccess();
    $id = (int) ($_GET['id'] ?? 0);
    echo json_encode(
        payrollDeskGetPayslipViewMeta($pdo, $id),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $code = str_contains($msg, 'Access denied') ? 403 : (str_contains($msg, 'not found') ? 404 : 500);
    http_response_code($code);
    echo json_encode(['error' => $msg]);
}
