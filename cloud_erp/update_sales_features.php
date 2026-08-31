<?php
// update_sales_features.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Sales Module Update: Quotations & Invoices</h1>";

$baseDir = __DIR__ . '/modules/Sales';
if (!is_dir($baseDir)) {
    die("Error: Sales module not found. Please run deploy_sales.php first.");
}

require_once __DIR__ . '/core/Database.php';
use Core\Database;

try {
    $pdo = Database::getInstance();
    
    // 1. Update Database Schema
    echo "Updating Schema... ";
    
    // Add doc_type if not exists
    $cols = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'doc_type'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE sales_orders ADD COLUMN doc_type ENUM('quote', 'invoice', 'order') DEFAULT 'quote' AFTER customer_id");
        echo "Added 'doc_type' column.<br>";
    } else {
        echo "'doc_type' column exists.<br>";
    }
    
    // Add order_number for formatted IDs (INV-001, qt-001)
    $cols = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'formatted_number'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE sales_orders ADD COLUMN formatted_number VARCHAR(50) AFTER id");
        echo "Added 'formatted_number' column.<br>";
    }

} catch (Exception $e) {
    echo "<span style='color:red'>DB Error: " . $e->getMessage() . "</span><br>";
}

// 2. Deploy/Update Files
$files = [
    // LIST QUOTATIONS
    'quotations.php' => '<?php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();

$stmt = $pdo->prepare("SELECT so.*, c.name as customer_name FROM sales_orders so LEFT JOIN crm_customers c ON so.customer_id = c.id WHERE so.company_id = ? AND so.doc_type = \'quote\' ORDER BY so.created_at DESC");
$stmt->execute([$user[\'erp_company_id\']]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Quotations</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container p-4">
    <div class="d-flex justify-content-between mb-4">
        <h4>Quotations</h4>
        <a href="create.php?type=quote" class="btn btn-primary">+ New Quote</a>
    </div>
    <div class="card shadow-sm"><table class="table mb-0 align-middle"><thead class="bg-light"><tr><th>Number</th><th>Date</th><th>Customer</th><th>Status</th><th>Total</th><th>Action</th></tr></thead><tbody>
    <?php foreach($quotes as $q): ?>
    <tr>
        <td><?= $q[\'formatted_number\'] ?? \'#\'.$q[\'id\'] ?></td>
        <td><?= $q[\'order_date\'] ?></td>
        <td><?= htmlspecialchars($q[\'customer_name\'] ?? \'Walk-in\') ?></td>
        <td><span class="badge bg-secondary"><?= ucfirst($q[\'status\']) ?></span></td>
        <td class="fw-bold">$<?= number_format($q[\'total_amount\'], 2) ?></td>
        <td><a href="view.php?id=<?= $q[\'id\'] ?>" class="btn btn-sm btn-light border">View</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div></body></html>',

    // LIST INVOICES
    'invoices.php' => '<?php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();

$stmt = $pdo->prepare("SELECT so.*, c.name as customer_name FROM sales_orders so LEFT JOIN crm_customers c ON so.customer_id = c.id WHERE so.company_id = ? AND so.doc_type = \'invoice\' ORDER BY so.created_at DESC");
$stmt->execute([$user[\'erp_company_id\']]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Invoices</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container p-4">
    <div class="d-flex justify-content-between mb-4">
        <h4>Invoices</h4>
        <a href="create.php?type=invoice" class="btn btn-primary">+ New Invoice</a>
    </div>
    <div class="card shadow-sm"><table class="table mb-0 align-middle"><thead class="bg-light"><tr><th>Number</th><th>Date</th><th>Customer</th><th>Status</th><th>Total</th><th>Action</th></tr></thead><tbody>
    <?php foreach($invoices as $i): ?>
    <tr>
        <td><?= $i[\'formatted_number\'] ?? \'#\'.$i[\'id\'] ?></td>
        <td><?= $i[\'order_date\'] ?></td>
        <td><?= htmlspecialchars($i[\'customer_name\'] ?? \'Walk-in\') ?></td>
        <td><span class="badge bg-success"><?= ucfirst($i[\'status\']) ?></span></td>
        <td class="fw-bold">$<?= number_format($i[\'total_amount\'], 2) ?></td>
        <td><a href="view.php?id=<?= $i[\'id\'] ?>" class="btn btn-sm btn-light border">View</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div></body></html>',

    // UNIVERSAL CREATE FORM
    'create.php' => '<?php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();

$type = $_GET[\'type\'] ?? \'quote\'; // Default to quote
$label = ucfirst($type);

if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\') {
    $customer_id = $_POST[\'customer_id\'] ?: null;
    $date = date(\'Y-m-d\');
    $amount = $_POST[\'amount\'];
    $desc = $_POST[\'description\'];
    
    // Generate Number (Simple Auto-inc based logic)
    // Real logic would check last number in DB
    $prefix = ($type === \'quote\') ? \'QT-\' : \'INV-\';
    $rand = rand(1000, 9999);
    $number = $prefix . $rand;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO sales_orders (company_id, customer_id, doc_type, formatted_number, order_date, total_amount, user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $status = ($type === \'invoice\') ? \'invoiced\' : \'draft\';
        $stmt->execute([$user[\'erp_company_id\'], $customer_id, $type, $number, $date, $amount, $user[\'erp_user_id\'], $status]);
        $order_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO sales_order_items (order_id, description, quantity, unit_price, total) VALUES (?, ?, 1, ?, ?)");
        $stmt->execute([$order_id, $desc, $amount, $amount]);

        $pdo->commit();
        header("Location: " . ($type == \'quote\' ? \'quotations.php\' : \'invoices.php\')); exit;
    } catch(Exception $e) {
        $pdo->rollBack();
        die($e->getMessage());
    }
}
// Customers
$stmt = $pdo->prepare("SELECT id, name FROM crm_customers WHERE company_id = ?");
$stmt->execute([$user[\'erp_company_id\']]);
$customers = $stmt->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>New <?= $label ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container mt-5" style="max-width:600px">
    <div class="card p-4 shadow-sm">
        <h5 class="mb-3">New <?= $label ?></h5>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="">-- Walk-in / Guest --</option>
                    <?php foreach($customers as $c): ?>
                    <option value="<?= $c[\'id\'] ?>"><?= htmlspecialchars($c[\'name\']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Total Amount</label>
                <input type="number" name="amount" class="form-control" step="0.01" required>
            </div>
            <button class="btn btn-primary w-100">Create <?= $label ?></button>
        </form>
    </div>
</div></body></html>',

    // VIEW & CONVERT
    'view.php' => '<?php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();
$id = $_GET[\'id\'] ?? 0;

// Convert Action
if (isset($_POST[\'convert\'])) {
    $stmt = $pdo->prepare("UPDATE sales_orders SET doc_type = \'invoice\', formatted_number = CONCAT(\'INV-\', SUBSTRING(formatted_number, 4)), status=\'invoiced\' WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $user[\'erp_company_id\']]);
    header("Location: invoices.php"); exit;
}

// Fetch
$stmt = $pdo->prepare("SELECT so.*, c.name as customer_name FROM sales_orders so LEFT JOIN crm_customers c ON so.customer_id = c.id WHERE so.id = ? AND so.company_id = ?");
$stmt->execute([$id, $user[\'erp_company_id\']]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$doc) die("Document not found");

$items = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id = ?");
$items->execute([$id]);
$lineItems = $items->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>View Document</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container p-4">
    <div class="card p-5 shadow-sm">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold"><?= $doc[\'doc_type\'] == \'quote\' ? \'QUOTATION\' : \'INVOICE\' ?></h2>
                <h5 class="text-muted"><?= $doc[\'formatted_number\'] ?></h5>
            </div>
            <div class="text-end">
                <p class="mb-1">Date: <?= $doc[\'order_date\'] ?></p>
                <p class="mb-1 fw-bold"><?= htmlspecialchars($doc[\'customer_name\'] ?? \'Walk-in Customer\') ?></p>
            </div>
        </div>

        <table class="table table-bordered mb-4">
            <thead class="table-light"><tr><th>Description</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
                <?php foreach($lineItems as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item[\'description\']) ?></td>
                    <td class="text-end">$<?= number_format($item[\'total\'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><th class="text-end">Total</th><th class="text-end">$<?= number_format($doc[\'total_amount\'], 2) ?></th></tr>
            </tfoot>
        </table>

        <div class="d-flex justify-content-end gap-2 no-print">
            <button onclick="window.print()" class="btn btn-secondary">Print PDF</button>
            <?php if($doc[\'doc_type\'] === \'quote\'): ?>
                <form method="POST" onsubmit="return confirm(\'Convert to Invoice?\')">
                    <input type="hidden" name="convert" value="1">
                    <button class="btn btn-success">Convert to Invoice</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div></body></html>',
    
    // UPDATED INDEX to be a menu
    'index.php' => '<?php
require_once __DIR__ . \'/../../core/Auth.php\';
Auth::check();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Sales Module</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container p-5">
    <h3 class="mb-4">Sales & Invoicing</h3>
    <div class="row g-4">
        <div class="col-md-6">
            <a href="quotations.php" class="card p-5 text-center text-decoration-none shadow-sm hover-shadow">
                <h2 class="fw-bold text-primary">Quotations</h2>
                <p class="text-muted">Create and manage price quotes</p>
            </a>
        </div>
        <div class="col-md-6">
            <a href="invoices.php" class="card p-5 text-center text-decoration-none shadow-sm hover-shadow">
                <h2 class="fw-bold text-success">Invoices</h2>
                <p class="text-muted">View invoices and track payments</p>
            </a>
        </div>
    </div>
</div>
</body></html>'
];

foreach ($files as $name => $content) {
    if (file_put_contents("$baseDir/$name", $content)) {
        echo "Updated: modules/Sales/$name<br>";
    } else {
        echo "<span style='color:red'>Failed to write: $name</span><br>";
    }
}
echo "<h3>Sales Features Updated! <a href='modules/Sales/index.php'>Go to Sales Menu</a></h3>";
?>
