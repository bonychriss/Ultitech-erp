<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

// Simple Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM shippers WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'Shipper deleted successfully!');
    } catch (PDOException $e) {
        flash('success', 'Cannot delete shipper in use.', 'danger');
    }
    redirect('index.php');
}

$stmt = $pdo->query("SELECT * FROM shippers ORDER BY name ASC");
$shippers = $stmt->fetchAll();

$page_title = 'Freight Forwarders & Shippers';
include '../../includes/header.php';
?>

<main class="main-content">
    <div class="stock-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Shippers & Carriers</h4>
            <div class="btn-group">
                <a href="../shipments/index.php" class="btn btn-outline-secondary btn-sm rounded-0"><i class="fas fa-arrow-left"></i> Shipments</a>
                <a href="add.php" class="btn btn-primary btn-sm rounded-0"><i class="fas fa-plus"></i> New Shipper</a>
            </div>
        </div>
        
        <?php flash('success'); ?>
        
        <div class="card border-0 shadow-sm rounded-0">
            <div class="card-body p-0">
                <table class="table table-striped table-hover table-sm mb-0 align-middle" style="font-size: 0.85rem;">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th class="ps-3 py-2">Name</th>
                            <th class="py-2">Service Type</th>
                            <th class="py-2">Contact</th>
                            <th class="text-center py-2">Reliability</th>
                            <th class="text-center py-2">Avg Days</th>
                            <th class="text-end py-2">Rate/kg</th>
                            <th class="text-end py-2">Rate/CBM</th>
                            <th class="text-center py-2 pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($shippers as $ship): ?>
                        <tr>
                            <td class="ps-3 fw-bold">
                                <?php echo htmlspecialchars($ship['name']); ?>
                            </td>
                            <td><span class="badge bg-light text-dark border rounded-0 text-uppercase"><?php echo $ship['service_type']; ?></span></td>
                            <td>
                                <div><?php echo htmlspecialchars($ship['contact_person']); ?></div>
                                <div class="text-muted small"><?php echo htmlspecialchars($ship['phone']); ?></div>
                            </td>
                            <td class="text-center">
                                <?php 
                                    $score = $ship['reliability_score'];
                                    $color = $score >= 4.5 ? 'success' : ($score >= 3.5 ? 'warning' : 'danger');
                                ?>
                                <span class="badge bg-<?php echo $color; ?> rounded-pill"><?php echo $score; ?></span>
                            </td>
                            <td class="text-center"><?php echo $ship['average_delivery_days']; ?> days</td>
                            <td class="text-end">$<?php echo number_format($ship['cost_per_kg'], 2); ?></td>
                            <td class="text-end">$<?php echo number_format($ship['cost_per_cbm'], 2); ?></td>
                            <td class="text-center">
                                <a href="edit.php?id=<?php echo $ship['id']; ?>" class="text-primary me-2"><i class="fas fa-edit"></i></a>
                                <a href="index.php?delete=<?php echo $ship['id']; ?>" class="text-danger" onclick="return confirm('Delete this shipper?');"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($shippers)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No shippers found. Add your first carrier.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
