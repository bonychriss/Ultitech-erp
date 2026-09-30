<?php
/**
 * Physical alias so /ultimate/system-status.php runs the app-root page.
 */
require __DIR__ . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('system-status.php');
