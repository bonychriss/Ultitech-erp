<?php
/**
 * Alias: /ultimate/modules/balances/accounts.php ? canonical Balances accounts desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$_GET['module'] = 'balances';
require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'balances' . DIRECTORY_SEPARATOR . 'accounts.php';
