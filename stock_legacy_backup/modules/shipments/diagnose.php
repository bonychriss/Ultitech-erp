<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Shipment Diagnosis</h1>";

// 1. Basic Hello
echo "<p>PHP is running. Current User: " . get_current_user() . "</p>";
echo "<p>Current Dir: " . __DIR__ . "</p>";

// 2. Path Calculations
$configDb = __DIR__ . '/../../config/database.php';
echo "<p>Config DB Path (Calculated): $configDb</p>";
echo "<p>Config DB Realpath: " . (realpath($configDb) ?: '<span style="color:red">NOT FOUND</span>') . "</p>";

$configFn = __DIR__ . '/../../config/functions.php';
echo "<p>Config Fn Path (Calculated): $configFn</p>";
echo "<p>Config Fn Realpath: " . (realpath($configFn) ?: '<span style="color:red">NOT FOUND</span>') . "</p>";

// 3. Try Including Database (Manual parsing to avoid Fatal Error immediately)
if (file_exists($configDb)) {
    echo "<p>Attempting check of database.php content...</p>";
    $content = file_get_contents($configDb);
    echo "<textarea style='width:100%;height:100px'>" . htmlspecialchars(substr($content, 0, 500)) . "</textarea>";
    
    // Now try include
    try {
        require_once $configDb;
        echo "<p style='color:green'>Database Included Successfully.</p>";
    } catch (Throwable $e) {
        echo "<p style='color:red'>Include Failed: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color:red'>Skipping include, file missing.</p>";
}

// 4. Check Root Functions (via database.php or direct)
if (defined('PDO::ATTR_ERRMODE')) {
    echo "<p>PDO class is available.</p>";
}

if (isset($pdo)) {
    echo "<p style='color:green'>\$pdo variable is set.</p>";
} else {
    echo "<p style='color:orange'>\$pdo variable is NOT set.</p>";
}

if (function_exists('requireLogin')) {
    echo "<p style='color:green'>requireLogin function EXISTS.</p>";
} else {
    echo "<p style='color:red'>requireLogin function MISSING.</p>";
}

echo "<p>End of Diagnosis.</p>";
?>
