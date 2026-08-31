<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

if (!isset($_GET['shipment']) || !isset($_SESSION['receipt_summary'])) {
    redirect('index.php');
}

$summary = $_SESSION['receipt_summary'];
// Clear the session variable so it doesn't persist forever, but maybe keep it for a reload?
// unset($_SESSION['receipt_summary']); 

$page_title = 'Receipt Confirmed';
include '../../includes/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    <div id="page-content-wrapper" class="w-100">
        <?php include '../../includes/navbar.php'; ?>
        <div class="container-fluid px-3 py-3">
            
            <div class="card border-0 rounded-0 shadow-sm mt-4">
                <div class="card-body p-4"> <!-- Reduced padding -->
                    
                    <div class="text-center mb-4"> <!-- Reduced margin -->
                        <div class="mb-2 text-success">
                            <i class="fas fa-check-circle" style="font-size: 2.5rem;"></i> <!-- Reduced icon size -->
                        </div>
                        <h4 class="fw-bold">Receipt Confirmed!</h4> <!-- Reduced heading size -->
                        <p class="text-muted small">Shipment <strong><?php echo htmlspecialchars($summary['shipment_no']); ?></strong> has been successfully received.</p>
                    </div>
                    
                    <div class="row justify-content-center">
                        <div class="col-md-7"> <!-- Reduced column width -->
                            <div class="card bg-light border-0 rounded-3 mb-3">
                                <div class="card-body py-2 px-3"> <!-- Compact card body -->
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="small">
                                            <i class="fas fa-user-check text-success me-1"></i> <strong><?php echo htmlspecialchars($summary['received_by']); ?></strong>
                                            <span class="text-muted mx-2">|</span>
                                            <i class="fas fa-clock text-success me-1"></i> <?php echo htmlspecialchars($summary['time']); ?>
                                        </div>
                                        <a href="print_receipt.php?shipment=<?php echo $summary['shipment_id']; ?>" class="btn btn-link btn-sm text-dark text-decoration-none p-0"><i class="fas fa-print"></i> Print</a>
                                    </div>
                                </div>
                            </div>
                            
                            <h6 class="fw-bold mb-2 small text-uppercase">Inventory Updates</h6>
                            <ul class="list-group list-group-flush mb-4 small"> <!-- Flush list for cleaner look -->
                                <?php foreach($summary['items'] as $item): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent border-bottom dashed px-0 py-2">
                                    <div>
                                        <i class="fas fa-box-open text-muted me-2"></i><strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                        <span class="text-muted ms-1">(<?php echo htmlspecialchars($item['batch']); ?>)</span>
                                    </div>
                                    <div class="text-end">
                                        <span class="text-success fw-bold">+<?php echo $item['qty']; ?></span>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            
                            <div class="d-flex justify-content-center gap-2 mt-4">
                                <a href="view.php?id=<?php echo $summary['shipment_id']; ?>" class="btn btn-outline-primary btn-sm rounded-0 px-3"><i class="fas fa-box"></i> View Shipment</a>
                                <a href="../products/index.php" class="btn btn-outline-secondary btn-sm rounded-0 px-3"><i class="fas fa-cubes"></i> Inventory</a>
                                <a href="index.php" class="btn btn-outline-dark btn-sm rounded-0 px-3">Back to List</a>
                            </div>
                            
                        </div>
                    </div>
                    
                </div>
            </div>
            
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
