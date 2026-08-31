<?php
/**
 * ONLINE PO AUDIT SCRIPT
 * 
 * This script diagnoses the SQL error:
 * "Incorrect datetime value: 'TZS' for column 'created_at'"
 */

require_once 'stock/config/database.php';
require_once 'stock/config/functions.php';

header('Content-Type: text/plain');

echo "ONLINE PO AUDIT START\n";
echo "=====================\n\n";

try {
    // 1. Database Connection Info
    echo "DB Connection: OK\n\n";

    // 2. Table Schema Audit
    echo "--- Schema of stocks_purchase_orders ---\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM stocks_purchase_orders");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $fieldNames = array_column($cols, 'Field');
    
    foreach ($cols as $idx => $c) {
        echo sprintf("#%-2d: %-25s | %-15s | Null: %-3s | Key: %-3s | Default: %s\n", 
            $idx, $c['Field'], $c['Type'], $c['Null'], $c['Key'], $c['Default']);
    }
    echo "\n";

    // 3. Check for Positional Misalignment Suspects
    $currencyIdx = array_search('currency', $fieldNames);
    $createdAtIdx = array_search('created_at', $fieldNames);
    
    echo "Currency Index: " . ($currencyIdx !== false ? $currencyIdx : "NOT FOUND") . "\n";
    echo "Created At Index: " . ($createdAtIdx !== false ? $createdAtIdx : "NOT FOUND") . "\n";
    
    if ($currencyIdx !== false && $createdAtIdx !== false) {
        $gap = $createdAtIdx - $currencyIdx;
        echo "Gap between Currency and Created At: $gap columns\n";
    }
    echo "\n";

    // 4. Test Simulated Insert (create.php logic)
    echo "--- Simulating create.php INSERT ---\n";
    $candidateValues = [
        'po_number' => 'AUDIT-' . date('YmdHis'),
        'supplier_id' => 1,
        'purchase_type' => 'domestic',
        'status' => 'draft',
        'currency' => 'TZS',
        'exchange_rate' => 2600,
        'created_by' => 1
    ];

    $insertCols = [];
    $valueSql = [];
    $insertVals = [];

    foreach ($candidateValues as $col => $val) {
        if (!in_array($col, $fieldNames, true)) {
            echo "Skipping candidate '$col' (missing from table)\n";
            continue;
        }
        $insertCols[] = $col;
        $valueSql[] = '?';
        $insertVals[] = $val;
    }

    if (in_array('created_at', $fieldNames, true)) {
        $insertCols[] = 'created_at';
        $valueSql[] = 'NOW()';
    }

    $sql = 'INSERT INTO stocks_purchase_orders (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $valueSql) . ')';
    echo "SQL: $sql\n";
    echo "Vals: " . json_encode($insertVals) . "\n";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($insertVals);
        $newId = $pdo->lastInsertId();
        echo "Simulated Insert: SUCCESS (ID: $newId)\n";
        
        // Clean up
        $pdo->exec("DELETE FROM stocks_purchase_orders WHERE id = $newId");
        echo "Clean up: OK\n";
    } catch (PDOException $ex) {
        echo "Simulated Insert: FAILED\n";
        echo "Error: " . $ex->getMessage() . "\n";
    }
    echo "\n";

    // 5. Check for triggers
    echo "--- Triggers ---\n";
    $stmtT = $pdo->query("SHOW TRIGGERS");
    $triggers = $stmtT->fetchAll(PDO::FETCH_ASSOC);
    if (empty($triggers)) {
        echo "No triggers found.\n";
    } else {
        foreach ($triggers as $t) {
            if ($t['Table'] == 'stocks_purchase_orders') {
                echo "Trigger: {$t['Trigger']} | Event: {$t['Event']} | Statement: {$t['Statement']}\n";
            }
        }
    }
    echo "\n";

    // 6. Check for Variables that might interfere
    echo "--- PDO State ---\n";
    echo "PDO ErrorMode: " . $pdo->getAttribute(PDO::ATTR_ERRMODE) . "\n";
    echo "PDO Case: " . $pdo->getAttribute(PDO::ATTR_CASE) . "\n";

} catch (Throwable $e) {
    echo "FATAL ERROR DURING AUDIT: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\nAUDIT FINISHED\n";
