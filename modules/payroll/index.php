<?php
/**
 * Payroll dashboard — React shell.
 */
require_once __DIR__ . '/includes/payroll-lib.php';

payrollDeskRequireFinanceOrAdmin();
payrollDeskRenderReactEntry('Payroll', 'Payroll', 'dashboard');
