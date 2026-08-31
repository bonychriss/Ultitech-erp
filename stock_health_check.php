<?php
// stock_health_check.php
// Comprehensive verification for Stock Management Module
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load Configuration
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    // Fallback if dropped in root
    require_once 'config.php';
}

echo "<!DOCTYPE html><html><head><title>Stock Module Health Check</title>";
echo "<style>
    body { font-family: monospace; line-height: 1.5; padding: 20px; }
    .ok { color: green; }
    .fail { color: red; font-weight: bold; }
    .warn { color: orange; font-weight: bold; }
    .box { border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 5px; }
</style></head><body>";

echo "<h1>Stock Module Health Check</h1>";

// 1. Define Expected Schema
$schema = [
    'categories' => [
        'cols' => ['id', 'name', 'description', 'status'],
        'pk' => 'id',
        'ai' => true
    ],
    'products' => [
        'cols' => ['id', 'name', 'sku', 'category_id', 'description', 'unit_price', 'reorder_level', 'status', 'image_path'],
        'pk' => 'id',
        'ai' => true
    ],
    'suppliers' => [
        'cols' => ['id', 'name', 'contact_person', 'email', 'phone', 'address', 'status'],
        'pk' => 'id',
        'ai' => true
    ],
    'stock' => [
        'cols' => ['id', 'product_id', 'quantity', 'batch_no', 'expiry_date', 'location'],
        'pk' => 'id',
        'ai' => true
    ],
    'stock_transactions' => [
        'cols' => ['id', 'product_id', 'type', 'quantity', 'reference', 'created_by', 'created_at'],
        'pk' => 'id',
        'ai' => true
    ],
    'purchases' => [
        'cols' => ['id', 'supplier_id', 'purchase_date', 'total_amount', 'status', 'created_by'],
        'pk' => 'id',
        'ai' => true
    ],
    'purchase_items' => [
        'cols' => ['id', 'purchase_id', 'product_id', 'quantity', 'unit_price', 'total_price'],
        'pk' => 'id',
        'ai' => true
    ],
    'shipments' => [
        'cols' => ['id', 'purchase_id', 'tracking_number', 'carrier', 'status', 'shipped_date', 'expected_delivery'],
        'pk' => 'id',
        'ai' => true
    ]
];

// 2. Run Checks
echo "<div class='box'><h3>Database Schema Verification</h3>";
$tables = [];
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    echo "<div class='fail'>Fatal: Could not list tables. " . $e->getMessage() . "</div>";
    die("</body></html>");
}

$missingTables = [];
$tableStatus = [];

foreach ($schema as $table => $def) {
    if (!in_array($table, $tables)) {
        echo "<div class='fail'>[MISSING] Table '$table' does not exist!</div>";
        $missingTables[] = $table;
        continue;
    }

    echo "<div><strong>Checking table '$table'...</strong></div>";
    
    // Check Columns
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'Field');
        
        $missingCols = array_diff($def['cols'], $colNames);
        if (!empty($missingCols)) {
            echo "<div class='fail'>&nbsp;&nbsp;- Missing Columns: " . implode(', ', $missingCols) . "</div>";
        } else {
            echo "<div class='ok'>&nbsp;&nbsp;- All required columns present.</div>";
        }

        // Check PK and Auto Increment
        foreach ($cols as $c) {
            if ($c['Field'] === $def['pk']) {
                // Check PK
                if ($c['Key'] !== 'PRI') {
                    echo "<div class='fail'>&nbsp;&nbsp;- Column '{$def['pk']}' is NOT Primary Key (Key='{$c['Key']}')</div>";
                } else {
                    echo "<div class='ok'>&nbsp;&nbsp;- Primary Key '{$def['pk']}' OK.</div>";
                }
                
                // Check AI
                if ($def['ai']) {
                    if (strpos($c['Extra'], 'auto_increment') === false) {
                        echo "<div class='fail'>&nbsp;&nbsp;- Column '{$def['pk']}' is MISSING AUTO_INCREMENT!</div>";
                        // Generate fix command
                        echo "<div style='background:#eee;padding:5px;margin:5px 0;'><strong>Fix Query:</strong><br><code>ALTER TABLE $table MODIFY COLUMN {$def['pk']} INT(11) NOT NULL AUTO_INCREMENT;</code></div>";
                    } else {
                        echo "<div class='ok'>&nbsp;&nbsp;- AUTO_INCREMENT OK.</div>";
                    }
                }
            }
        }

    } catch (Exception $e) {
        echo "<div class='fail'>Error describing table: " . $e->getMessage() . "</div>";
    }
    echo "<br>";
}
echo "</div>";

// 3. Logic & Relation Checks
echo "<div class='box'><h3>Data Integrity & Relations</h3>";

// Check Categories
if (!in_array('categories', $missingTables) && !in_array('products', $missingTables)) {
    // Check for products with invalid category_id
    $sql = "SELECT COUNT(*) FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE c.id IS NULL AND p.category_id IS NOT NULL AND p.category_id > 0";
    $orphans = $pdo->query($sql)->fetchColumn();
    if ($orphans > 0) {
        echo "<div class='fail'>[FAIL] Found $orphans products with invalid category_id (orphaned).</div>";
        echo "<div class='warn'>Suggestion: <code>UPDATE products SET category_id = NULL WHERE category_id NOT IN (SELECT id FROM categories);</code></div>";
    } else {
        echo "<div class='ok'>[OK] All product categories are valid.</div>";
    }
}

// Check Stock Links
if (!in_array('stock', $missingTables) && !in_array('products', $missingTables)) {
    // Check for stock with invalid product_id
    $sql = "SELECT COUNT(*) FROM stock s LEFT JOIN products p ON s.product_id = p.id WHERE p.id IS NULL";
    $orphans = $pdo->query($sql)->fetchColumn();
    if ($orphans > 0) {
        echo "<div class='fail'>[FAIL] Found $orphans stock records with invalid product_id.</div>";
    } else {
        echo "<div class='ok'>[OK] All stock records linked to valid products.</div>";
    }
}

echo "</div>";

echo "</body></html>";
?>
