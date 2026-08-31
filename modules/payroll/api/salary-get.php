<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int) ($_GET['id'] ?? 0);

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireFinanceOrAdmin();
    echo json_encode(payrollDeskGetSalaryEmployee($pdo, $id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code($id <= 0 ? 422 : 404);
    echo json_encode(['error' => $e->getMessage()]);
}
