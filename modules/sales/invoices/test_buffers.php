<?php
/**
 * Output Buffering Tester
 * Access via: https://ultitech.io/modules/sales/invoices/test_buffers.php
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "Initial ob_level: " . ob_get_level() . "\n";
print_r(ob_get_status(true));

echo "\nStarting ob_start...\n";
ob_start();
echo "Inside buffer\n";
echo "Current ob_level: " . ob_get_level() . "\n";
print_r(ob_get_status(true));

echo "\nCalling ob_end_clean...\n";
ob_end_clean();
echo "After ob_end_clean, current ob_level: " . ob_get_level() . "\n";
print_r(ob_get_status(true));
