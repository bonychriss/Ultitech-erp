<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireFinanceOrAdmin();
    // Unlock session immediately so this can poll during email-job-process.php.
    payrollDeskReleaseSessionLock();

    $jobId = trim((string) ($_GET['id'] ?? $_GET['jobId'] ?? ''));
    $job = payrollDeskReadEmailJob($jobId);
    if ($job === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Email job not found.', 'status' => 'missing'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'id' => (string) ($job['id'] ?? $jobId),
        'status' => (string) ($job['status'] ?? 'queued'),
        'total' => (int) ($job['total'] ?? 0),
        'processed' => (int) ($job['processed'] ?? 0),
        'sent' => (int) ($job['sent'] ?? 0),
        'failed' => (int) ($job['failed'] ?? 0),
        'skipped' => (int) ($job['skipped'] ?? 0),
        'message' => (string) ($job['message'] ?? ''),
        'error' => (string) ($job['error'] ?? ''),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
