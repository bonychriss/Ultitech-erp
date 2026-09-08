<?php
/**
 * Legacy fallback for my-payslips desk when React dist is missing.
 */
require_once __DIR__ . '/../includes/payroll-lib.php';

payrollDeskRequireAccess();
payrollDeskRenderReactEntry('My Payslips', 'My Payslips', 'my-payslips');
