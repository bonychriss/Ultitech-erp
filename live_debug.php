<?php
// live_debug.php
// This script is designed to catch and display ANY error on the live server.

// 1. Aggressively Enable Error Reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debug Diagnostic Tool</h1>";
echo "Server Time: " . date('Y-m-d H:i:s') . "<br>";
echo "PHP Version: " . phpversion() . "<br><hr>";

// 2. Register Shutdown Function to catch Fatal Errors
function shutdownHandler() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_COMPILE_ERROR)) {
        echo "<div style='background:#fee; color:#c00; padding:15px; border:1px solid #c00; margin:10px 0;'>";
        echo "<strong>FATAL ERROR CAUGHT:</strong><br>";
        echo "Message: " . $error['message'] . "<br>";
        echo "File: " . $error['file'] . "<br>";
        echo "Line: " . $error['line'];
        echo "</div>";
    }
}
register_shutdown_function('shutdownHandler');

// 3. Check DB Error Logs (Secrets stored here)
echo "<h3>2. Checking Error Logs</h3>";
$logFile = __DIR__ . '/storage/logs/db_errors.log';
if (file_exists($logFile)) {
    echo "<span style='color:orange'>Found db_errors.log. Last 2KB:</span><pre style='background:#eee; padding:10px;'>";
    $content = file_get_contents($logFile);
    echo htmlspecialchars(substr($content, -2000));
    echo "</pre>";
} else {
    echo "No db_errors.log found at $logFile<br>";
    if (is_dir(__DIR__ . '/storage/logs')) {
        echo "storage/logs directory exists.<br>";
    } else {
        echo "storage/logs directory MISSING.<br>";
    }
}

// 4. Force Dev Mode & Test Config
echo "<h3>3. Testing Config & Functions</h3>";
define('APP_ENV', 'development'); // Try to force verbose errors
try {
    if (file_exists(__DIR__ . '/includes/config.php')) {
        // Try including config directly
        require_once __DIR__ . '/includes/config.php';
        echo "<span style='color:green'>[OK] Config included. DB Connection seemingly OK.</span><br>";
        
        // Now try functions
        require_once __DIR__ . '/includes/functions.php';
        echo "<span style='color:green'>[OK] Functions included.</span><br>";
    }
} catch (Throwable $e) {
    echo "<span style='color:red'>[EXCEPTION] " . $e->getMessage() . "</span><br>";
}


// 6. Test Specific Functions
echo "<h3>4. Testing Schema Functions</h3>";
$funcs = ['ensureDeliveriesSchema', 'ensureDeliveryNotesSchema', 'ensureOrderVerificationSchema'];
foreach ($funcs as $func) {
    if (function_exists($func)) {
        echo "<span style='color:green'>[OK] Function $func exists.</span><br>";
    } else {
        echo "<span style='color:red'>[FAIL] Function $func is MISSING.</span><br>";
    }
}

echo "<hr><strong>Diagnostic Complete.</strong> If you see this message, the script finished executing.";
?>
