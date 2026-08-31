<?php
/**
 * Redirect to the official Create Voucher page in the employee directory.
 * This file is a bridge to ensure consistent logic and avoid issues with 
 * legacy or skeletal versions in the root.
 */
require_once 'includes/functions.php';
requireLogin();

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirectUrl = 'employee/create-voucher.php' . ($queryString !== '' ? '?' . $queryString : '');

if (!headers_sent()) {
    header('Location: ' . $redirectUrl);
    exit();
} else {
    echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '"></head><body>';
    echo 'Redirecting... <a href="' . htmlspecialchars($redirectUrl) . '">Click here</a></body></html>';
    exit();
}
?>
