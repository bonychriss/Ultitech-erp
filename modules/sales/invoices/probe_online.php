<?php
/**
 * Online Probe for create.php (Bypasses debug_* filename restriction in config.php)
 * Access via: https://ultitech.io/modules/sales/invoices/probe_online.php?company_slug=ultimate&mock=1
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inject mock session
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['company_id'] = 1;
$_SESSION['company_slug'] = 'ultimate';

echo "=== Running probe_online.php ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "URI: " . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
echo "Company Slug: " . ($_GET['company_slug'] ?? '') . "\n";

try {
    require_once __DIR__ . '/create.php';
    echo "\n=== Success: create.php loaded completely without fatal errors ===\n";
} catch (Throwable $e) {
    echo "\n=== FATAL ERROR inside create.php ===\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

$output = ob_get_clean();
echo $output;
