<?php
/**
 * View payroll run — React shell.
 */
require_once __DIR__ . '/includes/payroll-lib.php';

payrollDeskRequireFinanceOrAdmin();

$runId = (int) ($_GET['id'] ?? 0);
if ($runId <= 0) {
    header('Location: index.php?' . http_build_query(array_merge($_GET ?: [], ['module' => 'payroll'])));
    exit;
}

payrollDeskRenderReactEntry('Payroll Run', 'Payroll Run', 'view-run', [
    '__PAYROLL_RUN_ID__' => $runId,
]);
