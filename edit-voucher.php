<?php
/**
 * Bridge to the official Edit Voucher page (React UI in employee/).
 * Keeps /edit-voucher.php and /ultimate/edit-voucher.php consistent with create-voucher.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirectUrl = 'employee/edit-voucher.php' . ($queryString !== '' ? '?' . $queryString : '');

if (!headers_sent()) {
    header('Location: ' . $redirectUrl, true, 302);
    exit();
}

echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url='
    . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8')
    . '"></head><body>';
echo 'Redirecting… <a href="' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '">Click here</a></body></html>';
exit();
