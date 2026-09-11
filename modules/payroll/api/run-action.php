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
    $payslipIdsRaw = $jsonBody['payslipIds'] ?? $jsonBody['payslip_ids'] ?? null;
    $payslipIds = null;
    if (array_key_exists('payslipIds', $jsonBody) || array_key_exists('payslip_ids', $jsonBody)) {
        $payslipIds = [];
        if (is_array($payslipIdsRaw)) {
            $payslipIds = array_values(array_unique(array_filter(
                array_map('intval', $payslipIdsRaw),
                static fn (int $id): bool => $id > 0
            )));
        }
    }

    $result = payrollDeskRunAction($pdo, $runId, $action, $payslipId, $payslipIds);
    $message = (string) ($result['message'] ?? 'Updated.');

    $jobId = trim((string) ($result['emailJobId'] ?? ''));
    if ($jobId !== '') {
        // Unlock session, kick worker via short HTTP call, return JSON immediately.
        payrollDeskSpawnEmailJobWorker($jobId);
        // Browser also starts the worker as a backup (see frontend startEmailJob).
    }

    payrollDeskJsonResponse(true, $result, $message);
} catch (Throwable $e) {
    payrollDeskJsonResponse(false, null, $e->getMessage(), 500);
}
