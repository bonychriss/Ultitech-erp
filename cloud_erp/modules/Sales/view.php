<?php
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Database.php';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();
$id = $_GET['id'] ?? 0;

// Convert Action
if (isset($_POST['convert'])) {
    $stmt = $pdo->prepare("UPDATE sales_orders SET doc_type = 'invoice', formatted_number = CONCAT('INV-', SUBSTRING(formatted_number, 4)), status='invoiced' WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $user['erp_company_id']]);
    header("Location: invoices.php"); exit;
}

// Fetch
$stmt = $pdo->prepare("SELECT so.*, c.name as customer_name FROM sales_orders so LEFT JOIN crm_customers c ON so.customer_id = c.id WHERE so.id = ? AND so.company_id = ?");
$stmt->execute([$id, $user['erp_company_id']]);
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
                <h2 class="fw-bold"><?= $doc['doc_type'] == 'quote' ? 'QUOTATION' : 'INVOICE' ?></h2>
                <h5 class="text-muted"><?= $doc['formatted_number'] ?></h5>
            </div>
            <div class="text-end">
                <p class="mb-1">Date: <?= $doc['order_date'] ?></p>
                <p class="mb-1 fw-bold"><?= htmlspecialchars($doc['customer_name'] ?? 'Walk-in Customer') ?></p>
            </div>
        </div>

        <table class="table table-bordered mb-4">
            <thead class="table-light"><tr><th>Description</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
                <?php foreach($lineItems as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['description']) ?></td>
                    <td class="text-end">$<?= number_format($item['total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><th class="text-end">Total</th><th class="text-end">$<?= number_format($doc['total_amount'], 2) ?></th></tr>
            </tfoot>
        </table>

        <div class="d-flex justify-content-end gap-2 no-print">
            <button onclick="window.print()" class="btn btn-secondary">Print PDF</button>
            <?php if($doc['doc_type'] === 'quote'): ?>
                <form method="POST" onsubmit="return confirm('Convert to Invoice?')">
                    <input type="hidden" name="convert" value="1">
                    <button class="btn btn-success">Convert to Invoice</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div></body></html>