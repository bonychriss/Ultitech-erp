<?php
/**
 * Payroll settings init payload.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireFinanceOrAdmin();
    $json = json_encode(
        payrollDeskGetSettingsPayload($pdo),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        throw new RuntimeException('Failed to encode settings payload: ' . json_last_error_msg());
    }
    echo $json;
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $code = str_contains($msg, 'Access denied') ? 403 : 500;
    if (str_contains($msg, 'missing')) {
        $code = 503;
    }
    http_response_code($code);
    echo json_encode(['error' => $msg]);
}
