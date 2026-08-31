<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';
// Workflow helper may be missing on some deployments; don't white-screen.
$wfPath = __DIR__ . '/purchase_workflow.php';
if (is_file($wfPath)) {
    require_once $wfPath;
} else {
    if (!defined('PURCHASE_PROC_STANDARD')) {
        define('PURCHASE_PROC_STANDARD', 'standard');
    }
    if (!function_exists('ensurePurchaseWorkflowSchema')) {
        function ensurePurchaseWorkflowSchema(PDO $pdo): void { /* no-op fallback */ }
    }
}
requireLogin();
// requireRole(['admin', 'procurement']);

/** @var array{title: string, message: string, variant: string}|null $poCreateSuccess */
$poCreateSuccess = null;
if (!empty($_SESSION['stock_po_create_success']) && is_array($_SESSION['stock_po_create_success'])) {
    $poCreateSuccess = $_SESSION['stock_po_create_success'];
    unset($_SESSION['stock_po_create_success']);
}

$hasLegacySuppliersTable = false;
try {
    $hasLegacySuppliersTable = (bool) $pdo->query("SHOW TABLES LIKE 'suppliers'")->fetchColumn();
} catch (Exception $e) {
    $hasLegacySuppliersTable = false;
}

$page_title = 'Purchases';
include '../../includes/header.php';

function tableExists(PDO $pdo, string $table): bool {
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

// Ensure stocks_purchase_orders has purchase_type metadata for filtering.
try {
    $cols = $pdo->query("SHOW COLUMNS FROM stocks_purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('purchase_type', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN purchase_type ENUM('domestic','import') NOT NULL DEFAULT 'domestic' AFTER supplier_id");
    }
    if (!in_array('supplier_invoice_no', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN supplier_invoice_no VARCHAR(50) NULL AFTER purchase_type");
    }
} catch (Exception $e) {}

ensure_shipment_po_linking_schema($pdo);
ensurePurchaseWorkflowSchema($pdo);

// Determine whether header totals/tax columns exist (used for displaying grand total).
$poCols = [];
try {
    $poCols = $pdo->query("SHOW COLUMNS FROM stocks_purchase_orders")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $poCols = [];
}
$hasHeaderTotal = in_array('total_amount', $poCols, true);
$hasHeaderTax = in_array('tax_amount', $poCols, true);

$showDomestic = isset($_GET['domestic']) ? (int)$_GET['domestic'] : 1;
$showImport = isset($_GET['import']) ? (int)$_GET['import'] : 1;
$showDomestic = $showDomestic ? 1 : 0;
$showImport = $showImport ? 1 : 0;

// Normalize: if both off, show both (avoid empty list confusion)
if ($showDomestic === 0 && $showImport === 0) {
    $showDomestic = 1;
    $showImport = 1;
}

// Fetch Purchases using the live stock schema.
$supplierNameExpr = $hasLegacySuppliersTable
    ? "COALESCE(ss.name, ls.name, CONCAT('Supplier #', p.supplier_id))"
    : "COALESCE(ss.name, CONCAT('Supplier #', p.supplier_id))";

$hasPurchaseAttachments = tableExists($pdo, 'stocks_purchase_attachments');
$attachmentCountExpr = $hasPurchaseAttachments
    ? "COALESCE((SELECT COUNT(*) FROM stocks_purchase_attachments pa WHERE pa.purchase_id = p.id), 0)"
    : "0";

$linesTotalExpr = "COALESCE((
            SELECT SUM(COALESCE(pi.qty_ordered, 0) * COALESCE(pi.unit_cost, 0))
            FROM stocks_po_items pi
            WHERE pi.po_id = p.id
        ), 0)";
$taxExpr = $hasHeaderTax ? "COALESCE(p.tax_amount, 0)" : "0";
$grandTotalExpr = $hasHeaderTotal
    ? "COALESCE(p.total_amount, ($linesTotalExpr + $taxExpr))"
    : "($linesTotalExpr + $taxExpr)";

$sql = "SELECT p.*,
        p.po_number as purchase_no,
        $supplierNameExpr as supplier_name,
        $attachmentCountExpr as attachment_count,
        COALESCE((
            SELECT COUNT(*)
            FROM stocks_po_items pi
            WHERE pi.po_id = p.id
        ), 0) as item_count,
        COALESCE((
            SELECT SUM(COALESCE(pi.qty_ordered, 0))
            FROM stocks_po_items pi
            WHERE pi.po_id = p.id
        ), 0) as total_qty,
        COALESCE((
            SELECT GROUP_CONCAT(si.name SEPARATOR ', ')
            FROM stocks_po_items pi
            JOIN stocks_items si ON pi.item_id = si.id
            WHERE pi.po_id = p.id
            LIMIT 1
        ), 'No items') as product_name,
        (
            SELECT COALESCE(si.sku, si.name)
            FROM stocks_po_items pi
            JOIN stocks_items si ON pi.item_id = si.id
            WHERE pi.po_id = p.id
            LIMIT 1
        ) as product_code,
        $grandTotalExpr as total_amount,
        CASE
            WHEN EXISTS (SELECT 1 FROM shipments sh WHERE sh.stocks_po_id = p.id) THEN 1
            WHEN EXISTS (SELECT 1 FROM shipment_items si WHERE si.purchase_id = p.id) THEN 1
            ELSE 0
        END AS has_shipment,
        COALESCE(
            (SELECT sh.id FROM shipments sh WHERE sh.stocks_po_id = p.id ORDER BY sh.id DESC LIMIT 1),
            (SELECT MIN(si.shipment_id) FROM shipment_items si WHERE si.purchase_id = p.id)
        ) AS linked_shipment_id
        FROM stocks_purchase_orders p
        LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id";
$sql .= $hasLegacySuppliersTable ? "\n        LEFT JOIN suppliers ls ON p.supplier_id = ls.id" : "";
$types = [];
if ($showDomestic) $types[] = "'domestic'";
if ($showImport) $types[] = "'import'";
$sql .= "\n        WHERE p.purchase_type IN (" . implode(',', $types) . ")";
$sql .= "
        ORDER BY p.created_at DESC, p.id DESC";
$purchases = $pdo->query($sql)->fetchAll();

// Also fetch legacy purchases if that schema exists (older deployments stored POs in purchases/purchase_items).
$hasLegacyPurchases = tableExists($pdo, 'purchases') && tableExists($pdo, 'purchase_items');
$legacyPurchases = [];
// Legacy purchases are treated as domestic (local) by default.
if ($hasLegacyPurchases && $showDomestic) {
    $legacySupplierNameExpr = $hasLegacySuppliersTable
        ? "COALESCE(ss.name, ls.name, CONCAT('Supplier #', p.supplier_id))"
        : "COALESCE(ss.name, CONCAT('Supplier #', p.supplier_id))";

    $legacySql = "SELECT p.*,
            p.purchase_no as purchase_no,
            $legacySupplierNameExpr as supplier_name,
            0 as attachment_count,
            COALESCE((
                SELECT COUNT(*)
                FROM purchase_items pi
                WHERE pi.purchase_id = p.id
            ), 0) as item_count,
            COALESCE((
                SELECT SUM(COALESCE(pi.quantity, 0))
                FROM purchase_items pi
                WHERE pi.purchase_id = p.id
            ), 0) as total_qty,
            COALESCE((
                SELECT GROUP_CONCAT(pr.name SEPARATOR ', ')
                FROM purchase_items pi
                LEFT JOIN products pr ON pi.product_id = pr.id
                WHERE pi.purchase_id = p.id
                LIMIT 1
            ), 'No items') as product_name,
            (
                SELECT pr.product_code
                FROM purchase_items pi
                LEFT JOIN products pr ON pi.product_id = pr.id
                WHERE pi.purchase_id = p.id
                LIMIT 1
            ) as product_code,
            COALESCE(p.total_amount, (
                SELECT SUM(COALESCE(pi.quantity, 0) * COALESCE(pi.unit_price, 0))
                FROM purchase_items pi
                WHERE pi.purchase_id = p.id
            ), 0) as total_amount,
            1 AS has_shipment,
            NULL AS linked_shipment_id
            FROM purchases p
            LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id";
    $legacySql .= $hasLegacySuppliersTable ? "\n            LEFT JOIN suppliers ls ON p.supplier_id = ls.id" : "";
    $legacySql .= "\n            ORDER BY p.created_at DESC, p.id DESC";

    try {
        $legacyPurchases = $pdo->query($legacySql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $legacyPurchases = [];
    }
}

// Merge both sources and sort by created_at desc.
if (!empty($legacyPurchases)) {
    $purchases = array_merge($purchases, $legacyPurchases);
    usort($purchases, static function(array $a, array $b): int {
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        if ($tb !== $ta) {
            return $tb <=> $ta;
        }
        return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
    });
}

// Fetch Currency
$settings = [];
try {
    $settings = getCompanySettings($pdo);
} catch (Exception $e) {
    $settings = [
        'currency' => 'USD',
        'exchange_rate' => 1
    ];
}
$currency = getCurrencySymbol($settings['currency'] ?? 'USD');
$rate = $settings['exchange_rate'] ?? 1;
?>

<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="/assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .po-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .purchases-table {
        table-layout: fixed;
        width: 100%;
    }
    .purchases-table th,
    .purchases-table td {
        overflow: hidden;
        text-overflow: ellipsis;
        word-wrap: break-word;
    }
    .purchases-table .text-truncate {
        display: inline-block;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .purchases-table-wrapper {
        overflow-x: auto;
        overflow-y: visible !important;
        position: relative;
    }
    .purchases-table-wrapper::-webkit-scrollbar {
        height: 8px;
    }
    .purchases-table-wrapper::-webkit-scrollbar-track {
        background: #f1f5f9;
    }
    .purchases-table-wrapper::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }
    .purchases-table td:last-child {
        position: relative;
        overflow: visible !important;
    }
    .purchases-table .dropdown {
        position: static;
    }
    .purchases-table .dropdown-menu {
        z-index: 1055 !important;
        min-width: 200px;
        max-height: 400px;
        overflow-y: auto;
        font-size: 0.9rem;
        border-radius: 0.5rem;
        border: 1px solid #e5e7eb;
        box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.08);
    }
    .po-btn-primary {
        background-color: #2563EB;
        color: #fff;
        border: 1px solid #2563EB;
    }
    .po-btn-primary:hover {
        background-color: #1D4ED8;
        border-color: #1D4ED8;
        color: #fff;
    }
    div.dataTables_wrapper {
        padding: 0 1rem 1rem;
        background: #fff;
    }
    div.dataTables_wrapper .dataTables_length {
        padding-top: 0.75rem;
        margin-bottom: 0.25rem;
    }
    div.dataTables_wrapper .dataTables_length select {
        border-radius: 0.375rem;
        border: 1px solid #e5e7eb;
        padding: 0.35rem 2rem 0.35rem 0.5rem;
        font-size: 0.95rem;
        background-color: #fff;
    }
    div.dataTables_wrapper .dataTables_info,
    div.dataTables_wrapper .dataTables_paginate {
        padding-top: 0.75rem;
        font-size: 0.95rem;
        color: #6b7280;
    }
    div.dataTables_wrapper .page-link {
        border-radius: 0.375rem;
        margin: 0 2px;
    }
    /* Dark header bar (match voucher-style tables) */
    .purchases-table thead tr.po-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
    }
    .purchases-table thead tr.po-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .purchases-table thead tr.po-table-head th.sorting,
    .purchases-table thead tr.po-table-head th.sorting_asc,
    .purchases-table thead tr.po-table-head th.sorting_desc {
        background-color: #1c2331 !important;
        color: #ffffff !important;
    }

    /* Mobile PO-created success bottom sheet (Dispatch-style) */
    @media (max-width: 767.98px) {
        body.po-create-success-sheet-open {
            overflow: hidden;
            touch-action: none;
        }
    }
    .po-create-success-sheet-backdrop {
        display: none;
    }
    .po-create-success-sheet {
        display: none;
    }
    @media (max-width: 767.98px) {
        .po-create-success-sheet-backdrop {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.48);
            z-index: 1080;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.28s ease, visibility 0.28s ease;
        }
        .po-create-success-sheet-backdrop.is-visible {
            opacity: 1;
            visibility: visible;
        }
        .po-create-success-sheet {
            display: block;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            max-height: min(58vh, 420px);
            background: #fff;
            border-radius: 1.25rem 1.25rem 0 0;
            box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.18);
            z-index: 1090;
            transform: translateY(105%);
            transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
            padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
        }
        .po-create-success-sheet.is-visible {
            transform: translateY(0);
        }
        .po-create-success-sheet-handle {
            width: 40px;
            height: 5px;
            background: #d1d5db;
            border-radius: 999px;
            margin: 12px auto 8px;
            flex-shrink: 0;
        }
    }
</style>
<main class="main-content po-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <?php if ($poCreateSuccess): ?>
            <?php $poCsVariant = ($poCreateSuccess['variant'] ?? 'success') === 'warning' ? 'warning' : 'success'; ?>
            <div class="d-md-none po-create-success-sheet-backdrop" id="poCreateSuccessBackdrop" aria-hidden="true"></div>
            <div class="d-md-none po-create-success-sheet" id="poCreateSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="poCreateSuccessSheetTitle">
                <div class="po-create-success-sheet-handle" aria-hidden="true"></div>
                <div class="px-4 pb-4 pt-0 text-center">
                    <?php if ($poCsVariant === 'warning'): ?>
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning bg-opacity-15 text-warning mb-3" style="width: 56px; height: 56px;">
                            <i class="fas fa-exclamation-triangle fa-lg"></i>
                        </div>
                    <?php else: ?>
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 56px; height: 56px;">
                            <i class="fas fa-check fa-lg"></i>
                        </div>
                    <?php endif; ?>
                    <h2 id="poCreateSuccessSheetTitle" class="h5 fw-bold text-dark mb-2"><?php echo htmlspecialchars($poCreateSuccess['title'] ?? 'Success'); ?></h2>
                    <p class="text-secondary mb-4 small"><?php echo htmlspecialchars($poCreateSuccess['message'] ?? ''); ?></p>
                    <a href="index.php" class="btn po-btn-primary w-100 py-2 rounded-pill fw-semibold border-0 d-inline-flex align-items-center justify-content-center" id="poCreateSuccessDismiss">
                        View purchase orders
                    </a>
                </div>
            </div>
        <?php endif; ?>
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="create.php" class="po-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-plus text-sm"></i> New purchase
                </a>
                <a href="domestic_create.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline" title="New domestic purchase">
                    <i class="fas fa-truck-loading text-sm"></i> Domestic
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Purchase orders</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <div class="inline-flex rounded-lg border border-gray-200 bg-gray-100 p-0.5 gap-0.5" role="group" aria-label="Purchase type filter">
                    <button type="button" class="px-3 py-2 rounded-md text-sm font-semibold transition-colors <?php echo $showDomestic ? 'active bg-white text-gray-900 shadow-sm border border-gray-200' : 'text-gray-500 hover:text-gray-800 hover:bg-gray-50 border border-transparent'; ?>" id="toggleDomestic" aria-pressed="<?php echo $showDomestic ? 'true' : 'false'; ?>">
                        Domestic
                    </button>
                    <button type="button" class="px-3 py-2 rounded-md text-sm font-semibold transition-colors <?php echo $showImport ? 'active bg-white text-gray-900 shadow-sm border border-gray-200' : 'text-gray-500 hover:text-gray-800 hover:bg-gray-50 border border-transparent'; ?>" id="toggleImport" aria-pressed="<?php echo $showImport ? 'true' : 'false'; ?>">
                        Import
                    </button>
                </div>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-3 bg-gray-50/80 border-b border-gray-100">
                <div class="relative flex-1 min-w-[200px] max-w-xl">
                    <label for="purchaseSearchInput" class="visually-hidden">Search purchase orders</label>
                    <i class="fas fa-filter absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                    <input type="search" id="purchaseSearchInput" class="w-full pl-9 pr-3 py-2 text-base bg-white border border-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 focus:border-[#2563EB]" placeholder="Search PO, product, supplier, status, typeâ€¦" autocomplete="off">
                </div>
            </div>
        </div>

        <div class="bg-white border-t border-gray-200">
            <div class="purchases-table-wrapper">
                <table class="table table-hover mb-0 datatable purchases-table border-0" style="font-size: 1rem;">
                            <thead>
                                <tr class="po-table-head">
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide" style="width: 12%; min-width: 120px;">PO number</th>
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide" style="width: 25%; min-width: 200px;">Product</th>
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide" style="width: 18%; min-width: 150px;">Supplier</th>
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide text-center" style="width: 8%; min-width: 90px;">Type</th>
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide text-center" style="width: 8%; min-width: 70px;">Qty</th>
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide text-end" style="width: 12%; min-width: 100px;">Total</th>
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide text-center" style="width: 12%; min-width: 110px;">Status</th>
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide" style="width: 10%; min-width: 100px;">Date</th>
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide text-center" style="width: 6%; min-width: 90px;">Docs</th>
                                    <th class="px-3 py-2.5 text-sm uppercase tracking-wide text-center pe-3" style="width: 3%; min-width: 50px;"><i class="fas fa-sliders-h text-white/70" title="Actions"></i></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($purchases as $po): ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50/80">
                                    <td class="px-3 py-2.5 fw-bold align-middle text-base text-gray-900">
                                        <span class="text-truncate d-inline-block" style="max-width: 100%;" title="<?php echo htmlspecialchars($po['purchase_no']); ?>">
                                            <?php echo htmlspecialchars($po['purchase_no']); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5 align-middle text-base text-gray-800">
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="text-truncate flex-grow-1 fw-medium" style="max-width: 100%;" title="<?php echo htmlspecialchars($po['product_name']); ?>">
                                                <?php echo htmlspecialchars($po['product_name']); ?>
                                            </span>
                                            <?php if($po['item_count'] > 1): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-pill text-xs font-semibold bg-gray-200 text-gray-700 flex-shrink-0">+<?php echo ($po['item_count'] - 1); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if(!empty($po['product_code'])): ?>
                                            <small class="text-gray-500 d-block text-truncate text-sm" style="max-width: 100%;" title="<?php echo htmlspecialchars($po['product_code']); ?>">
                                                <?php echo htmlspecialchars($po['product_code']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2.5 align-middle text-base text-gray-700">
                                        <span class="text-truncate d-inline-block" style="max-width: 100%;" title="<?php echo htmlspecialchars($po['supplier_name']); ?>">
                                            <?php echo htmlspecialchars($po['supplier_name']); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5 text-center align-middle">
                                        <?php
                                            $poType = $po['purchase_type'] ?? 'domestic';
                                            $poType = in_array($poType, ['domestic','import'], true) ? $poType : 'domestic';
                                            $typeLabel = $poType === 'import' ? 'Outdoor' : 'Domestic';
                                            $typeTw = $poType === 'import'
                                                ? 'bg-blue-50 text-blue-700 border border-blue-200'
                                                : 'bg-gray-100 text-gray-800 border border-gray-200';
                                        ?>
                                        <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full <?php echo $typeTw; ?> whitespace-nowrap">
                                            <?php echo htmlspecialchars($typeLabel); ?>
                                        </span>
                                        <?php if ($poType === 'import' && (int) ($po['has_shipment'] ?? 0) !== 1 && !in_array(($po['status'] ?? ''), ['Received', 'Cancelled'], true)): ?>
                                            <span class="d-block mt-1 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5 fw-semibold" title="Create a shipment before receiving">Shipment required</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2.5 text-center align-middle text-base text-gray-700 tabular-nums"><?php echo number_format($po['total_qty'], 2); ?></td>
                                    <td class="px-3 py-2.5 text-end align-middle fw-bold text-base text-gray-900 tabular-nums"><?php echo $currency; ?><?php echo number_format(convertCurrency($po['total_amount'], $rate), 2); ?></td>
                                    <td class="px-3 py-2.5 text-center align-middle">
                                        <?php
                                            $status_tw = match($po['status']) {
                                                'Draft' => 'bg-gray-500 text-white',
                                                'Pending Supplier' => 'bg-amber-500 text-white',
                                                'Pending Approval' => 'bg-cyan-600 text-white',
                                                'Pending' => 'bg-[#FFC107] text-gray-900',
                                                'Approved' => 'bg-[#17A2B8] text-white',
                                                'Supplier Responded' => 'bg-[#2563EB] text-white',
                                                'Negotiation Requested' => 'bg-[#FD7E14] text-white',
                                                'Received' => 'bg-[#28A745] text-white',
                                                'Cancelled' => 'bg-[#DC3545] text-white',
                                                default => 'bg-gray-500 text-white'
                                            };
                                            $statusLabel = purchaseDisplayStatusLabel((string) ($po['status'] ?? ''), $po['procurement_workflow'] ?? null);
                                        ?>
                                        <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full <?php echo $status_tw; ?> whitespace-nowrap" title="<?php echo htmlspecialchars((string) ($po['status'] ?? '')); ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                    </td>
                                    <?php
                                        $createdTs = strtotime((string) ($po['created_at'] ?? '')) ?: 0;
                                        $createdLabel = $createdTs ? date('M d, Y', $createdTs) : 'â€”';
                                    ?>
                                    <td class="px-3 py-2.5 align-middle text-base text-gray-600 whitespace-nowrap" data-order="<?php echo (int) $createdTs; ?>">
                                        <?php echo htmlspecialchars($createdLabel); ?>
                                    </td>
                                    <td class="px-3 py-2.5 text-center align-middle">
                                        <?php $attCount = (int) ($po['attachment_count'] ?? 0); ?>
                                        <?php if (!$hasPurchaseAttachments): ?>
                                            <span class="text-gray-300" title="Attachments feature not installed on this server">â€”</span>
                                        <?php elseif ($attCount > 0): ?>
                                            <a class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-sm font-semibold bg-blue-50 text-blue-700 border border-blue-200 no-underline"
                                               href="open_attachment.php?purchase_id=<?php echo (int) $po['id']; ?>"
                                               target="_blank"
                                               title="<?php echo $attCount; ?> attachment<?php echo $attCount === 1 ? '' : 's'; ?> (open document)">
                                                <i class="fas fa-paperclip text-xs"></i>
                                                <?php echo $attCount; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-gray-300" title="No documents attached">â€”</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2.5 text-center align-middle pe-3">

<div class="dropdown">
                                            <button class="btn btn-sm text-gray-400 border-0 bg-transparent btn-ellipsis shadow-none dropdown-toggle py-1" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow" style="font-size: 0.85rem;">
                                                <li><a class="dropdown-item" href="view_po.php?id=<?php echo $po['id']; ?>"><i class="fas fa-file-invoice me-2 text-secondary"></i> View PO</a></li>
                                                
                                                <?php
                                                    $rowPoType = $po['purchase_type'] ?? 'domestic';
                                                    $rowPoType = in_array($rowPoType, ['domestic', 'import'], true) ? $rowPoType : 'domestic';
                                                    $hasShipment = (int) ($po['has_shipment'] ?? 0) === 1;
                                                    $rowWf = $po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD;
                                                    $canReceive = !in_array(($po['status'] ?? ''), ['Received', 'Cancelled'], true)
                                                        && !in_array(($po['status'] ?? ''), purchaseStatusesBlockingReceive(), true)
                                                        && ($rowPoType === 'domestic' || ($rowPoType === 'import' && $hasShipment));
                                                ?>
                                                <?php if ($canReceive): ?>
                                                    <li><a class="dropdown-item" href="domestic_receive.php?id=<?php echo $po['id']; ?>"><i class="fas fa-check me-2 text-success"></i> Receive stock</a></li>
                                                <?php elseif ($rowPoType === 'import' && !in_array(($po['status'] ?? ''), ['Received', 'Cancelled'], true) && !$hasShipment): ?>
                                                    <li><span class="dropdown-item text-muted disabled pe-none"><i class="fas fa-check me-2"></i> Receive stock <small>(create shipment first)</small></span></li>
                                                <?php endif; ?>
                                                
                                                <?php if (in_array($po['status'], purchaseOrderEditableStatuses($rowWf), true)): ?>
                                                    <li><a class="dropdown-item" href="edit.php?id=<?php echo $po['id']; ?>"><i class="fas fa-edit me-2 text-primary"></i> Edit Price/Qty</a></li>
                                                <?php endif; ?>
                                                <?php
                                                    $mayApprove = in_array($po['status'], purchaseAwaitingApprovalStatuses(), true)
                                                        || (($po['status'] ?? '') === PURCHASE_STATUS_PENDING && !isSupplierLinkWorkflow($rowWf));
                                                    $mayApprove = $mayApprove && !in_array(($po['status'] ?? ''), [PURCHASE_STATUS_DRAFT, PURCHASE_STATUS_PENDING_SUPPLIER], true);
                                                    // Standard workflow: PO is already pending immediately; hide Approve in dropdown.
                                                    $mayApprove = $mayApprove && isSupplierLinkWorkflow($rowWf);
                                                ?>
                                                <?php if ($mayApprove): ?>
                                                    <li><a class="dropdown-item" href="approve.php?id=<?php echo $po['id']; ?>"><i class="fas fa-thumbs-up me-2 text-success"></i> Approve</a></li>
                                                <?php endif; ?>

                                                <?php if(in_array($po['status'], ['Pending', 'Approved', 'Supplier Responded', 'Pending Approval', 'Negotiation Requested', 'Draft', 'Pending Supplier'])): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>

                                                <?php
                                                    $poTypeMenu = $po['purchase_type'] ?? 'domestic';
                                                    $poTypeMenu = in_array($poTypeMenu, ['domestic', 'import'], true) ? $poTypeMenu : 'domestic';
                                                    $showOutdoorShipment = $poTypeMenu === 'import'
                                                        && !in_array(($po['status'] ?? ''), ['Received', 'Cancelled'], true);
                                                ?>
                                                <?php if ($showOutdoorShipment): ?>
                                                    <?php if (!in_array($po['status'], ['Pending', 'Approved', 'Supplier Responded', 'Pending Approval', 'Negotiation Requested', 'Draft', 'Pending Supplier'], true)): ?>
                                                        <li><hr class="dropdown-divider"></li>
                                                    <?php endif; ?>
                                                    <?php
                                                        $impHasShip = (int) ($po['has_shipment'] ?? 0) === 1;
                                                        $shipLabel = $impHasShip ? 'View linked shipment' : 'Create shipment (required for receiving)';
                                                    ?>
                                                    <?php if ($impHasShip && !empty($po['linked_shipment_id'])): ?>
                                                        <li><a class="dropdown-item" href="/stock/modules/shipments/view.php?id=<?php echo (int) $po['linked_shipment_id']; ?>"><i class="fas fa-link me-2 text-primary"></i> <?php echo htmlspecialchars($shipLabel); ?></a></li>
                                                    <?php else: ?>
                                                        <li><a class="dropdown-item fw-semibold" href="/stock/modules/shipments/create.php?purchase_id=<?php echo (int) $po['id']; ?>"><i class="fas fa-shipping-fast me-2 text-primary"></i> <?php echo htmlspecialchars($shipLabel); ?></a></li>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item" href="create.php?clone_from_id=<?php echo $po['id']; ?>"><i class="fas fa-share-square me-2 text-dark"></i> Clone Order</a></li>

                                                <?php if (in_array($po['status'], purchaseCancelableStatuses($rowWf), true)): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item text-danger" href="cancel.php?id=<?php echo $po['id']; ?>" onclick="return confirm('Cancel this order?');"><i class="fas fa-times me-2"></i> Cancel Order</a></li>
                                                <?php endif; ?>

                                                <?php if(hasRole('admin')): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item text-danger js-delete-po" href="delete.php?id=<?php echo $po['id']; ?>"><i class="fas fa-trash-alt me-2"></i> Delete Order</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
            </div>
        </div>
    </div>
</main>

<script>
<?php
$swalMsg = '';
$swalType = 'success';
if (isset($_SESSION['success'])) {
    $swalMsg = $_SESSION['success'];
    $swalType = $_SESSION['success_type'] ?? 'success';
    unset($_SESSION['success']);
    unset($_SESSION['success_type']);
}
?>

document.addEventListener('DOMContentLoaded', function() {
    const msg = "<?php echo addslashes($swalMsg); ?>";
    const type = "<?php echo $swalType; ?>";
    
    if (msg) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: type === 'error' ? 'error' : 'success',
            title: msg,
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            heightAuto: false,
            scrollbarPadding: false
        });
    }
    
        // Initialize DataTable with fixed layout to prevent horizontal scrolling
        if ($.fn.DataTable.isDataTable('.purchases-table')) {
            $('.purchases-table').DataTable().destroy();
        }
        var table = $('.purchases-table').DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            stripeClasses: [],
            // Columns: 0 PO, 1 Product, 2 Supplier, 3 Type, 4 Qty, 5 Total, 6 Status, 7 Date, 8 Docs, 9 Actions
            order: [[7, 'desc']],
            autoWidth: false,
            scrollX: false,
            // Custom search in sticky bar; built-in filter omitted
            dom: 'lrtip',
            columnDefs: [
                { orderable: true, targets: [0, 1, 2, 3, 4, 5, 6, 7, 8] },
                { orderable: false, targets: [9] }
            ],
            language: {
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries to show",
                infoFiltered: "(filtered from _MAX_ total entries)"
            },
            drawCallback: function() {
                // Reinitialize dropdowns after DataTable redraws
                initializeDropdowns();
            }
        });

        var searchInput = document.getElementById('purchaseSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                table.search(this.value).draw();
            });
            searchInput.addEventListener('search', function() {
                table.search(this.value).draw();
            });
        }
        
        // Initialize dropdowns function
        function initializeDropdowns() {
            var dropdownElementList = [].slice.call(document.querySelectorAll('.purchases-table .dropdown-toggle'));
            dropdownElementList.forEach(function (dropdownToggleEl) {
                // Destroy existing dropdown if any
                var existingDropdown = bootstrap.Dropdown.getInstance(dropdownToggleEl);
                if (existingDropdown) {
                    existingDropdown.dispose();
                }
                
                var dropdown = new bootstrap.Dropdown(dropdownToggleEl, {
                    boundary: document.body,
                    popperConfig: {
                        modifiers: [
                            {
                                name: 'preventOverflow',
                                options: {
                                    boundary: document.body
                                }
                            },
                            {
                                name: 'flip',
                                options: {
                                    boundary: document.body
                                }
                            }
                        ]
                    }
                });
                
                // Ensure dropdown menu appears fully on show
                dropdownToggleEl.addEventListener('shown.bs.dropdown', function() {
                    var menu = this.nextElementSibling;
                    if (menu && menu.classList.contains('dropdown-menu')) {
                        menu.style.zIndex = '1055';
                    }
                });
            });
        }
        
        // Initial dropdown initialization
        initializeDropdowns();

        // Purchase type switches (Domestic/Import)
        const dom = document.getElementById('toggleDomestic');
        const imp = document.getElementById('toggleImport');
        const updateFilters = () => {
            const url = new URL(window.location.href);
            const isDomActive = dom && dom.classList.contains('active');
            const isImpActive = imp && imp.classList.contains('active');
            url.searchParams.set('domestic', isDomActive ? '1' : '0');
            url.searchParams.set('import', isImpActive ? '1' : '0');
            url.searchParams.delete('type'); // legacy param (if present)
            window.location.href = url.toString();
        };
        const toggleBtn = (btn) => {
            if (!btn) return;
            btn.classList.toggle('active');
            btn.setAttribute('aria-pressed', btn.classList.contains('active') ? 'true' : 'false');

            // If both are off, revert both on to avoid empty list confusion.
            const domOn = dom && dom.classList.contains('active');
            const impOn = imp && imp.classList.contains('active');
            if (!domOn && !impOn) {
                if (dom) { dom.classList.add('active'); dom.setAttribute('aria-pressed', 'true'); }
                if (imp) { imp.classList.add('active'); imp.setAttribute('aria-pressed', 'true'); }
            }
            updateFilters();
        };
        if (dom) dom.addEventListener('click', () => toggleBtn(dom));
        if (imp) imp.addEventListener('click', () => toggleBtn(imp));

        // Confirm destructive delete with SweetAlert (instead of browser confirm).
        document.querySelectorAll('a.js-delete-po').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const href = link.getAttribute('href') || '';
                if (!href) return;

                Swal.fire({
                    icon: 'warning',
                    title: 'Permanently delete purchase order?',
                    html: 'Are you sure you want to <b>PERMANENTLY DELETE</b> this Purchase Order?<br>This action cannot be undone.',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#DC3545',
                    cancelButtonColor: '#6C757D',
                    reverseButtons: true,
                    heightAuto: false,
                    scrollbarPadding: false
                }).then(function (result) {
                    if (result.isConfirmed) {
                        window.location.href = href;
                    }
                });
            });
        });
});
</script>

<?php if ($poCreateSuccess): ?>
<script>
(function () {
    var sheet = document.getElementById('poCreateSuccessSheet');
    var backdrop = document.getElementById('poCreateSuccessBackdrop');
    var btn = document.getElementById('poCreateSuccessDismiss');
    if (!sheet || !backdrop) return;

    var mq = window.matchMedia('(max-width: 767.98px)');
    var autoTimer;
    var listHref = 'index.php';

    function goList() {
        window.location.href = listHref;
    }

    function openSheet() {
        if (!mq.matches) return;
        sheet.setAttribute('aria-hidden', 'false');
        document.body.classList.add('po-create-success-sheet-open');
        requestAnimationFrame(function () {
            backdrop.classList.add('is-visible');
            sheet.classList.add('is-visible');
        });
        window.clearTimeout(autoTimer);
        autoTimer = window.setTimeout(function () {
            closeSheet(true);
        }, 6000);
    }

    function closeSheet(fromTimer) {
        window.clearTimeout(autoTimer);
        backdrop.classList.remove('is-visible');
        sheet.classList.remove('is-visible');
        document.body.classList.remove('po-create-success-sheet-open');
        window.setTimeout(function () {
            if (!sheet.classList.contains('is-visible')) {
                sheet.setAttribute('aria-hidden', 'true');
            }
            if (fromTimer) goList();
        }, 350);
    }

    function init() {
        if (mq.matches) openSheet();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    mq.addEventListener('change', function (e) {
        if (!e.matches) {
            backdrop.classList.remove('is-visible');
            sheet.classList.remove('is-visible');
            document.body.classList.remove('po-create-success-sheet-open');
        }
    });

    if (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            window.clearTimeout(autoTimer);
            backdrop.classList.remove('is-visible');
            sheet.classList.remove('is-visible');
            document.body.classList.remove('po-create-success-sheet-open');
            goList();
        });
    }
    backdrop.addEventListener('click', function () {
        closeSheet(true);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('is-visible')) {
            closeSheet(true);
        }
    });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Swal === 'undefined') return;
    if (!window.matchMedia('(min-width: 768px)').matches) return;
    var d = <?php echo json_encode($poCreateSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    if (!d) return;
    Swal.fire({
        title: d.title || 'Success',
        text: d.message || '',
        icon: d.variant === 'warning' ? 'warning' : 'success',
        confirmButtonColor: '#2563EB',
        confirmButtonText: 'OK'
    }).then(function () {
        window.location.href = 'index.php';
    });
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
