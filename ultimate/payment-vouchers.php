<?php
/**
 * Physical alias so /ultimate/payment-vouchers works (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'voucher';
}
require dirname(__DIR__) . '/admin/all-vouchers.php';
