<?php
/**
 * Physical alias so /ultimate/accounting/view-journal works even when
 * a file exists here (Apache would otherwise skip the rewrite to ../accounting/).
 */
require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('accounting/view-journal.php');
