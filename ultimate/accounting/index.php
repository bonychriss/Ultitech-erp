<?php
/**
 * Physical dir ultimate/accounting/ was winning over ultimate/accounting.php
 * (Apache 301 → /ultimate/accounting/), so Select Module → Accounting bounced
 * to login. This index bridges into the real accounting entry.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'accounting';
}

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'accounting.php';
