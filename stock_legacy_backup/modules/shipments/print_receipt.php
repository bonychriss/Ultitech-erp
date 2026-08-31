<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

if (!isset($_GET['shipment'])) {
    die("Shipment ID not specified.");
}

$id = $_GET['shipment'];
$userId = $_SESSION['user_id'] ?? 1;

// Fetch Shipment Details
$stmt = $pdo->prepare("SELECT s.*, su.name as supplier_name, su.address as supplier_addr, 
                              u.username as received_by_user
                       FROM shipments s 
                       LEFT JOIN suppliers su ON s.supplier_id = su.id 
                       LEFT JOIN users u ON s.received_by = u.id
                       WHERE s.id = ?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment) {
    die("Shipment not found.");
}

// Fetch Items
$stmtItems = $pdo->prepare("SELECT si.*, p.name AS product_name, p.product_code,
                            stk.name AS stocks_item_name, stk.sku AS stocks_item_sku
                            FROM shipment_items si
                            LEFT JOIN products p ON si.product_id = p.id
                            LEFT JOIN stocks_items stk ON si.stocks_item_id = stk.id
                            WHERE si.shipment_id = ?");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();

// Fetch Company Info (Hardcoded for now or fetch from settings)
$companyName = "SABOR ESPANOL TZ";
$companyAddress = "123 Business Road, Dar es Salaam, Tanzania";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GRN - <?php echo $shipment['invoice_number']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #fff; color: #000; }
        .invoice-header { border-bottom: 2px solid #000; padding-bottom: 20px; margin-bottom: 20px; }
        .table thead th { background-color: #f8f9fa !important; border-bottom: 2px solid #000; font-weight: bold; }
        .watermark { position: fixed; top: 50%; left: 50%; opacity: 0.1; transform: translate(-50%, -50%) rotate(-45deg); font-size: 5rem; font-weight: bold; z-index: -1; }
        @media print {
            .no-print { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body>

    <div class="watermark">RECEIVED</div>

    <div class="container my-5">
        
        <!-- Action Buttons -->
        <div class="d-flex justify-content-end mb-4 no-print">
            <button onclick="window.print()" class="btn btn-primary me-2"><i class="fas fa-print"></i> Print Details</button>
            <a href="receipt_confirmed.php?shipment=<?php echo $id; ?>" class="btn btn-secondary">Back</a>
        </div>

        <div class="card p-4">
            <!-- Header -->
            <div class="row invoice-header align-items-center">
                <div class="col-6">
                    <h2 class="fw-bold mb-0">GOODS RECEIVED NOTE</h2>
                    <p class="text-muted mb-0">GRN No: GRN-<?php echo str_pad($id, 5, '0', STR_PAD_LEFT); ?></p>
                </div>
                <div class="col-6 text-end">
                    <h4 class="fw-bold"><?php echo $companyName; ?></h4>
                    <p class="mb-0 small"><?php echo $companyAddress; ?></p>
                </div>
            </div>

            <!-- Meta Info -->
            <div class="row mb-4">
                <div class="col-4">
                    <h6 class="fw-bold text-uppercase text-muted small">Vendor</h6>
                    <p class="fw-bold mb-0"><?php echo htmlspecialchars($shipment['supplier_name']); ?></p>
                    <small><?php echo htmlspecialchars($shipment['supplier_addr'] ?? ''); ?></small>
                </div>
                <div class="col-4">
                    <h6 class="fw-bold text-uppercase text-muted small">Shipment Details</h6>
                    <table class="table table-sm table-borderless mb-0 small">
                        <tr><td>Invoice/Ref:</td><td class="fw-bold"><?php echo htmlspecialchars($shipment['invoice_number']); ?></td></tr>
                        <tr><td>Date Received:</td><td class="fw-bold"><?php echo date('d M Y H:i', strtotime($shipment['actual_arrival_date'])); ?></td></tr>
                        <tr><td>Received By:</td><td class="fw-bold"><?php echo htmlspecialchars($shipment['received_by_user'] ?? 'N/A'); ?></td></tr>
                    </table>
                </div>
                <div class="col-4 text-end">
                    <div style="border: 1px solid #000; padding: 10px; display: inline-block;">
                        <span class="d-block small fw-bold text-uppercase">Status</span>
                        <span class="h5 fw-bold text-success mb-0">RECEIVED</span>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <table class="table table-bordered align-middle">
                <thead>
                    <tr>
                        <th width="50">#</th>
                        <th>Item Description</th>
                        <th width="150" class="text-center">Expected</th>
                        <th width="150" class="text-center">Received</th>
                        <th width="100" class="text-center">QA Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i=1; foreach($items as $item): ?>
                    <?php
                        $pn = $item['product_name'] ?? $item['stocks_item_name'] ?? '—';
                        $pc = $item['product_code'] ?? $item['stocks_item_sku'] ?? '';
                    ?>
                    <tr>
                        <td class="text-center"><?php echo $i++; ?></td>
                        <td>
                            <div class="fw-bold"><?php echo htmlspecialchars($pn); ?></div>
                            <?php if ($pc !== ''): ?><small class="text-muted"><?php echo htmlspecialchars($pc); ?></small><?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                        <td class="text-center fw-bold bg-light"><?php echo $item['received_quantity']; ?></td>
                        <td class="text-center">
                            <span class="badge bg-success border border-success text-success bg-opacity-10">PASSED</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Footer Signatures -->
            <div class="row mt-5 pt-5">
                <div class="col-6">
                    <div class="border-top border-dark pt-2 w-75">
                        <p class="fw-bold mb-0">Received By (Signature)</p>
                        <small class="text-muted"><?php echo htmlspecialchars($shipment['received_by_user'] ?? 'Staff'); ?></small>
                    </div>
                </div>
                <div class="col-6 text-end">
                    <div class="border-top border-dark pt-2 w-75 ms-auto">
                        <p class="fw-bold mb-0">Authorized By</p>
                        <small class="text-muted">Warehouse Manager</small>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-5 pt-4 text-muted small border-top">
                <p>Generated by Stock Management System on <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>

        </div>
    </div>

    <!-- Auto-print script -->
    <script>
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
