<?php
/**
 * Bridge: root create.php was a misplaced shipment form with broken relative paths.
 * Redirect to the stock shipments create page (or preserve query string).
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirectUrl = 'stock/modules/shipments/create.php' . ($queryString !== '' ? '?' . $queryString : '');

if (!headers_sent()) {
    header('Location: ' . $redirectUrl);
    exit();
}

echo '<script>window.location.href=' . json_encode($redirectUrl) . ';</script>';
echo '<p><a href="' . htmlspecialchars($redirectUrl) . '">Continue to Create Shipment</a></p>';
