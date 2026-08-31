<?php
/**
 * Employee salaries list — React shell.
 */
require_once __DIR__ . '/includes/payroll-lib.php';

payrollDeskRequireFinanceOrAdmin();
payrollDeskRenderReactEntry('Salaries', 'Salaries', 'salaries');
