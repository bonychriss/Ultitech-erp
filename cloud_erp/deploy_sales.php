<?php
// deploy_sales.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Sales Module Deployment</h1>";

// 1. Ensure 'modules' is writable
$modulesDir = __DIR__ . '/modules';
if (is_dir($modulesDir)) {
    @chmod($modulesDir, 0777); 
}

// 2. Create 'modules/Sales' folder
$salesDir = __DIR__ . '/modules/Sales';
if (!file_exists($salesDir)) {
    echo "Creating 'modules/Sales' folder... ";
    if (mkdir($salesDir, 0777, true)) {
        echo "<span style='color:green'>Success</span><br>";
        @chmod($salesDir, 0777);
    } else {
         echo "<span style='color:red'>FAILED!</span><br>";
         echo "Please manually create folder: <code>cloud_erp/modules/Sales</code> and refresh.";
         die();
    }
}

// 3. Write Files
$files = [
    'manifest.json' => '{ "name": "Sales", "version": "1.0", "description": "Sales Orders & Invoicing", "enabled": true }',
    
    'install.php' => '<?php
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Database;
echo "<h1>Sales Installer</h1>";
try {
    $pdo = Database::getInstance();
    
    // Sales Orders
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_orders (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        company_id INT NOT NULL, 
        customer_id INT, 
        order_date DATE, 
        status ENUM(\'draft\', \'confirmed\', \'invoiced\', \'cancelled\') DEFAULT \'draft\', 
        total_amount DECIMAL(15,2) DEFAULT 0, 
        user_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");
    
    // Sales Order Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        order_id INT NOT NULL, 
        description VARCHAR(255), 
        quantity DECIMAL(10,2), 
        unit_price DECIMAL(15,2), 
        total DECIMAL(15,2), 
        FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    echo "<li>Sales Tables Created.</li>";
    echo "<h3><a href=\'../../index.php\'>Success! Go to Dashboard</a></h3>";
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }',

'index.php' => '<?php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();

// Fetch Orders
$stmt = $pdo->prepare("SELECT so.*, c.name as customer_name FROM sales_orders so LEFT JOIN crm_customers c ON so.customer_id = c.id WHERE so.company_id = ? ORDER BY so.order_date DESC");
$stmt->execute([$user[\'erp_company_id\']]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Sales</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container p-4">
    <div class="d-flex justify-content-between mb-4">
        <h4>Sales Orders</h4>
        <a href="create.php" class="btn btn-primary">+ New Order</a>
    </div>
    <div class="card shadow-sm">
        <table class="table mb-0 align-middle">
            <thead class="bg-light"><tr><th>ID</th><th>Date</th><th>Customer</th><th>Status</th><th>Total</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach($orders as $o): ?>
                <tr>
                    <td>#<?= $o[\'id\'] ?></td>
                    <td><?= $o[\'order_date\'] ?></td>
                    <td><?= htmlspecialchars($o[\'customer_name\'] ?? \'Walk-in\') ?></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($o[\'status\']) ?></span></td>
                    <td class="fw-bold">$<?= number_format($o[\'total_amount\'], 2) ?></td>
                    <td><button class="btn btn-sm btn-light border">View</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body></html>',

'create.php' => '<?php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();

// Handle Post
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\') {
    $customer_id = $_POST[\'customer_id\'] ?: null;
    $date = date(\'Y-m-d\');
    $amount = $_POST[\'amount\'];
    $desc = $_POST[\'description\'];

    $pdo->beginTransaction();
    try {
        // Create Order
        $stmt = $pdo->prepare("INSERT INTO sales_orders (company_id, customer_id, order_date, total_amount, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user[\'erp_company_id\'], $customer_id, $date, $amount, $user[\'erp_user_id\']]);
        $order_id = $pdo->lastInsertId();

        // Create Item (Simple 1 item for now)
        $stmt = $pdo->prepare("INSERT INTO sales_order_items (order_id, description, quantity, unit_price, total) VALUES (?, ?, 1, ?, ?)");
        $stmt->execute([$order_id, $desc, $amount, $amount]);

        $pdo->commit();
        header("Location: index.php"); exit;
    } catch(Exception $e) {
        $pdo->rollBack();
        die($e->getMessage());
    }
}

// Fetch Customers for Dropdown
$stmt = $pdo->prepare("SELECT id, name FROM crm_customers WHERE company_id = ?");
$stmt->execute([$user[\'erp_company_id\']]);
$customers = $stmt->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>New Sales Order</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container mt-5" style="max-width:600px">
    <div class="card p-4 shadow-sm">
        <h5 class="mb-3">New Sales Order</h5>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="">-- Walk-in Customer --</option>
                    <?php foreach($customers as $c): ?>
                    <option value="<?= $c[\'id\'] ?>"><?= htmlspecialchars($c[\'name\']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Requires CRM Customers</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Item Description</label>
                <input type="text" name="description" class="form-control" required placeholder="e.g. Service Fee">
            </div>
            <div class="mb-3">
                <label class="form-label">Total Amount</label>
                <input type="number" name="amount" class="form-control" step="0.01" required>
            </div>
            <button class="btn btn-primary w-100">Create Order</button>
        </form>
    </div>
</div>
</body></html>'
];

foreach ($files as $name => $content) {
    if (file_put_contents("$salesDir/$name", $content)) {
        echo "Created file: modules/Sales/$name<br>";
    } else {
        echo "<span style='color:red'>Failed to create modules/Sales/$name</span><br>";
    }
}

echo "<h3>Sales Module Deployed! <a href='modules/Sales/install.php'>Click to Install Database</a></h3>";
?>
