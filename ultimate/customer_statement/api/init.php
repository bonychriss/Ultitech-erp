<?php
/**
 * Physical alias so /ultimate/customer_statement/api/... works (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}

require dirname(__DIR__, 3) . '/customer_statement/api/init.php';
