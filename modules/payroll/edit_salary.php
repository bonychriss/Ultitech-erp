<?php
/**
 * Edit employee salary — opens as popup on the salaries desk.
 */
require_once __DIR__ . '/includes/payroll-lib.php';

payrollDeskRequireFinanceOrAdmin();

$employeeId = (int) ($_GET['id'] ?? $_GET['edit'] ?? 0);
$params = array_merge($_GET ?: [], ['module' => 'payroll']);
unset($params['id']);
if ($employeeId > 0) {
    $params['edit'] = $employeeId;
} else {
    unset($params['edit']);
}

header('Location: salaries.php?' . http_build_query($params));
exit;
