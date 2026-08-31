<?php
/**
 * Payroll module JSON API.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = json_decode($rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

$action = strtolower(trim((string) (
    $_GET['action']
    ?? $_POST['action']
    ?? ($jsonBody['action'] ?? '')
)));
if ($action === '') {
    $action = 'dashboard';
}

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireFinanceOrAdmin();

    switch ($action) {
        case 'dashboard':
            payrollDeskJsonResponse(true, payrollDeskDashboardData($pdo));
            break;

        case 'approve':
            $id = (int) ($jsonBody['id'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
            payrollDeskApproveRun($pdo, $id);
            break;

        case 'delete':
            $id = (int) ($jsonBody['id'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
            payrollDeskDeleteRun($pdo, $id);
            break;

        default:
            payrollDeskJsonResponse(false, null, 'Unknown action.', 400);
    }
} catch (Throwable $e) {
    payrollDeskJsonResponse(false, null, $e->getMessage(), 500);
}
