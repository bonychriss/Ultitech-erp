<?php
// deploy_sales_debug_v2.php
$target = __DIR__ . '/modules/Sales/debug_sales_v2.php';
$content = '<?php
// modules/Sales/debug_sales_v2.php
ini_set(\'display_errors\', 1);
error_reporting(E_ALL);

echo "<h1>Sales Debugger V2</h1>";

require_once __DIR__ . \'/../../core/Database.php\';
use Core\Database;

try {
    $pdo = Database::getInstance();
    echo "DB Connected.<br>";
    
    // 1. Check if crm_customers exists
    echo "Checking \'crm_customers\' table... ";
    try {
        $pdo->query("SELECT 1 FROM crm_customers LIMIT 1");
        echo "<span style=\'color:green\'>Exists</span><br>";
    } catch (Exception $e) {
        echo "<span style=\'color:red\'>MISSING! (This causes the Join to fail)</span><br>";
    }
    
    // 2. Check for \'doc_type\' column in sales_orders
    echo "Checking \'doc_type\' column in sales_orders... ";
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE \'doc_type\'");
        if ($stmt->fetch()) {
            echo "<span style=\'color:green\'>Found (Update Ran)</span><br>";
        } else {
            echo "<span style=\'color:red\'>MISSING (Update Script did not run)</span><br>";
        }
    } catch (Exception $e) {
        echo "<span style=\'color:red\'>Error checking column</span><br>";
    }

    // 3. Try the exact query from index.php (Old Version)
    echo "Testing Old Index Query (Join)... ";
    try {
        $user_company_id = 1; // Assume 1 for test
        $stmt = $pdo->prepare("SELECT so.*, c.name as customer_name FROM sales_orders so LEFT JOIN crm_customers c ON so.customer_id = c.id WHERE so.company_id = ? ORDER BY so.order_date DESC");
        $stmt->execute([$user_company_id]);
        echo "<span style=\'color:green\'>Query Success</span><br>";
    } catch (Exception $e) {
        echo "<span style=\'color:red\'>Query Failed: " . $e->getMessage() . "</span><br>";
    }

} catch (Exception $e) {
    echo "Fatal DB Error: " . $e->getMessage();
}
?>';

file_put_contents($target, $content);
echo "Created V2 Debugger: <a href='modules/Sales/debug_sales_v2.php'>modules/Sales/debug_sales_v2.php</a>";
?>
