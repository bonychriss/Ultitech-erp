<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$page_title = 'Batch Management';
include '../../includes/header.php';

// Fetch Batches
$query = "SELECT pb.*, p.name as product_name, p.product_code, s.invoice_number 
          FROM product_batches pb 
          JOIN products p ON pb.product_id = p.id 
          LEFT JOIN shipments s ON pb.shipment_id = s.id 
          WHERE pb.current_stock > 0 
          ORDER BY pb.expiry_date ASC";
$batches = $pdo->query($query)->fetchAll();
?>

<main class="main-content">
    <div class="stock-container">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">Batch Management</h4>
                <p class="text-muted mb-0">Track expiry dates and batch-specific stock</p>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Batch No</th>
                            <th>Product</th>
                            <th>Received</th>
                            <th>Expiry Date</th>
                            <th class="text-center">Initial Qty</th>
                            <th class="text-center">Current Stock</th>
                            <th class="text-end">Unit Cost</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No active batches found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($batches as $batch): ?>
                                <?php 
                                    $expiry = strtotime($batch['expiry_date']);
                                    $today = time();
                                    $days_left = ceil(($expiry - $today) / 86400);
                                    $status_class = '';
                                    if ($days_left < 0) $status_class = 'table-danger';
                                    elseif ($days_left < 30) $status_class = 'table-warning';
                                ?>
                                <tr class="<?= $status_class ?>">
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($batch['batch_number']) ?></td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($batch['product_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($batch['product_code']) ?></small>
                                    </td>
                                    <td><?= date('d M Y', strtotime($batch['received_date'])) ?></td>
                                    <td>
                                        <?= date('d M Y', $expiry) ?>
                                        <?php if ($days_left < 0): ?>
                                            <span class="badge bg-danger ms-1">Expired</span>
                                        <?php elseif ($days_left < 30): ?>
                                            <span class="badge bg-warning text-dark ms-1"><?= $days_left ?> days left</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-muted"><?= $batch['quantity'] ?></td>
                                    <td class="text-center fw-bold fs-5"><?= $batch['current_stock'] ?></td>
                                    <td class="text-end"><?= number_format($batch['unit_cost'], 2) ?></td>
                                    <td><?= htmlspecialchars($batch['location']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

<?php include '../../includes/footer.php'; ?>
