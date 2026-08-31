<?php
// deploy_sales_debug.php
$target = __DIR__ . '/modules/Sales/debug_500.php';
$content = '<?php
// modules/Sales/debug_500.php
ini_set(\'display_errors\', 1);
ini_set(\'display_startup_errors\', 1);
error_reporting(E_ALL);

echo "<h1>Sales Debugger</h1>";

echo "Current Directory: " . __DIR__ . "<br>";

$authPath = __DIR__ . \'/../../core/Auth.php\';
$dbPath = __DIR__ . \'/../../core/Database.php\';

echo "Checking Auth path: $authPath ... ";
if (file_exists($authPath)) {
    echo "<span style=\'color:green\'>Found</span><br>";
    require_once $authPath;
    echo "Auth Class Loaded.<br>";
} else {
    echo "<span style=\'color:red\'>NOT FOUND</span><br>";
}

echo "Checking Database path: $dbPath ... ";
if (file_exists($dbPath)) {
    echo "<span style=\'color:green\'>Found</span><br>";
    require_once $dbPath;
    echo "Database Class Loaded.<br>";
} else {
    echo "<span style=\'color:red\'>NOT FOUND</span><br>";
}

try {
    echo "Testing Database Connection... ";
    $pdo = \Core\Database::getInstance();
    echo "<span style=\'color:green\'>Success</span><br>";
    
    echo "Testing Auth Check... ";
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION[\'erp_user_id\'])) {
        echo "<span style=\'color:orange\'>No Session (Redirect would happen)</span><br>";
    } else {
        echo "<span style=\'color:green\'>Session Active for user ID " . $_SESSION[\'erp_user_id\'] . "</span><br>";
    }
    
    echo "Testing Sales Table Query... ";
    $stmt = $pdo->query("SELECT COUNT(*) FROM sales_orders");
    echo "Count: " . $stmt->fetchColumn() . "<br>";
    
} catch (Exception $e) {
    echo "<br><strong style=\'color:red\'>Exception: " . $e->getMessage() . "</strong><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<h3>If you see this, basic PHP is working.</h3>";
?>';

if (file_put_contents($target, $content)) {
    echo "Created debug tool at: <a href='modules/Sales/debug_500.php'>modules/Sales/debug_500.php</a>";
} else {
    echo "Failed to create debug tool. Check permissions.";
}
?>
