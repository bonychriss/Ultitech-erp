<?php
/**
 * Physical alias so /ultimate/employee/pending-voucher-tasks.php works
 * with correct CWD for relative includes (Laravel/ultimate tenant stubs).
 */
require_once dirname(__DIR__) . '/_tenant_require.php';
ultimate_tenant_require('employee/pending-voucher-tasks.php');
