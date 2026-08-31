<?php
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Database.php';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();

$stmt = $pdo->prepare("SELECT so.*, c.name as customer_name FROM sales_orders so LEFT JOIN crm_customers c ON so.customer_id = c.id WHERE so.company_id = ? AND so.doc_type = 'invoice' ORDER BY so.created_at DESC");
$stmt->execute([$user['erp_company_id']]);
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
        <td><?= $i['formatted_number'] ?? '#'.$i['id'] ?></td>
        <td><?= $i['order_date'] ?></td>
        <td><?= htmlspecialchars($i['customer_name'] ?? 'Walk-in') ?></td>
        <td><span class="badge bg-success"><?= ucfirst($i['status']) ?></span></td>
        <td class="fw-bold">$<?= number_format($i['total_amount'], 2) ?></td>
        <td><a href="view.php?id=<?= $i['id'] ?>" class="btn btn-sm btn-light border">View</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div></body></html>