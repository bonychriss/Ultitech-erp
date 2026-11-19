<?php
/**
 * Proxy Payment Vouchers desk API through the real app root.
 * Physical /ultimate/admin/... must not boot nested ultimate/includes (broken session path).
 */
require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('admin/dashboard-ui/api/init.php');
