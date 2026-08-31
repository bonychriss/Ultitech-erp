<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>500 Error Diagnostic</h1>";
echo "Attempting to include includes/functions.php...<br>";

try {
    require_once 'includes/functions.php';
    echo "SUCCESS: functions.php included.<br>";
    
    echo "Current DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'NOT DEFINED') . "<br>";
    echo "Current DB_USER: " . (defined('DB_USER') ? DB_USER : 'NOT DEFINED') . "<br>";
    
    // Test a basic function
    if (function_exists('app_url')) {
        echo "SUCCESS: app_url() exists. URL: " . app_url() . "<br>";
    } else {
        echo "FAILED: app_url() does not exist.<br>";
    }
    
} catch (Throwable $t) {
    echo "<h2>CATCHED ERROR:</h2>";
    echo "Message: " . $t->getMessage() . "<br>";
    echo "File: " . $t->getFile() . "<br>";
    echo "Line: " . $t->getLine() . "<br>";
}

echo "<br><hr>End of Diagnostic.";
?>
