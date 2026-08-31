<?php
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Database.php';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();

$type = $_GET['type'] ?? 'quote'; // Default to quote
$label = ucfirst($type);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = $_POST['customer_id'] ?: null;
    $date = date('Y-m-d');
    $amount = $_POST['amount'];
    $desc = $_POST['description'];
    
    // Generate Number (Simple Auto-inc based logic)
    // Real logic would check last number in DB
    $prefix = ($type === 'quote') ? 'QT-' : 'INV-';
    $rand = rand(1000, 9999);
    $number = $prefix . $rand;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO sales_orders (company_id, customer_id, doc_type, formatted_number, order_date, total_amount, user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $status = ($type === 'invoice') ? 'invoiced' : 'draft';
        $stmt->execute([$user['erp_company_id'], $customer_id, $type, $number, $date, $amount, $user['erp_user_id'], $status]);
        $order_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO sales_order_items (order_id, description, quantity, unit_price, total) VALUES (?, ?, 1, ?, ?)");
        $stmt->execute([$order_id, $desc, $amount, $amount]);

        $pdo->commit();
        header("Location: " . ($type == 'quote' ? 'quotations.php' : 'invoices.php')); exit;
    } catch(Exception $e) {
        $pdo->rollBack();
        die($e->getMessage());
    }
}
// Customers
$stmt = $pdo->prepare("SELECT id, name FROM crm_customers WHERE company_id = ?");
$stmt->execute([$user['erp_company_id']]);
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
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
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
</div></body></html>