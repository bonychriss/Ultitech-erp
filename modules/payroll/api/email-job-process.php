<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = json_decode($rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireFinanceOrAdmin();
    // Critical: unlock session so email-job-status.php can poll while SMTP runs.
    payrollDeskReleaseSessionLock();

    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(true);
    }

    $jobId = trim((string) ($jsonBody['id'] ?? $jsonBody['jobId'] ?? $_GET['id'] ?? ''));
    if ($jobId === '') {
        payrollDeskJsonExit(422, 'Email job id is required.');
    }

    $job = payrollDeskReadEmailJob($jobId);
    if ($job === null) {
        payrollDeskJsonExit(404, 'Email job not found.');
    }

    $status = (string) ($job['status'] ?? '');
    if ($status === 'done' || $status === 'failed') {
        echo json_encode([
            'success' => true,
            'message' => (string) ($job['message'] ?? 'Done.'),
            'started' => false,
            'data' => [
                'id' => (string) ($job['id'] ?? $jobId),
                'status' => $status,
                'message' => (string) ($job['message'] ?? ''),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($status === 'running') {
        echo json_encode([
            'success' => true,
            'message' => (string) ($job['message'] ?? 'Already running.'),
            'started' => false,
            'data' => [
                'id' => (string) ($job['id'] ?? $jobId),
                'status' => 'running',
                'message' => (string) ($job['message'] ?? ''),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $runId = (int) ($job['runId'] ?? 0);
    $payslipIds = is_array($job['payslipIds'] ?? null) ? $job['payslipIds'] : [];
    if ($runId <= 0 || $payslipIds === []) {
        payrollDeskWriteEmailJob($jobId, array_merge($job, [
            'status' => 'failed',
            'message' => 'Email job is missing recipients.',
            'error' => 'Email job is missing recipients.',
        ]));
        payrollDeskJsonExit(422, 'Email job is missing recipients.');
    }

    // Claim immediately so the UI can leave "queued" before SMTP starts.
    payrollDeskWriteEmailJob($jobId, array_merge($job, [
        'status' => 'running',
        'message' => count($payslipIds) === 1
            ? 'Sending payslip email…'
            : sprintf('Sending %d payslip emails…', count($payslipIds)),
    ]));

    $ack = [
        'success' => true,
        'message' => 'Email worker started.',
        'started' => true,
        'data' => [
            'id' => $jobId,
            'status' => 'running',
            'total' => count($payslipIds),
            'processed' => 0,
            'message' => count($payslipIds) === 1
                ? 'Sending payslip email…'
                : sprintf('Sending %d payslip emails…', count($payslipIds)),
        ],
    ];

    payrollDeskJsonResponseThen(
        true,
        $ack['data'],
        (string) $ack['message'],
        static function () use ($pdo, $runId, $payslipIds, $jobId): void {
            try {
                payrollDeskSendPayslipEmails($pdo, $runId, null, $payslipIds, $jobId);
            } catch (Throwable $e) {
                error_log('payroll email-job-process: ' . $e->getMessage());
                $current = payrollDeskReadEmailJob($jobId) ?: ['id' => $jobId];
                payrollDeskWriteEmailJob($jobId, array_merge($current, [
                    'status' => 'failed',
                    'message' => 'Email send failed.',
                    'error' => $e->getMessage(),
                ]));
            }
        }
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
