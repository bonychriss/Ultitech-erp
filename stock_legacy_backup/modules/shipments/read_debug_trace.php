<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$file = __DIR__ . '/debug_trace.txt';

echo "<h1>Debug Trace Log</h1>";
echo "<p>Checking file: $file</p>";

if (file_exists($file)) {
    echo "<h2>File Content:</h2>";
    echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc;'>" . htmlspecialchars(file_get_contents($file)) . "</pre>";
    echo "<hr>";
    echo "<p>Last Modified: " . date("F d Y H:i:s", filemtime($file)) . "</p>";
} else {
    echo "<h2 style='color:red;'>File Not Found</h2>";
    echo "<p>The system hasn't written a log file yet. This might mean the script crashes BEFORE it can write, or permission is denied.</p>";
}

echo "<h2>Manual Calculator Test</h2>";
try {
    require_once '../../config/database.php';
    require_once '../../classes/LandedCostCalculator.php';
    
    echo "Database: Connected<br>";
    echo "Class LandedCostCalculator: " . (class_exists('LandedCostCalculator') ? 'Exists' : 'Missing') . "<br>";
    
    $id = $_GET['id'] ?? 1;
    echo "Testing Shipment ID: $id<br>";
    
    $check = $pdo->prepare("SELECT * FROM shipments WHERE id = ?");
    $check->execute([$id]);
    $shp = $check->fetch();
    
    if ($shp) {
        echo "Shipment Found: Invoice " . $shp['invoice_number'] . "<br>";
        
        $calc = new LandedCostCalculator($pdo);
        echo "Attempting Calculate...<br>";
        $res = $calc->calculateTotalCosts($id);
        echo "Result: " . json_encode($res) . "<br>";
        
    } else {
        echo "Shipment Not Found in DB.<br>";
    }

} catch (Throwable $e) {
    echo "<h3 style='color:red'>Crash: " . $e->getMessage() . "</h3>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
