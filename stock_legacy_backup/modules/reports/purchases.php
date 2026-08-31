<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';

$sql = "SELECT p.*, s.name as supplier_name, pr.name as product_name 
        FROM purchases p 
        JOIN suppliers s ON p.supplier_id = s.id 
        JOIN products pr ON p.product_id = pr.id 
        WHERE DATE(p.created_at) BETWEEN ? AND ?";
$params = [$start_date, $end_date];

if ($status != '') {
    $sql .= " AND p.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$total_purchases_amount = 0;
foreach($purchases as $p) {
    $total_purchases_amount += $p['total_amount'];
}

$page_title = 'Purchase Report';
include '../../includes/header.php';
?>

<main class="main-content">
    <div class="stock-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Purchase Report</h2>
            <a href="export_purchases.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&status=<?php echo $status; ?>" class="btn btn-success"><i class="fas fa-file-csv"></i> Export to CSV</a>
        </div>
            
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="received" <?php if($status == 'received') echo 'selected'; ?>>Received</option>
                                <option value="pending" <?php if($status == 'pending') echo 'selected'; ?>>Pending</option>
                                <option value="cancelled" <?php if($status == 'cancelled') echo 'selected'; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Filter</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="alert alert-info">
                Total Expenses for Period: <strong>$<?php echo number_format($total_purchases_amount, 2); ?></strong>
            </div>

            <div class="card">
                <div class="card-body">
                    <table class="table table-striped table-hover datatable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Product</th>
                                <th>Status</th>
                                <th>Quantity</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($purchases as $po): ?>
                            <tr>
                                <td><?php echo date('Y-m-d', strtotime($po['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($po['purchase_no']); ?></td>
                                <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                                <td><?php echo htmlspecialchars($po['product_name']); ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo $po['status']; ?></span>
                                </td>
                                <td><?php echo $po['quantity']; ?></td>
                                <td>$<?php echo number_format($po['total_amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
