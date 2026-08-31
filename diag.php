<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Diagnostic Mode</h1>";

$files = [
    'config.php',
    'includes/functions.php',
    'meeting.php',
    'meeting-lobby.php'
];

echo "<ul>";
foreach ($files as $file) {
    if (file_exists($file)) {
        echo "<li>✅ <strong>$file</strong> exists.</li>";
    } else {
        echo "<li>❌ <strong>$file</strong> is MISSING!</li>";
    }
}
echo "</ul>";

require_once 'includes/functions.php';

echo "<h2>Session Info</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Database Connection Test</h2>";
try {
    require_once 'includes/functions.php';
    if (isset($pdo)) {
        echo "✅ PDO Connection object exists (loaded via functions.php).<br>";
        $stmt = $pdo->query("SELECT 1");
        echo "✅ Database query (SELECT 1) successful.<br>";
        
        try {
            $pdo->query("SELECT * FROM active_meetings LIMIT 1");
            echo "✅ 'active_meetings' table exists and is accessible.";
        } catch (Exception $e) {
            echo "❌ 'active_meetings' table is missing or inaccessible: " . $e->getMessage();
        }
    } else {
        echo "❌ PDO object not found after including functions.php.";
    }
} catch (Exception $e) {
    echo "❌ Database Error: " . $e->getMessage();
}
?>
