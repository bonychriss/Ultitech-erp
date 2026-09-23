<?php
/**
 * Physical alias so /ultimate/edit-voucher.php uses the same React edit UI as create.
 */
require __DIR__ . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('employee/edit-voucher.php');
