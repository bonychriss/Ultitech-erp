<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';
requireLogin();
ensure_shipment_po_linking_schema($pdo);

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
                              su.name AS supplier_name, su.contact_person AS supplier_contact, su.phone AS supplier_phone,
                              sh.name AS shipper_name, sh.phone AS shipper_phone, sh.email AS shipper_email, sh.website AS shipper_website,
                              spo.po_number AS linked_po_number
                       FROM shipments s
                       LEFT JOIN stocks_suppliers su ON s.supplier_id = su.id
                       LEFT JOIN shippers sh ON s.shipper_id = sh.id
                       LEFT JOIN stocks_purchase_orders spo ON spo.id = s.stocks_po_id
                       WHERE s.id = ?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment) redirect('index.php');

// Fetch Items
$stmtItems = $pdo->prepare("SELECT si.*, p.name AS product_name, p.product_code,
                            stk.name AS stocks_item_name, stk.sku AS stocks_item_sku
                            FROM shipment_items si
                            LEFT JOIN products p ON si.product_id = p.id
                            LEFT JOIN stocks_items stk ON si.stocks_item_id = stk.id
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

$stView = strtolower((string) ($shipment['status'] ?? ''));
$statusViewMap = [
    'pending' => ['tw' => 'bg-gray-100 text-gray-800 border-gray-200', 'label' => 'Pending'],
    'confirmed' => ['tw' => 'bg-gray-100 text-gray-800 border-gray-200', 'label' => 'Confirmed'],
    'shipped' => ['tw' => 'bg-cyan-50 text-cyan-800 border-cyan-200', 'label' => 'Shipped'],
    'in_transit' => ['tw' => 'bg-blue-50 text-blue-800 border-blue-200', 'label' => 'In transit'],
    'arrived_at_port' => ['tw' => 'bg-amber-50 text-amber-900 border-amber-200', 'label' => 'Port arrival'],
    'in_customs' => ['tw' => 'bg-orange-50 text-orange-900 border-orange-200', 'label' => 'In customs'],
    'ready_for_pickup' => ['tw' => 'bg-teal-50 text-teal-800 border-teal-200', 'label' => 'Ready for pickup'],
    'out_for_delivery' => ['tw' => 'bg-indigo-50 text-indigo-800 border-indigo-200', 'label' => 'Out for delivery'],
    'delivered' => ['tw' => 'bg-emerald-50 text-emerald-800 border-emerald-200', 'label' => 'Delivered'],
    'delayed' => ['tw' => 'bg-red-50 text-red-800 border-red-200', 'label' => 'Delayed'],
    'cancelled' => ['tw' => 'bg-red-50 text-red-800 border-red-200', 'label' => 'Cancelled'],
];
$sv = $statusViewMap[$stView] ?? ['tw' => 'bg-gray-50 text-gray-700 border-gray-200', 'label' => ucwords(str_replace('_', ' ', $stView))];
?>
<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="../../assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .ship-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .ship-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .ship-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .btn.ship-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .ship-form-card-h {
        background-color: #1c2331;
        color: #fff;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 0.65rem 1.25rem;
        border-bottom: 2px solid #151a24;
    }
    .ship-view-table thead tr.ship-view-table-head th {
        background-color: #1c2331 !important;
        color: #fff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        white-space: nowrap;
        padding: 0.65rem 0.5rem !important;
        vertical-align: middle;
    }
    .ship-view-table thead tr.ship-view-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .ship-view-tabs.nav-tabs {
        border-bottom: 1px solid #e5e7eb;
        gap: 0.25rem;
        flex-wrap: nowrap;
    }
    .ship-view-tabs .nav-link {
        color: #6b7280;
        border: 1px solid transparent;
        border-radius: 0.375rem 0.375rem 0 0;
        padding: 0.5rem 0.85rem;
        font-weight: 500;
        white-space: nowrap;
    }
    .ship-view-tabs .nav-link:hover {
        color: #2563EB;
        background: #f9fafb;
    }
    .ship-view-tabs .nav-link.active {
        color: #2563EB !important;
        font-weight: 600;
        background: #fff;
        border-color: #e5e7eb #e5e7eb #fff;
    }
    .timeline-step { width: 100px; position: relative; z-index: 1; text-align: center; }
    .timeline-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; }
    .timeline-bar { position: absolute; top: 20px; left: 0; width: 100%; height: 4px; background: #e5e7eb; z-index: 0; }
    .timeline-icon.ship-tl-done { background-color: #2563EB !important; color: #fff !important; }
    .timeline-icon.ship-tl-future { background-color: #d1d5db !important; color: #fff !important; opacity: 0.45; }
    @media (max-width: 768px) {
        .timeline-step { width: 32% !important; margin: 0; }
        .timeline-icon { width: 28px; height: 28px; font-size: 0.7rem; margin-bottom: 5px; }
        .timeline-bar { top: 14px; }
        .ship-view-tabs .nav-link { font-size: 0.8125rem; padding: 0.45rem 0.65rem; }
        .timeline-label { font-size: 0.6rem !important; }
        .timeline-date { font-size: 0.55rem !important; }
    }
    @media (min-width: 769px) {
        .timeline-container-inner { min-width: 800px; }
    }
</style>

<main class="main-content ship-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Shipments
                </a>
                <?php if ($shipment['status'] !== 'delivered'): ?>
                    <a href="edit.php?id=<?php echo (int) $id; ?>" class="btn ship-btn-primary btn-sm rounded-md px-3 py-2 border-0 d-inline-flex align-items-center gap-2">
                        <i class="fas fa-edit text-sm"></i> Edit
                    </a>
                <?php endif; ?>
                <div class="flex items-center gap-2 min-w-0 flex-wrap">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Shipment</h1>
                    <code class="text-sm bg-gray-100 text-gray-800 px-2 py-0.5 rounded border border-gray-200"><?php echo htmlspecialchars($shipment['invoice_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?></code>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <span class="inline-block px-2.5 py-0.5 text-xs font-bold rounded-full border <?php echo $sv['tw']; ?>"><?php echo htmlspecialchars($sv['label']); ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="inline-flex items-center gap-1.5"><i class="fas fa-truck text-gray-400 text-sm"></i><?php echo htmlspecialchars($shipment['supplier_name'] ?? 'â€”'); ?></span>
                <?php if (!empty($shipment['shipper_name'])): ?>
                    <span class="text-gray-300 hidden sm:inline">|</span>
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-shipping-fast text-gray-400 text-sm"></i><?php echo htmlspecialchars($shipment['shipper_name']); ?></span>
                <?php endif; ?>
                <?php if (!empty($shipment['stocks_po_id'])): ?>
                    <span class="text-gray-300 hidden sm:inline">|</span>
                    <a href="../purchases/view_po.php?id=<?php echo (int) $shipment['stocks_po_id']; ?>" class="inline-flex items-center gap-1.5 text-[#2563EB] fw-semibold text-decoration-none">
                        <i class="fas fa-link text-sm"></i>PO <?php echo htmlspecialchars($shipment['linked_po_number'] ?: '#' . (int) $shipment['stocks_po_id']); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="px-4 pt-4">
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm mb-4 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100">
                <span class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Progress</span>
            </div>
            <div class="card-body overflow-auto p-4 pt-3">
                <div class="d-flex justify-content-between position-relative timeline-container-inner">
                    <div class="timeline-bar"></div>
                    <?php 
                    $passed = true;
                    foreach($timeline as $key => $step): 
                        if ($key == $statusKey) $passed = false; 
                        $isActive = ($key == $statusKey);
                        $isPast = (!$isActive && $passed) || ($key == $statusKey); // Current is also 'past' in terms of coloring
                        
                        // Fix logic: passed becomes false AFTER current
                        
                        $bgClass = $isPast ? 'ship-tl-done' : 'ship-tl-future';
                        if ($key == $statusKey) {
                            $bgClass = 'ship-tl-done';
                        }

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
                        <small class="fw-bold d-block small timeline-label"><?php echo $step['label']; ?></small>
                        <small class="text-muted d-block timeline-date" style="font-size: 0.65rem;"><?php echo $dateDisplay; ?></small>
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

        <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
                 <ul class="nav nav-tabs ship-view-tabs mb-0 px-3 pt-3 flex-nowrap overflow-auto hide-scrollbar bg-gray-50/50 border-bottom border-gray-200">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#details" type="button">Basic info</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#packages" type="button">Packages <span class="badge rounded-pill ms-1" style="background:#e5e7eb;color:#374151;"><?php echo count($packages_list) ?: (int) $shipment['packages_count']; ?></span></button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ecc" type="button">ECC documents</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#shipper" type="button">Shipper</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#landed-cost" type="button">
                            <i class="fas fa-calculator text-gray-400 small"></i> Landed cost
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content p-3 p-lg-4">
                    <!-- BASIC INFO & ITEMS -->
                    <div class="tab-pane fade show active" id="details">
                        <div class="row g-4">
                            <div class="col-md-8">
                                <div class="border border-gray-200 rounded-lg shadow-sm overflow-hidden h-100 bg-white">
                                    <div class="ship-form-card-h"><i class="fas fa-boxes me-2 opacity-80"></i>Shipment content</div>
                                    <div class="p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover align-middle mb-0 ship-view-table" style="font-size: 0.875rem; min-width: 500px;">
                                                <thead>
                                                    <tr class="ship-view-table-head">
                                                        <th class="ps-3">Product</th>
                                                        <th class="text-center">Qty</th>
                                                        <th class="text-end">Unit price</th>
                                                        <th class="text-end pe-3">Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($items as $item): ?>
                                                    <?php
                                                        $lineName = $item['product_name'] ?? $item['stocks_item_name'] ?? 'Line item';
                                                        $lineCode = $item['product_code'] ?? $item['stocks_item_sku'] ?? '';
                                                    ?>
                                                    <tr>
                                                        <td class="ps-3 align-middle">
                                                            <div class="fw-bold"><?php echo htmlspecialchars($lineName); ?></div>
                                                            <?php if ($lineCode !== ''): ?><small class="text-muted"><?php echo htmlspecialchars($lineCode); ?></small><?php endif; ?>
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
                            </div>
                            
                            <div class="col-md-4">
                                 <div class="border border-gray-200 rounded-lg shadow-sm overflow-hidden mb-4 bg-white">
                                    <div class="ship-form-card-h"><i class="fas fa-clipboard-list me-2 opacity-80"></i>Summary</div>
                                    <div class="p-0">
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
                                                <span class="fw-bold text-success tabular-nums"><?php echo htmlspecialchars(shipment_currency_display_prefix($shipment['total_value_currency'] ?? 'USD'), ENT_QUOTES, 'UTF-8'); ?><?php echo number_format((float) ($shipment['total_value'] ?? 0), 2); ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between px-3 py-2">
                                                <span class="text-muted">Packages</span>
                                                <span><?php echo $shipment['packages_count']; ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between px-3 py-2">
                                                <span class="text-muted">CBM</span>
                                                <span><?php echo $shipment['cbm']; ?> mÂ³</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PACKAGES TAB -->
                    <div class="tab-pane fade" id="packages">
                         <div class="border border-gray-200 rounded-lg shadow-sm overflow-hidden bg-white">
                            <div class="p-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                    <h2 class="h5 fw-bold text-gray-900 m-0">Package tracking</h2>
                                    <button type="button" class="btn btn-sm ship-btn-primary rounded-md border-0" data-bs-toggle="modal" data-bs-target="#addPackageModal"><i class="fas fa-plus me-1"></i> Add package</button>
                                </div>
                                <div class="table-responsive border border-gray-200 rounded-md overflow-hidden">
                                    <table class="table table-sm align-middle mb-0 ship-view-table text-nowrap">
                                    <thead>
                                        <tr class="ship-view-table-head">
                                            <th class="ps-3">Package #</th>
                                            <th>Tracking</th>
                                            <th>Dimensions</th>
                                            <th>Weight</th>
                                            <th>CBM</th>
                                            <th class="pe-3">Status</th>
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
                    </div>

                    <!-- ECC DOCUMENTS TAB -->
                    <div class="tab-pane fade" id="ecc">
                         <div class="border border-gray-200 rounded-lg shadow-sm overflow-hidden bg-white">
                            <div class="ship-form-card-h"><i class="fas fa-file-alt me-2 opacity-80"></i>Clearance &amp; documents</div>
                            <div class="p-4">
                                <h3 class="h6 fw-bold text-gray-900 mb-3">Estimated clearance</h3>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg">
                                            <small class="text-gray-500 d-block text-uppercase fw-semibold small mb-1">Est. clearance cost</small>
                                            <strong class="fs-4 text-gray-900 tabular-nums">$<?php echo number_format((float) ($shipment['estimated_clearance_cost'] ?? 0), 2); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-md-8 d-flex align-items-stretch">
                                        <div class="alert alert-primary border-0 rounded-lg mb-0 w-100 d-flex align-items-center" style="background-color: #eff6ff; color: #1e40af;">
                                            <i class="fas fa-info-circle me-2"></i> This is an estimate. Use <strong class="mx-1">Landed cost</strong> when final invoices are available.
                                        </div>
                                    </div>
                                </div>
                                
                                <hr class="border-gray-200 my-4">
                                <h3 class="h6 fw-bold text-gray-900 mb-3">Attached documents</h3>
                                <div class="table-responsive border border-gray-200 rounded-md overflow-hidden">
                                <table class="table table-sm align-middle mb-0 ship-view-table">
                                    <thead><tr class="ship-view-table-head"><th class="ps-3">Type</th><th>Authority</th><th>Dates</th><th>Status</th><th class="pe-3">Actions</th></tr></thead>
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
                    </div>

                    <!-- SHIPPER TAB -->
                    <div class="tab-pane fade" id="shipper">
                        <div class="border border-gray-200 rounded-lg shadow-sm overflow-hidden bg-white">
                            <div class="ship-form-card-h"><i class="fas fa-truck me-2 opacity-80"></i>Shipper details</div>
                            <div class="p-4">
                                <?php if($shipment['shipper_id']): ?>
                                    <h2 class="h5 fw-bold text-gray-900 mb-3"><?php echo htmlspecialchars($shipment['shipper_name'] ?? ''); ?></h2>
                                    <div class="row g-4 mt-1">
                                        <div class="col-md-6">
                                            <table class="table table-borderless table-sm">
                                                <tr><td class="text-gray-500" style="width:7rem;">Service</td><td><strong><?php echo htmlspecialchars(ucfirst($shipment['service_type'] ?? 'Standard')); ?></strong></td></tr>
                                                <tr><td class="text-gray-500">Website</td><td><?php if (!empty($shipment['shipper_website'])): ?><a href="<?php echo htmlspecialchars($shipment['shipper_website']); ?>" target="_blank" rel="noopener" class="text-[#2563EB]"><?php echo htmlspecialchars($shipment['shipper_website']); ?></a><?php else: ?>â€”<?php endif; ?></td></tr>
                                                <tr><td class="text-gray-500">Phone</td><td><?php echo htmlspecialchars($shipment['shipper_phone'] ?? 'â€”'); ?></td></tr>
                                                <tr><td class="text-gray-500">Email</td><td><?php echo htmlspecialchars($shipment['shipper_email'] ?? 'â€”'); ?></td></tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="border border-gray-200 rounded-lg bg-gray-50 p-3">
                                                <h3 class="small text-uppercase text-gray-500 fw-bold mb-3">Performance <span class="fw-normal">(sample)</span></h3>
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span>Reliability</span>
                                                    <span class="badge rounded-pill" style="background:#d1fae5;color:#065f46;">4.8/5.0</span>
                                                </div>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span>On-time rate</span>
                                                    <span class="badge rounded-pill" style="background:#dbeafe;color:#1e40af;">98%</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5 text-gray-500">
                                        <i class="fas fa-shipping-fast fa-3x mb-3 opacity-25"></i>
                                        <p class="mb-0">No shipper linked to this shipment.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- LANDED COST TAB (Existing Logic) -->
                    <div class="tab-pane fade" id="landed-cost">
                        <form action="save_landed_cost.php" method="POST" id="landedCostForm">
                            <input type="hidden" name="shipment_id" value="<?php echo (int) $id; ?>">
                            <div class="row g-4">
                                <div class="col-lg-8">
                                    <div class="border border-gray-200 rounded-lg shadow-sm overflow-hidden mb-4 bg-white">
                                        <div class="ship-form-card-h"><i class="fas fa-dollar-sign me-2 opacity-80"></i>Additional costs</div>
                                        <div class="p-4">
                                            <!-- Existing Cost Fields preserved -->
                                            <h6 class="text-gray-500 mb-3 text-uppercase small fw-bold">Shipping &amp; logistics</h6>
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-gray-700">Freight / shipping</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-md border-gray-300 bg-gray-50">$</span>
                                                        <input type="number" step="0.01" name="shipping_cost" class="form-control cost-input rounded-md border-gray-300" value="<?php echo htmlspecialchars((string) ($shipment['shipping_cost'] ?? 0)); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-gray-700">Insurance</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-md border-gray-300 bg-gray-50">$</span>
                                                        <input type="number" step="0.01" name="insurance_cost" class="form-control cost-input rounded-md border-gray-300" value="<?php echo htmlspecialchars((string) ($shipment['insurance_cost'] ?? 0)); ?>">
                                                    </div>
                                                </div>
                                                 <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-gray-700">Mode</label>
                                                    <select name="shipping_method" class="form-select form-select-sm rounded-md border-gray-300">
                                                        <option value="sea" <?php echo ($shipment['shipping_method'] ?? '') == 'sea' ? 'selected' : ''; ?>>Sea Freight</option>
                                                        <option value="air" <?php echo ($shipment['shipping_method'] ?? '') == 'air' ? 'selected' : ''; ?>>Air Freight</option>
                                                        <option value="road" <?php echo ($shipment['shipping_method'] ?? '') == 'road' ? 'selected' : ''; ?>>Road</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <h6 class="text-gray-500 mb-3 text-uppercase small fw-bold">Customs &amp; duties</h6>
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-gray-700">Customs duty</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-md border-gray-300 bg-gray-50">$</span>
                                                        <input type="number" step="0.01" name="customs_duty" class="form-control cost-input rounded-md border-gray-300" value="<?php echo htmlspecialchars((string) ($shipment['customs_duty'] ?? 0)); ?>">
                                                    </div>
                                                </div>
                                                 <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-gray-700">Brokerage</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-md border-gray-300 bg-gray-50">$</span>
                                                        <input type="number" step="0.01" name="customs_brokerage" class="form-control cost-input rounded-md border-gray-300" value="<?php echo htmlspecialchars((string) ($shipment['customs_brokerage'] ?? 0)); ?>">
                                                    </div>
                                                </div>
                                                 <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-gray-700">Port charges</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-md border-gray-300 bg-gray-50">$</span>
                                                        <input type="number" step="0.01" name="port_charges" class="form-control cost-input rounded-md border-gray-300" value="<?php echo htmlspecialchars((string) ($shipment['port_charges'] ?? 0)); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <h6 class="text-gray-500 mb-3 text-uppercase small fw-bold">Local &amp; other</h6>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-gray-700">Local transport</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-md border-gray-300 bg-gray-50">$</span>
                                                        <input type="number" step="0.01" name="local_transport" class="form-control cost-input rounded-md border-gray-300" value="<?php echo htmlspecialchars((string) ($shipment['local_transport'] ?? 0)); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-gray-700">Warehousing</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-md border-gray-300 bg-gray-50">$</span>
                                                        <input type="number" step="0.01" name="warehousing_fees" class="form-control cost-input rounded-md border-gray-300" value="<?php echo htmlspecialchars((string) ($shipment['warehousing_fees'] ?? 0)); ?>">
                                                    </div>
                                                </div>
                                                 <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-gray-700">Other</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text rounded-md border-gray-300 bg-gray-50">$</span>
                                                        <input type="number" step="0.01" name="other_costs" class="form-control cost-input rounded-md border-gray-300" value="<?php echo htmlspecialchars((string) ($shipment['other_costs'] ?? 0)); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-lg-4">
                                    <div class="border border-gray-200 rounded-lg shadow-sm overflow-hidden mb-3 bg-white">
                                        <div class="ship-form-card-h" style="background:linear-gradient(90deg,#059669,#047857);border-bottom-color:#065f46;"><i class="fas fa-chart-pie me-2 opacity-90"></i>Cost summary</div>
                                        <div class="p-4">
                                            <table class="table table-borderless table-sm mb-0">
                                                <tr>
                                                    <td class="text-gray-600">Product cost</td>
                                                    <td class="text-end fw-bold tabular-nums"><?php echo htmlspecialchars(shipment_currency_display_prefix($shipment['total_value_currency'] ?? 'USD'), ENT_QUOTES, 'UTF-8'); ?><span id="display_product_cost"><?php echo number_format((float) ($shipment['total_value'] ?? 0), 2); ?></span></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-gray-600">Total additional</td>
                                                    <td class="text-end fw-bold text-danger tabular-nums">$<span id="display_additional_cost">0.00</span></td>
                                                </tr>
                                                <tr class="border-top border-gray-200">
                                                    <td class="pt-2 fs-5 fw-bold text-gray-900">Landed cost</td>
                                                    <td class="pt-2 fs-5 fw-bold text-end tabular-nums" style="color:#059669;">$<span id="display_total_landed">0.00</span></td>
                                                </tr>
                                            </table>
                                            
                                            <div class="mt-3 pt-3 border-top border-gray-200">
                                                <div class="mb-3">
                                                    <label class="form-label small fw-semibold text-gray-700">Allocation method</label>
                                                    <select name="allocation_method" class="form-select form-select-sm rounded-md border-gray-300">
                                                        <option value="value">By product value (recommended)</option>
                                                        <option value="weight">By weight</option>
                                                        <option value="volume">By volume</option>
                                                    </select>
                                                </div>
                                                 <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" name="update_products" id="update_products" value="1">
                                                    <label class="form-check-label small text-gray-700" for="update_products">
                                                        Update buying price on master products
                                                    </label>
                                                </div>
                                                <div class="d-grid">
                                                    <button type="submit" class="btn ship-btn-primary btn-sm rounded-md py-2 fw-semibold border-0">
                                                        <i class="fas fa-save me-1"></i> Calculate &amp; save
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="border border-gray-200 rounded-lg shadow-sm bg-white p-3">
                                        <canvas id="costChart" style="height: 150px;"></canvas>
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
<div class="modal fade" id="addPackageModal" tabindex="-1" aria-labelledby="addPackageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-lg border border-gray-200 shadow-lg overflow-hidden">
            <div class="modal-header text-white border-0 py-3" style="background-color: #2563EB;">
                <h5 class="modal-title fw-bold mb-0" id="addPackageModalLabel"><i class="fas fa-box me-2 opacity-90"></i>Add package</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body pt-4">
                    <input type="hidden" name="action" value="add_package">
                    <input type="hidden" name="shipment_id" value="<?php echo (int) $id; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-gray-700" for="pkg_number">Package number / ID <span class="text-danger">*</span></label>
                        <input type="text" name="package_number" id="pkg_number" class="form-control rounded-md border-gray-300" required placeholder="e.g. PKG-001 or 1/10">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-gray-700" for="pkg_track">Tracking reference</label>
                        <input type="text" name="tracking_number" id="pkg_track" class="form-control rounded-md border-gray-300" placeholder="Optional">
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold text-gray-700" for="pkg_dims">Dimensions (LÃ—WÃ—H)</label>
                            <input type="text" name="dimensions" id="pkg_dims" class="form-control rounded-md border-gray-300" placeholder="e.g. 50Ã—50Ã—50 cm">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold text-gray-700" for="pkg_w">Weight (kg)</label>
                            <input type="number" step="0.01" name="weight_kg" id="pkg_w" class="form-control rounded-md border-gray-300" value="0">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold text-gray-700" for="pkg_cbm">CBM</label>
                            <input type="number" step="0.001" name="cbm" id="pkg_cbm" class="form-control rounded-md border-gray-300" value="0.000">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-gray-200 bg-gray-50">
                    <button type="button" class="btn btn-outline-secondary rounded-md" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn ship-btn-primary rounded-md px-4 fw-semibold border-0">Save package</button>
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
                backgroundColor: ['#2563EB', '#f59e0b'],
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
