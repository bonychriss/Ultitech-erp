<?php
require_once 'config/database.php';

echo "<!DOCTYPE html><html><head><title>System Audit</title><style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f6; padding: 30px; }
    .container { max-width: 1000px; margin: auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
    h2 { color: #34495e; margin-top: 30px; border-left: 5px solid #3498db; padding-left: 10px; }
    .status { padding: 5px 10px; border-radius: 4px; font-size: 0.85em; font-weight: bold; }
    .status-ok { background: #d4edda; color: #155724; }
    .status-fail { background: #f8d7da; color: #721c24; }
    .status-warn { background: #fff3cd; color: #856404; }
    table { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 0.9em; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background: #f8f9fa; color: #555; }
    pre { background: #fdf6e3; padding: 10px; border: 1px solid #eadbb2; overflow-x: auto; font-size: 0.8em; }
    .btn { display: inline-block; padding: 8px 15px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; margin-top: 10px; font-size: 0.9em; }
</style></head><body><div class='container'>";

echo "<h1>Live Site Database Audit</h1>";

function checkTable($pdo, $name) {
    try {
        $stmt = $pdo->query("SELECT 1 FROM `$name` LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// 1. TABLE INVENTORY
echo "<h2>1. Tables Inventory</h2>";
$requiredTables = [
    'users', 'products', 'stock', 'stock_movements', 'purchases', 'purchase_items', 
    'suppliers', 'shipments', 'shipment_items', 'product_batches', 'product_landed_costs'
];
$conflictingTables = [
    'shipment_costs' => 'Expected by migration A',
    'shipment_cost_details' => 'Expected by migration B'
];

echo "<table><tr><th>Table Name</th><th>Status</th><th>Notes</th></tr>";
foreach ($requiredTables as $t) {
    $exists = checkTable($pdo, $t);
    $status = $exists ? "<span class='status status-ok'>EXISTS</span>" : "<span class='status status-fail'>MISSING</span>";
    echo "<tr><td>$t</td><td>$status</td><td>-</td></tr>";
}
foreach ($conflictingTables as $t => $note) {
    $exists = checkTable($pdo, $t);
    $status = $exists ? "<span class='status status-ok'>EXISTS</span>" : "<span class='status status-warn'>MISSING</span>";
    echo "<tr><td><b>$t</b></td><td>$status</td><td>$note</td></tr>";
}
echo "</table>";

// 2. CRITICAL SCHEMA CHECKS
echo "<h2>2. Critical Schema Checks</h2>";
echo "<table><tr><th>Check</th><th>Result</th><th>Detail</th></tr>";

// Check Auto Increment
$tablesToCheckAI = ['product_batches', 'stock_movements', 'stock', 'purchases'];
foreach ($tablesToCheckAI as $t) {
    if (checkTable($pdo, $t)) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$t` WHERE Field = 'id'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        $hasAI = strpos($col['Extra'], 'auto_increment') !== false;
        $status = $hasAI ? "<span class='status status-ok'>PASS</span>" : "<span class='status status-fail'>FAIL</span>";
        echo "<tr><td>$t (Auto-Inc)</td><td>$status</td><td>Extra: " . $col['Extra'] . "</td></tr>";
    }
}

// Check Negative Stock
$stmtNeg = $pdo->query("SELECT p.name, s.quantity FROM stock s JOIN products p ON s.product_id = p.id WHERE s.quantity < 0");
$negItems = $stmtNeg->fetchAll(PDO::FETCH_ASSOC);
$statusNeg = empty($negItems) ? "<span class='status status-ok'>0 Items</span>" : "<span class='status status-warn'>" . count($negItems) . " Items</span>";
echo "<tr><td>Negative Stock Items</td><td>$statusNeg</td><td>Items found below zero</td></tr>";
echo "</table>";

if (!empty($negItems)) {
    echo "<h5>Negative Stock Detail:</h5><table>";
    foreach ($negItems as $item) echo "<tr><td>{$item['name']}</td><td><b>{$item['quantity']}</b></td></tr>";
    echo "</table>";
}

// 3. CODE VS DB COMPATIBILITY
echo "<h2>3. Code Compatibility</h2>";
echo "<div class='info'>Checking LandedCostCalculator.php reference...</div>";
$filePath = dirname(__FILE__) . '/classes/LandedCostCalculator.php';
if (file_exists($filePath)) {
    $content = file_get_contents($filePath);
    $foundCosts = strpos($content, 'shipment_costs') !== false;
    $foundDetails = strpos($content, 'shipment_cost_details') !== false;
    
    echo "<table><tr><th>Code Pattern</th><th>Found</th><th>Action Needed</th></tr>";
    echo "<tr><td>'shipment_costs' in PHP</td><td>" . ($foundCosts ? 'YES' : 'NO') . "</td><td>-</td></tr>";
    echo "<tr><td>'shipment_cost_details' in PHP</td><td>" . ($foundDetails ? 'YES' : 'NO') . "</td><td>-</td></tr>";
    
    // Check if the one in PHP exists in DB
    $intended = $foundCosts ? 'shipment_costs' : ($foundDetails ? 'shipment_cost_details' : null);
    if ($intended && !checkTable($pdo, $intended)) {
        echo "<tr><td colspan='3' class='status-fail'>CRITICAL: Code expects table '$intended' but it DOES NOT EXIST in database.</td></tr>";
    }
    echo "</table>";
}

// 4. ACTION CENTER
echo "<h2>4. Action Center</h2>";
echo "<p>Use these to fix detected issues on the live site:</p>";
echo "<a href='repair_db.php' class='btn'>Run Quick Repair Script</a> ";
echo "<a href='?action=fix_naming' class='btn' style='background:#e67e22;'>Sync Table Names</a>";

if (isset($_GET['action']) && $_GET['action'] == 'fix_naming') {
    echo "<div class='info' style='margin-top:20px;'>";
    try {
        if (checkTable($pdo, 'shipment_costs') && !checkTable($pdo, 'shipment_cost_details')) {
             $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_cost_details LIKE shipment_costs; INSERT INTO shipment_cost_details SELECT * FROM shipment_costs;");
             echo "Synced shipment_costs to shipment_cost_details.<br>";
        } elseif (checkTable($pdo, 'shipment_cost_details') && !checkTable($pdo, 'shipment_costs')) {
             $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_costs LIKE shipment_cost_details; INSERT INTO shipment_costs SELECT * FROM shipment_cost_details;");
             echo "Synced shipment_cost_details to shipment_costs.<br>";
        }
        echo "<b>Done. Refresh to view status.</b>";
    } catch (Exception $e) {
        echo "Error syncing: " . $e->getMessage();
    }
    echo "</div>";
}

echo "</div></body></html>";
