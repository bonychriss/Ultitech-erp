<?php
require_once 'stock/config/database.php';

$tables = [
    'price_audit_trail', // This is likely the culprit
    'purchases', 
    'purchase_items', 
    'stock_transactions',
    'products',
    'suppliers'
];

echo "<h1>Force DB Repair (Primary Keys + Auto Increment)</h1>";
echo "<pre>";

foreach ($tables as $table) {
    echo "Processing table: <strong>$table</strong>... \n";
    
    try {
        // 1. Get Column Info
        $stmt = $pdo->query("SHOW COLUMNS FROM $table LIKE 'id'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$col) {
            echo "  - Column 'id' missing! Skipping.\n";
            continue;
        }
        
        // 2. Check if it's already AI
        if (stripos($col['Extra'], 'auto_increment') !== false) {
             echo "  - Already AUTO_INCREMENT. OK.\n";
             echo "--------------------------------------------------\n";
             continue; // Skip if already good
        }
        
        echo "  - Missing AUTO_INCREMENT. Attempting repair...\n";
        
        // 3. Ensure it is a Primary Key first (Required for Auto Increment)
        if ($col['Key'] !== 'PRI') {
             echo "  - Column is NOT a Primary Key. Adding Primary Key... ";
             try {
                 $pdo->exec("ALTER TABLE $table ADD PRIMARY KEY (id)");
                 echo "<span style='color:green;'>Done.</span>\n";
             } catch (PDOException $pkErr) {
                 // Check if Multiple primary key defined (could be another column?)
                 echo "<span style='color:orange;'>Warning: " . $pkErr->getMessage() . "</span>\n";
             }
        }
        
        // 4. Modify to add AUTO_INCREMENT
        echo "  - Applying AUTO_INCREMENT... ";
        $pdo->exec("ALTER TABLE $table MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
        echo "<span style='color:green;'>SUCCESS!</span>\n";
        
    } catch (PDOException $e) {
        echo "<span style='color:red;'>CRITICAL ERROR: " . $e->getMessage() . "</span>\n";
    }
    echo "--------------------------------------------------\n";
}

echo "</pre>";
echo "<p>Repair Complete. Please retry your action.</p>";
?>
