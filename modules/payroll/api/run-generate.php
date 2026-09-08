<?php
/**
 * Generate draft payroll run for a month/year.
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

    $month = (int) ($jsonBody['month'] ?? $_POST['month'] ?? 0);
    $year = (int) ($jsonBody['year'] ?? $_POST['year'] ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $result = payrollDeskGenerateRun($pdo, $month, $year, $userId);
    payrollDeskJsonResponse(
        true,
        $result,
        'Payroll generated successfully! Total payout: TSh ' . number_format((float) $result['totalPayout'], 2)
    );
} catch (InvalidArgumentException $e) {
    payrollDeskJsonResponse(false, null, $e->getMessage(), 400);
} catch (Throwable $e) {
    $code = str_contains($e->getMessage(), 'already exists') ? 409 : 500;
    payrollDeskJsonResponse(false, null, $e->getMessage(), $code);
}
