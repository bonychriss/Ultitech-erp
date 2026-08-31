<?php
/**
 * Unmocked Execution Inspector
 * Access via: https://ultitech.io/modules/sales/invoices/check_create.php?company_slug=ultimate
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

ob_start();

echo "=== Running check_create.php ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";

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
