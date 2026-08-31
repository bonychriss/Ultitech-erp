<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';
requireLogin();

// Handle Add Package
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_package') {
    $shipment_id = $_POST['shipment_id'];
    $pkg_num = $_POST['package_number'];
    $track = $_POST['tracking_number'];
    $dims = $_POST['dimensions'];
    $weight = $_POST['weight_kg'];
    $cbm = $_POST['cbm'];
    
    $stmt = $pdo->prepare("INSERT INTO shipment_packages (shipment_id, package_number, tracking_number, dimensions, weight_kg, cbm) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$shipment_id, $pkg_num, $track, $dims, $weight, $cbm]);
    
    // Optional: Recalculate Shipment Total CBM/Packages? 
    // For now, just add the individual record.
    
    redirect("view.php?id=$shipment_id&tab=packages");
}

if (!isset($_GET['id'])) redirect('index.php');
$id = $_GET['id'];

// Fetch Shipment with Shipper and Supplier
$stmt = $pdo->prepare("SELECT s.*, 
                              su.name as supplier_name, su.contact_person as supplier_contact, su.phone as supplier_phone,
                              sh.name as shipper_name, sh.phone as shipper_phone, sh.email as shipper_email, sh.website as shipper_website
                       FROM shipments s 
                       LEFT JOIN suppliers su ON s.supplier_id = su.id 
                       LEFT JOIN shippers sh ON s.shipper_id = sh.id
                       WHERE s.id = ?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment) redirect('index.php');

// Fetch Items
$stmtItems = $pdo->prepare("SELECT si.*, p.name as product_name, p.product_code 
                            FROM shipment_items si 
                            LEFT JOIN products p ON si.product_id = p.id 
                            WHERE si.shipment_id = ?");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();

// Fetch Packages
$stmtPkgs = $pdo->prepare("SELECT * FROM shipment_packages WHERE shipment_id = ? ORDER BY id ASC");
$stmtPkgs->execute([$id]);
$packages_list = $stmtPkgs->fetchAll();

// Fetch ECC Docs
$stmtDocs = $pdo->prepare("SELECT * FROM ecc_documents WHERE shipment_id = ? ORDER BY created_at DESC");
$stmtDocs->execute([$id]);
$ecc_docs = $stmtDocs->fetchAll();

// Timeline Logic
// Simplified Timeline Logic
$timeline = [
    'step_pending' => ['label' => 'PENDING', 'icon' => 'fas fa-clock'],
    'step_shipped' => ['label' => 'SHIPPED', 'icon' => 'fas fa-shipping-fast'],
    'step_arrived' => ['label' => 'DELIVERED', 'icon' => 'fas fa-check-circle']
];

// Map specific DB statuses to visual steps
$statusMapping = [
    'pending' => 'step_pending',
    'confirmed' => 'step_pending',
    'shipped' => 'step_shipped',
    'in_transit' => 'step_shipped',
    'arrived_at_port' => 'step_arrived',
    'in_customs' => 'step_arrived',
    'ready_for_pickup' => 'step_arrived',
    'out_for_delivery' => 'step_arrived',
    'delivered' => 'step_arrived',
    'cancelled' => 'step_pending'
];

$statusKey = $statusMapping[$shipment['status']] ?? 'step_pending';

$page_title = 'Shipment Details: ' . $shipment['invoice_number'];
include '../../includes/header.php';
?>

<style>
    .timeline-step { width: 100px; position: relative; z-index: 1; text-align: center; }
    .timeline-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; }
    .timeline-bar { position: absolute; top: 20px; left: 0; width: 100%; height: 4px; background: #e9ecef; z-index: 0; }
    .nav-tabs .nav-link { color: #6c757d; }
    .nav-tabs .nav-link.active { color: #0d6efd; font-weight: 600; border-bottom-color: transparent; }
</style>

<main class="main-content">
    <div class="stock-container">
            
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">Shipment #<?php echo htmlspecialchars($shipment['invoice_number']); ?></h4>
                <div class="text-muted small">
                    <?php echo getShipmentStatusBadge($shipment['status']); ?> 
                    <span class="ms-2"><i class="fas fa-truck text-muted"></i> <?php echo htmlspecialchars($shipment['supplier_name']); ?></span>
                    <?php if($shipment['shipper_name']): ?>
                        <span class="ms-2"><i class="fas fa-shipping-fast text-muted"></i> via <?php echo htmlspecialchars($shipment['shipper_name']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="btn-group">
                <?php if($shipment['status'] !== 'delivered'): ?>
                    <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary" style="border-radius: 0;"><i class="fas fa-edit"></i> Edit</a>
                <?php endif; ?>
                    <a href="index.php" class="btn btn-outline-secondary" style="border-radius: 0;"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <!-- Timeline -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 0;">
            <div class="card-body overflow-auto">
                <div class="d-flex justify-content-between position-relative" style="min-width: 800px;">
                    <div class="timeline-bar"></div>
                    <?php 
                    $passed = true;
                    foreach($timeline as $key => $step): 
                        if ($key == $statusKey) $passed = false; 
                        $isActive = ($key == $statusKey);
                        $isPast = (!$isActive && $passed) || ($key == $statusKey); // Current is also 'past' in terms of coloring
                        
                        // Fix logic: passed becomes false AFTER current
                        
                        $bgClass = $isPast ? 'bg-primary text-white' : 'bg-secondary text-white opacity-25';
                        if ($key == $statusKey) $bgClass = 'bg-primary text-white ring-offset'; // current is active

                        // Date logic
                        $dateDisplay = '';
                        if ($key == 'step_shipped') {
                             if ($shipment['shipment_date']) $dateDisplay .= 'Dep: ' . $shipment['shipment_date'];
                             if ($shipment['etd']) $dateDisplay .= '<br>ETD: ' . $shipment['etd'];
                        }
                        elseif ($key == 'step_arrived') {
                             if ($shipment['eta']) $dateDisplay .= 'ETA: ' . $shipment['eta'];
                             if ($shipment['status'] == 'arrived_at_port' || $shipment['status'] == 'delivered') {
                                  // Show actual arrival if available, or just keeping ETA for now
                             }
                        }
                    ?>
                    <div class="timeline-step">
                        <div class="timeline-icon <?php echo $bgClass; ?>">
                            <i class="<?php echo $step['icon']; ?>"></i>
                        </div>
                        <small class="fw-bold d-block small"><?php echo $step['label']; ?></small>
                        <small class="text-muted d-block" style="font-size: 0.65rem;"><?php echo $dateDisplay; ?></small>
                    </div>
                    <?php 
                        if ($key == $statusKey) {
                             $passed = false; // Next items are future
                        }
                    endforeach; 
                    ?>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-12">
                 <ul class="nav nav-tabs mb-3 border-bottom-0">
                    <li class="nav-item">
                        <button class="nav-link active rounded-0" data-bs-toggle="tab" data-bs-target="#details" type="button">Basic Info</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link rounded-0" data-bs-toggle="tab" data-bs-target="#packages" type="button">Packages <span class="badge bg-secondary rounded-pill ms-1"><?php echo count($packages_list) ?: $shipment['packages_count']; ?></span></button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link rounded-0" data-bs-toggle="tab" data-bs-target="#ecc" type="button">ECC Documents</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link rounded-0" data-bs-toggle="tab" data-bs-target="#shipper" type="button">Shipper Details</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link rounded-0" data-bs-toggle="tab" data-bs-target="#landed-cost" type="button">
                            <i class="fas fa-calculator text-muted small"></i> Landed Cost
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content">
                    <!-- BASIC INFO & ITEMS -->
                    <div class="tab-pane fade show active" id="details">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="card border-0 shadow-sm h-100" style="border-radius: 0;">
                                    <div class="card-header bg-dark text-white fw-bold small py-2" style="border-radius: 0;">Shipment Content</div>
                                    <div class="card-body p-0">
                                         <table class="table table-sm table-striped table-hover mb-0" style="font-size: 0.85rem;">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="ps-3">Product</th>
                                                    <th class="text-center">Qty</th>
                                                    <th class="text-end">Unit Price</th>
                                                    <th class="text-end pe-3">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($items as $item): ?>
                                                <tr>
                                                    <td class="ps-3 align-middle">
                                                        <div class="fw-bold"><?php echo htmlspecialchars($item['product_name'] ?? 'Unknown Product'); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                                    </td>
                                                    <td class="text-center align-middle"><?php echo $item['quantity']; ?></td>
                                                    <td class="text-end align-middle">$<?php echo number_format($item['unit_price'], 2); ?></td>
                                                    <td class="text-end align-middle pe-3 fw-bold">$<?php echo number_format($item['quantity'] * $item['unit_price'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php if(empty($items)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-3">No specific product items linked.</td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr>
                                                    <td colspan="4" class="bg-light px-3 py-2 border-top">
                                                        <small class="text-uppercase text-muted fw-bold">Description / Cargo Manifest</small><br>
                                                        <?php echo nl2br(htmlspecialchars($shipment['description'])); ?>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                 <div class="card border-0 shadow-sm mb-4" style="border-radius: 0;">
                                    <div class="card-header bg-dark text-white fw-bold small py-2" style="border-radius: 0;">Summary</div>
                                    <div class="card-body p-0">
                                        <ul class="list-group list-group-flush small">
                                            <li class="list-group-item d-flex justify-content-between px-3 py-2">
                                                <span class="text-muted">Invoice #</span>
                                                <span class="fw-bold"><?php echo htmlspecialchars($shipment['invoice_number']); ?></span>
                                            </li>
                                             <li class="list-group-item d-flex justify-content-between px-3 py-2">
                                                <span class="text-muted">Contact</span>
                                                <span class="fw-bold"><?php echo htmlspecialchars($shipment['contact_number'] ?? 'NA'); ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between px-3 py-2">
                                                <span class="text-muted">Tracking #</span>
                                                <span class="fw-bold"><?php echo htmlspecialchars($shipment['tracking_number']); ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between px-3 py-2">
                                                <span class="text-muted">Total Value</span>
                                                <span class="fw-bold text-success">$<?php echo number_format($shipment['total_value'], 2); ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between px-3 py-2">
                                                <span class="text-muted">Packages</span>
                                                <span><?php echo $shipment['packages_count']; ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between px-3 py-2">
                                                <span class="text-muted">CBM</span>
                                                <span><?php echo $shipment['cbm']; ?> m³</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PACKAGES TAB -->
                    <div class="tab-pane fade" id="packages">
                         <div class="card border-0 shadow-sm" style="border-radius: 0;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-3">
                                    <h5 class="card-title">Package Tracking</h5>
                                    <button class="btn btn-sm btn-outline-primary rounded-0" data-bs-toggle="modal" data-bs-target="#addPackageModal"><i class="fas fa-plus"></i> Add Package</button>
                                </div>
                                <table class="table table-bordered table-sm align-middle">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Package #</th>
                                            <th>Tracking Ref</th>
                                            <th>Dimensions</th>
                                            <th>Weight</th>
                                            <th>CBM</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($packages_list as $pkg): ?>
                                        <tr>
                                            <td><?php echo $pkg['package_number']; ?></td>
                                            <td><?php echo $pkg['tracking_number']; ?></td>
                                            <td><?php echo $pkg['dimensions']; ?></td>
                                            <td><?php echo $pkg['weight_kg']; ?> kg</td>
                                            <td><?php echo $pkg['cbm']; ?></td>
                                            <td><span class="badge bg-secondary"><?php echo $pkg['status']; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if(empty($packages_list)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                No individual packages tracked. <br>
                                                Total Packages Count: <strong><?php echo $shipment['packages_count']; ?></strong>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                         </div>
                    </div>

                    <!-- ECC DOCUMENTS TAB -->
                    <div class="tab-pane fade" id="ecc">
                         <div class="card border-0 shadow-sm" style="border-radius: 0;">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Clearance Cost Analysis</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <div class="p-3 bg-light border">
                                            <small class="text-muted d-block">Estimated Clearance Cost</small>
                                            <strong class="fs-5">$<?php echo number_format($shipment['estimated_clearance_cost'], 2); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <!-- Analyitcs placeholder -->
                                        <div class="alert alert-info py-2 mb-0 rounded-0">
                                            <i class="fas fa-info-circle"></i> This is an estimated cost. Final Landed Cost should be calculated when the invoice arrives.
                                        </div>
                                    </div>
                                </div>
                                
                                <hr>
                                <h6>Attached Documents</h6>
                                <table class="table table-sm">
                                    <thead><tr><th>Type</th><th>Authority</th><th>Dates</th><th>Status</th><th>Actions</th></tr></thead>
                                    <tbody>
                                        <?php if(empty($ecc_docs)): ?>
                                            <tr><td colspan="5" class="text-muted text-center">No documents uploaded.</td></tr>
                                        <?php endif; ?>
                                        <!-- Loop docs here when implemented -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- SHIPPER TAB -->
                    <div class="tab-pane fade" id="shipper">
                        <div class="card border-0 shadow-sm" style="border-radius: 0;">
                            <div class="card-body">
                                <?php if($shipment['shipper_id']): ?>
                                    <h5 class="card-title"><?php echo htmlspecialchars($shipment['shipper_name']); ?></h5>
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <table class="table table-borderless table-sm">
                                                <tr><td class="text-muted" width="120">Service Type:</td><td><strong><?php echo ucfirst($shipment['service_type'] ?? 'Standard'); ?></strong></td></tr>
                                                <tr><td class="text-muted">Website:</td><td><a href="<?php echo $shipment['shipper_website']; ?>" target="_blank"><?php echo $shipment['shipper_website']; ?></a></td></tr>
                                                <tr><td class="text-muted">Phone:</td><td><?php echo $shipment['shipper_phone']; ?></td></tr>
                                                <tr><td class="text-muted">Email:</td><td><?php echo $shipment['shipper_email']; ?></td></tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <!-- Performance Stats Placeholder -->
                                            <div class="card bg-light border-0">
                                                <div class="card-body">
                                                    <h6 class="card-title small text-uppercase text-muted">Performance</h6>
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <span>Reliability</span>
                                                        <span class="badge bg-success">4.8/5.0</span>
                                                    </div>
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>On-Time Rate</span>
                                                        <span class="badge bg-info">98%</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-shipping-fast fa-3x mb-3 opacity-25"></i>
                                        <p>No specific shipper profile linked to this shipment.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- LANDED COST TAB (Existing Logic) -->
                    <div class="tab-pane fade" id="landed-cost">
                        <form action="save_landed_cost.php" method="POST" id="landedCostForm">
                            <input type="hidden" name="shipment_id" value="<?php echo $id; ?>">
                            <div class="row g-4">
                                <div class="col-lg-8">
                                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 0;">
                                        <div class="card-header bg-white fw-bold" style="border-radius: 0;">Additional Costs</div>
                                        <div class="card-body">
                                            <!-- Existing Cost Fields preserved -->
                                            <h6 class="text-muted mb-3 text-uppercase small ls-1">Shipping & Logistics</h6>
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-4">
                                                    <label class="form-label small">Freight/Shipping</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-0">$</span>
                                                        <input type="number" step="0.01" name="shipping_cost" class="form-control cost-input rounded-0" value="<?php echo $shipment['shipping_cost'] ?? 0; ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Insurance</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-0">$</span>
                                                        <input type="number" step="0.01" name="insurance_cost" class="form-control cost-input rounded-0" value="<?php echo $shipment['insurance_cost'] ?? 0; ?>">
                                                    </div>
                                                </div>
                                                 <div class="col-md-4">
                                                    <label class="form-label small">Mode</label>
                                                    <select name="shipping_method" class="form-select form-select-sm rounded-0">
                                                        <option value="sea" <?php echo ($shipment['shipping_method'] ?? '') == 'sea' ? 'selected' : ''; ?>>Sea Freight</option>
                                                        <option value="air" <?php echo ($shipment['shipping_method'] ?? '') == 'air' ? 'selected' : ''; ?>>Air Freight</option>
                                                        <option value="road" <?php echo ($shipment['shipping_method'] ?? '') == 'road' ? 'selected' : ''; ?>>Road</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <h6 class="text-muted mb-3 text-uppercase small ls-1">Customs & Duties</h6>
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-4">
                                                    <label class="form-label small">Customs Duty</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-0">$</span>
                                                        <input type="number" step="0.01" name="customs_duty" class="form-control cost-input rounded-0" value="<?php echo $shipment['customs_duty'] ?? 0; ?>">
                                                    </div>
                                                </div>
                                                 <div class="col-md-4">
                                                    <label class="form-label small">Brokerage Fees</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-0">$</span>
                                                        <input type="number" step="0.01" name="customs_brokerage" class="form-control cost-input rounded-0" value="<?php echo $shipment['customs_brokerage'] ?? 0; ?>">
                                                    </div>
                                                </div>
                                                 <div class="col-md-4">
                                                    <label class="form-label small">Port Charges</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-0">$</span>
                                                        <input type="number" step="0.01" name="port_charges" class="form-control cost-input rounded-0" value="<?php echo $shipment['port_charges'] ?? 0; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <h6 class="text-muted mb-3 text-uppercase small ls-1">Local & Other</h6>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small">Local Transport</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-0">$</span>
                                                        <input type="number" step="0.01" name="local_transport" class="form-control cost-input rounded-0" value="<?php echo $shipment['local_transport'] ?? 0; ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Warehousing/Unloading</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-0">$</span>
                                                        <input type="number" step="0.01" name="warehousing_fees" class="form-control cost-input rounded-0" value="<?php echo $shipment['warehousing_fees'] ?? 0; ?>">
                                                    </div>
                                                </div>
                                                 <div class="col-md-4">
                                                    <label class="form-label small">Other Costs</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-0">$</span>
                                                        <input type="number" step="0.01" name="other_costs" class="form-control cost-input rounded-0" value="<?php echo $shipment['other_costs'] ?? 0; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-lg-4">
                                    <div class="card border-0 shadow-sm mb-3" style="border-radius: 0;">
                                        <div class="card-header bg-success text-white" style="border-radius: 0;">Cost Summary</div>
                                        <div class="card-body">
                                            <table class="table table-borderless table-sm mb-0">
                                                <tr>
                                                    <td class="text-muted">Product Cost:</td>
                                                    <td class="text-end fw-bold">$<span id="display_product_cost"><?php echo number_format($shipment['total_value'], 2); ?></span></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Total Additional:</td>
                                                    <td class="text-end fw-bold text-danger">$<span id="display_additional_cost">0.00</span></td>
                                                </tr>
                                                <tr class="border-top">
                                                    <td class="pt-2 fs-5 fw-bold">Landed Cost:</td>
                                                    <td class="pt-2 fs-5 fw-bold text-success text-end">$<span id="display_total_landed">0.00</span></td>
                                                </tr>
                                            </table>
                                            
                                            <div class="mt-3 pt-3 border-top">
                                                <div class="mb-3">
                                                    <label class="form-label small">Allocation Method</label>
                                                    <select name="allocation_method" class="form-select form-select-sm rounded-0">
                                                        <option value="value">By Product Value (recommended)</option>
                                                        <option value="weight">By Product Weight</option>
                                                        <option value="volume">By Product Volume</option>
                                                    </select>
                                                </div>
                                                 <div class="form-check mb-3">
                                                    <input class="form-check-input rounded-0" type="checkbox" name="update_products" id="update_products" value="1">
                                                    <label class="form-check-label small" for="update_products">
                                                        Update 'Buying Price' in Master Product List
                                                    </label>
                                                </div>
                                                <div class="d-grid">
                                                    <button type="submit" class="btn btn-primary btn-sm" style="border-radius: 0;">
                                                        <i class="fas fa-save"></i> Calculate & Save
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card border-0 shadow-sm" style="border-radius: 0;">
                                        <div class="card-body p-2">
                                            <canvas id="costChart" style="height: 150px;"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Add Package Modal -->
<div class="modal fade" id="addPackageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content rounded-0">
            <div class="modal-header">
                <h5 class="modal-title">Add Package</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_package">
                    <input type="hidden" name="shipment_id" value="<?php echo $id; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Package Number/ID</label>
                        <input type="text" name="package_number" class="form-control rounded-0" required placeholder="e.g. PKG-001 or 1/10">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tracking Ref (Optional)</label>
                        <input type="text" name="tracking_number" class="form-control rounded-0">
                    </div>
                    <div class="row g-2">
                         <div class="col-md-6 mb-3">
                            <label class="form-label">Dimensions (LxWxH)</label>
                            <input type="text" name="dimensions" class="form-control rounded-0" placeholder="e.g. 50x50x50 cm">
                        </div>
                         <div class="col-md-3 mb-3">
                            <label class="form-label">Weight (KG)</label>
                            <input type="number" step="0.01" name="weight_kg" class="form-control rounded-0" value="0">
                        </div>
                         <div class="col-md-3 mb-3">
                            <label class="form-label">CBM</label>
                            <input type="number" step="0.001" name="cbm" class="form-control rounded-0" value="0.000">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-0" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-0">Save Package</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('.cost-input');
    const displayAdditional = document.getElementById('display_additional_cost');
    const displayTotal = document.getElementById('display_total_landed');
    const productCost = parseFloat(document.getElementById('display_product_cost').textContent.replace(/,/g, ''));
    
    // Initialize Chart
    const ctx = document.getElementById('costChart');
    let costChart;
    
    function updateTotals() {
        let totalAdditional = 0;
        inputs.forEach(input => {
            totalAdditional += parseFloat(input.value) || 0;
        });
        
        displayAdditional.textContent = totalAdditional.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        const totalLanded = productCost + totalAdditional;
        displayTotal.textContent = totalLanded.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        updateChart(productCost, totalAdditional);
    }
    
    function updateChart(product, additional) {
        if (!ctx) return;
        
        const data = {
            labels: ['Product Value', 'Added Costs'],
            datasets: [{
                data: [product, additional],
                backgroundColor: ['#0d6efd', '#ffc107'],
                borderWidth: 0
            }]
        };
        
        if (costChart) {
            costChart.data = data;
            costChart.update();
        } else {
            costChart = new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
                    }
                }
            });
        }
    }
    
    inputs.forEach(input => {
        input.addEventListener('input', updateTotals);
    });
    
    // Initial Calc
    updateTotals();

    // Tab Deep Linking
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab');
    if (activeTab) {
        const triggerEl = document.querySelector(`button[data-bs-target="#${activeTab}"]`);
        if (triggerEl) {
            const tab = new bootstrap.Tab(triggerEl);
            tab.show();
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
