<?php
require_once 'config/database.php';

echo "<style>
    body { font-family: sans-serif; line-height: 1.6; padding: 20px; color: #333; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: #555; background: #f9f9f9; padding: 10px; border-left: 5px solid #007bff; margin: 10px 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #eee; }
</style>";

echo "<h1>Diagnostic & Repair Utility</h1>";

try {
    // 1. FIX DATABASE SCHEMA
    echo "<h3>1. Database Schema Check</h3>";
    
    // Fix product_batches id column
    try {
        $pdo->exec("ALTER TABLE `product_batches` MODIFY `id` INT AUTO_INCREMENT;");
        echo "<span class='success'>[FIXED]</span> `product_batches.id` is now AUTO_INCREMENT.<br>";
    } catch (Exception $e) {
        echo "<span class='info'>[INFO]</span> product_batches.id: " . $e->getMessage() . "<br>";
    }

    // Fix sales_share_tokens table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_share_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL,
            doc_type ENUM('order', 'invoice') NOT NULL,
            doc_id INT NOT NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            used_at DATETIME NULL,
            expires_at DATETIME NULL,
            INDEX (token)
        )");
        echo "<span class='success'>[OK]</span> `sales_share_tokens` table checked/created.<br>";
    } catch (Exception $e) {
        echo "<span class='error'>[ERROR]</span> sales_share_tokens: " . $e->getMessage() . "<br>";
    }

    // 2. DIAGNOSE EAR PLUGS (PRD-2026-046)
    echo "<h3>2. Product Diagnostics: EAR PLUGS</h3>";
    
    $stmt = $pdo->prepare("SELECT id, name, product_code FROM products WHERE product_code = ? OR name LIKE ?");
    $stmt->execute(['PRD-2026-046', '%EAR PLUG%']);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        $prodId = $product['id'];
        echo "Found Product: <b>" . htmlspecialchars($product['name']) . "</b> (ID: $prodId)<br>";

        // Get Current Stock
        $stmtStock = $pdo->prepare("SELECT quantity FROM stock WHERE product_id = ?");
        $stmtStock->execute([$prodId]);
        $currentQty = $stmtStock->fetchColumn();
        echo "Current Digital Stock: <b>$currentQty</b><br>";

        // FIX - Set to -100 as requested
        if ($currentQty != -100) {
            echo "Directing stock to -100 as per request... ";
            $pdo->prepare("UPDATE stock SET quantity = -100 WHERE product_id = ?")->execute([$prodId]);
            echo "<span class='success'>DONE.</span><br>";
        }

        // Show Movement History
        echo "<h4>Movement Timeline (Where the error started):</h4>";
        $stmtMove = $pdo->prepare("SELECT * FROM stock_movements WHERE product_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmtMove->execute([$prodId]);
        $movements = $stmtMove->fetchAll(PDO::FETCH_ASSOC);

        if ($movements) {
            echo "<table>
                    <tr><th>Date</th><th>Type</th><th>Qty</th><th>Ref</th><th>Notes</th></tr>";
            foreach ($movements as $m) {
                $typeClass = $m['movement_type'] == 'in' ? 'success' : 'error';
                echo "<tr>
                        <td>{$m['created_at']}</td>
                        <td><span class='$typeClass'>".strtoupper($m['movement_type'])."</span></td>
                        <td>{$m['quantity']}</td>
                        <td>{$m['reference_type']} #{$m['reference_id']}</td>
                        <td>".htmlspecialchars($m['notes'])."</td>
                      </tr>";
            }
            echo "</table>";
        } else {
            echo "No movement records found.<br>";
        }
    } else {
        echo "<span class='error'>Product PRD-2026-046 not found in database.</span><br>";
    }

    echo "<h3>3. Next Steps</h3>";
    echo "<div class='info'>
        1. Stock for Ear Plugs has been manually corrected to -100.<br>
        2. Database is now ready to receive new stock without errors.<br>
        3. <a href='modules/shipments/index.php'>Click here to go and Receive the 100 Ear Plugs</a> from your supplier.<br>
        4. After you receive them, your final stock will be <b>0</b> (-100 + 100).
    </div>";

} catch (Exception $e) {
    echo "<div class='error'>Global Error: " . $e->getMessage() . "</div>";
}
?>
