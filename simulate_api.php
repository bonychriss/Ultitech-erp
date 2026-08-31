<?php
// Fix paths for includes
chdir(__DIR__ . '/erp/api');

// Mock POST request
$_POST['action'] = 'get_tax_bands';

// Capture output
ob_start();
require 'settings.php';
$output = ob_get_clean();

echo "API Output:\n" . $output;
?>
