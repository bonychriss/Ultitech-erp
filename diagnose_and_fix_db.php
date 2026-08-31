<?php
require_once 'stock/config/database.php';

// Fetch all tables
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "<h1>Database Schema Diagnostic & Fixer</h1>";
echo "<p>Checking " . count($tables) . " tables for missing AUTO_INCREMENT...</p>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
echo "<tr style='background:#eee;'><th>Table</th><th>ID Column</th><th>Status</th><th>Action</th></tr>";

$issues_found = false;

foreach ($tables as $table) {
    echo "<tr><td><strong>$table</strong></td>";
    
    // Get Columns
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $idCol = null;
        
        foreach ($columns as $col) {
            if ($col['Field'] === 'id') {
                $idCol = $col;
                break;
            }
        }
        
        if (!$idCol) {
            echo "<td>(No 'id' column)</td><td><span style='color:gray;'>Skipped</span></td><td>-</td></tr>";
            continue;
        }
        
        echo "<td>Found 'id' (Type: {$idCol['Type']})</td>";
        
        if (stripos($idCol['Extra'], 'auto_increment') === false) {
            $issues_found = true;
            echo "<td><span style='color:red; font-weight:bold;'>MISSING AUTO_INCREMENT</span> (Key: {$idCol['Key']})</td>";
            
            // Fix Form
            echo "<td>
                <form method='POST' style='margin:0;'>
                    <input type='hidden' name='fix_table' value='$table'>
                    <button type='submit' style='background:red; color:white; border:none; padding:5px 10px; cursor:pointer;'>FIX NOW</button>
                </form>
            </td>";
        } else {
            echo "<td><span style='color:green;'>OK</span></td><td>-</td>";
        }
        
    } catch (Exception $e) {
        echo "<td colspan='3'>Error: " . $e->getMessage() . "</td>";
    }
    echo "</tr>";
}
echo "</table>";

// Handle Fix
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['fix_table'])) {
    $table = $_POST['fix_table'];
    echo "<div style='margin-top:20px; padding:10px; background:#ffeb3b; border:1px solid #eba400;'>";
    echo "<strong>Attempting to fix table: $table...</strong><br>";
    
    try {
        // 1. Check if Primary Key exists
        $stmt = $pdo->query("SHOW KEYS FROM $table WHERE Key_name = 'PRIMARY'");
        $pk = $stmt->fetch();
        
        if (!$pk) {
            echo " - Primary Key missing. Adding Primary Key... ";
            $pdo->exec("ALTER TABLE $table ADD PRIMARY KEY (id)");
            echo "<span style='color:green;'>Done.</span><br>";
        }
        
        // 2. Add Auto Increment
        echo " - Adding AUTO_INCREMENT... ";
        $pdo->exec("ALTER TABLE $table MODIFY id INT NOT NULL AUTO_INCREMENT");
        echo "<span style='color:green;'>SUCCESS! Table $table is fixed.</span>";
        echo "<script>setTimeout(function(){ window.location.href = window.location.href; }, 1000);</script>"; // Refresh
        
    } catch (PDOException $e) {
        echo "<span style='color:red;'>FAILED: " . $e->getMessage() . "</span>";
    }
    echo "</div>";
}
?>
