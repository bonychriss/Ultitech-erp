<?php
/**
 * Alias: /ultimate/modules/balances/transfer.php ? canonical Balances transfer desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$_GET['module'] = 'balances';
require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'balances' . DIRECTORY_SEPARATOR . 'transfer.php';
