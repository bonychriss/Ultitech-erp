<?php
/**
 * My Payslips — employee React shell.
 */
require_once __DIR__ . '/includes/payroll-lib.php';

payrollDeskRequireAccess();
payrollDeskRenderReactEntry('My Payslips', 'My Payslips', 'my-payslips');
