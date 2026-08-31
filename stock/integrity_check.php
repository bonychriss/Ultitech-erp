<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Stock Module Integrity Check</h2>";

// 1. Check Config Files
$files = [
    'config/database.php',
    'config/functions.php',
    'includes/header.php',
    '../includes/functions.php',
    '../config.php'
];

echo "<h3>1. File Check</h3><ul>";
foreach ($files as $f) {
    if (file_exists(__DIR__ . '/' . $f)) {
        echo "<li style='color:green'>Found: $f</li>";
    } else {
        echo "<li style='color:red'>MISSING: $f</li>";
    }
}
echo "</ul>";

// 2. Load DB
echo "<h3>2. Database Connection</h3>";
try {
    require_once 'config/database.php';
    if (isset($pdo)) {
        echo "<span style='color:green'>Connection Successful.</span><br>";
        echo "DB Name: " . DB_NAME . "<br>";
    } else {
        echo "<span style='color:red'>\$pdo variable not set after include.</span><br>";
    }
} catch (Exception $e) {
    echo "<span style='color:red'>Connection Failed: " . $e->getMessage() . "</span><br>";
    exit;
}

// 3. Check Tables
$tables = ['products', 'suppliers', 'stock', 'purchases', 'categories', 'shipments'];
echo "<h3>3. Table Check</h3><ul>";
foreach ($tables as $t) {
    try {
        $stmt = $pdo->query("SELECT 1 FROM $t LIMIT 1");
        echo "<li style='color:green'>Table OK: $t</li>";
        
        // Check columns if products
        if ($t === 'products') {
            $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
            $required = ['reorder_level', 'unit_price', 'currency'];
            echo "<ul>";
            foreach ($required as $r) {
                if (in_array($r, $cols)) {
                    echo "<li style='color:green; font-size:0.9em'>Column OK: $r</li>";
                } else {
                    echo "<li style='color:red; font-size:0.9em'>MISSING Column: $r</li>";
                }
            }
            echo "</ul>";
        }
    } catch (Exception $e) {
        echo "<li style='color:red'>MISSING/ERROR Table: $t (" . $e->getMessage() . ")</li>";
    }
}
echo "</ul>";

// 4. Session Check (Again)
echo "<h3>4. Session Check</h3>";
if (session_status() === PHP_SESSION__ACTIVE) {
    echo "Session Active: " . session_name() . "<br>";
    echo "User ID: " . ($_SESSION['user_id'] ?? 'NULL') . "<br>";
} else {
    echo "Session NOT Active (this script might have started it via config/database.php)<br>";
}

?>
