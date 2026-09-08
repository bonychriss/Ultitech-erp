<?php
/**
 * Physical alias so /ultimate/view-voucher.php works (on-disk ultimate/ folder).
 */
require __DIR__ . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('view-voucher.php');
