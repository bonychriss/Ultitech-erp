<?php
// // session_start(); // Removed to avoid conflict with main config.php
require_once 'config/database.php';
require_once 'config/functions.php';
requireLogin();

$active_module = 'stocks';
$page_title = 'Dashboard';
include 'includes/header.php';

$tryScalar = function (array $queries, $default = 0) use ($pdo) {
    foreach ($queries as $sql) {
        try {
            $stmt = $pdo->query($sql);
            if ($stmt !== false) {
                $value = $stmt->fetchColumn();
                return ($value === false || $value === null) ? $default : $value;
            }
        } catch (Throwable $e) {
            // Try next compatible query.
        }
    }
    return $default;
};

// Fetch Statistics (schema-safe for live deployments)
$total_products = (int) $tryScalar([
    "SELECT COUNT(*) FROM products",
    "SELECT COUNT(*) FROM stocks_items",
], 0);

$low_stock = (int) $tryScalar([
    "SELECT COUNT(*) FROM products p JOIN stock s ON p.id = s.product_id WHERE s.quantity <= p.reorder_level",
    "SELECT COUNT(*) FROM products p JOIN stock s ON p.id = s.product_id WHERE s.quantity <= COALESCE(p.reorder_level, 0)",
], 0);

$pending_purchases = (int) $tryScalar([
    "SELECT COUNT(*) FROM stocks_purchase_orders WHERE status = 'Pending'",
    "SELECT COUNT(*) FROM stocks_purchase_orders",
], 0);

$total_suppliers = (int) $tryScalar([
    "SELECT COUNT(*) FROM stocks_suppliers",
    "SELECT COUNT(*) FROM suppliers",
], 0);

// Recent Purchases (schema-safe: item_id may map to stocks_items or products)
$recent_purchases = [];
$recentPurchaseQueries = [
    "SELECT p.*, s.name as supplier_name,
            (SELECT si.name FROM stocks_po_items pi JOIN stocks_items si ON pi.item_id = si.id WHERE pi.po_id = p.id LIMIT 1) as product_name,
            (SELECT COUNT(*) FROM stocks_po_items pi WHERE pi.po_id = p.id) as item_count,
            (SELECT SUM(unit_cost * qty_ordered) FROM stocks_po_items pi WHERE pi.po_id = p.id) as total_amount
     FROM stocks_purchase_orders p
     JOIN stocks_suppliers s ON p.supplier_id = s.id
     ORDER BY p.created_at DESC LIMIT 5",
    "SELECT p.*, s.name as supplier_name,
            (SELECT pr.name FROM stocks_po_items pi JOIN products pr ON pi.item_id = pr.id WHERE pi.po_id = p.id LIMIT 1) as product_name,
            (SELECT COUNT(*) FROM stocks_po_items pi WHERE pi.po_id = p.id) as item_count,
            (SELECT SUM(unit_cost * qty_ordered) FROM stocks_po_items pi WHERE pi.po_id = p.id) as total_amount
     FROM stocks_purchase_orders p
     JOIN stocks_suppliers s ON p.supplier_id = s.id
     ORDER BY p.created_at DESC LIMIT 5",
];
foreach ($recentPurchaseQueries as $sql) {
    try {
        $st = $pdo->query($sql);
        if ($st !== false) {
            $recent_purchases = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            break;
        }
    } catch (Throwable $e) {
        // try next
    }
}

// Low Stock Products
$low_stock_items = [];
try {
    $stmt = $pdo->query("SELECT p.id AS product_id, p.name, p.product_code, p.image AS main_image, s.quantity, p.reorder_level
                         FROM products p
                         JOIN stock s ON p.id = s.product_id
                         WHERE s.quantity <= COALESCE(p.reorder_level, 0)
                         LIMIT 5");
    $low_stock_items = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $low_stock_items = [];
}

// Today's Purchases (Aggregated as TZS)
$today = date('Y-m-d');
$today_purchases_total = 0;
try {
    $stmt = $pdo->prepare("SELECT SUM(pi.unit_cost * pi.qty_ordered)
                           FROM stocks_purchase_orders p
                           JOIN stocks_po_items pi ON p.id = pi.po_id
                           WHERE DATE(p.created_at) = ? AND p.status != 'Cancelled'");
    $stmt->execute([$today]);
    $today_purchases_total = $stmt->fetchColumn() ?: 0;
} catch (Throwable $e) {
    $today_purchases_total = 0;
}

// Stock Accuracy (Dummy for now)
$stock_accuracy = 100;

// In Transit
$in_transit_count = (int) $tryScalar([
    "SELECT COUNT(*) FROM delivery_notes WHERE status IN ('shipped', 'preparing')",
    "SELECT 0",
], 0);

// Outgoing products (last 30 days)
$outgoing_products = (float)$tryScalar([
    "SELECT COALESCE(SUM(soi.quantity), 0)
     FROM sales_order_items soi
     JOIN sales_orders so ON so.id = soi.order_id
     WHERE so.status IN ('confirmed','invoiced','shipped','paid','delivered')
       AND DATE(so.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
    "SELECT COALESCE(SUM(soi.quantity), 0)
     FROM sales_order_items soi
     JOIN sales_orders so ON so.id = soi.order_id
     WHERE DATE(so.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
    "SELECT COALESCE(SUM(quantity), 0) FROM sales_order_items"
], 0);

// Top 3 outgoing products (last 30 days)
$top_outgoing_products = [];
$topOutgoingQueries = [
    "SELECT p.id AS product_id, p.name AS product_name, p.image AS main_image, COALESCE(SUM(soi.quantity),0) AS total_qty
     FROM sales_order_items soi
     JOIN sales_orders so ON so.id = soi.order_id
     JOIN products p ON p.id = soi.product_id
     WHERE so.status IN ('confirmed','invoiced','shipped','paid','delivered')
       AND DATE(so.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY soi.product_id, p.id, p.name, p.image
     ORDER BY total_qty DESC
     LIMIT 3",
    "SELECT p.id AS product_id, p.name AS product_name, p.image AS main_image, COALESCE(SUM(soi.quantity),0) AS total_qty
     FROM sales_order_items soi
     JOIN products p ON p.id = soi.product_id
     GROUP BY soi.product_id, p.id, p.name, p.image
     ORDER BY total_qty DESC
     LIMIT 3"
];
foreach ($topOutgoingQueries as $sql) {
    try {
        $stmtTopOut = $pdo->query($sql);
        if ($stmtTopOut !== false) {
            $rows = $stmtTopOut->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $top_outgoing_products = $rows;
                break;
            }
        }
    } catch (Throwable $e) {
        // Try next compatible query.
    }
}

// Unsold products (no sale in last 30 days)
$unsold_products = (int)$tryScalar([
    "SELECT COUNT(*)
     FROM products p
     WHERE NOT EXISTS (
         SELECT 1
         FROM sales_order_items soi
         JOIN sales_orders so ON so.id = soi.order_id
         WHERE soi.product_id = p.id
           AND so.status IN ('confirmed','invoiced','shipped','paid','delivered')
           AND DATE(so.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     )",
    "SELECT COUNT(*)
     FROM products p
     WHERE NOT EXISTS (
         SELECT 1
         FROM sales_order_items soi
         WHERE soi.product_id = p.id
     )"
], 0);

// One unsold product sample with image
$unsold_sample = null;
$unsoldSampleQueries = [
    "SELECT p.id AS product_id, p.name AS product_name, p.image AS main_image
     FROM products p
     WHERE NOT EXISTS (
         SELECT 1
         FROM sales_order_items soi
         JOIN sales_orders so ON so.id = soi.order_id
         WHERE soi.product_id = p.id
           AND so.status IN ('confirmed','invoiced','shipped','paid','delivered')
           AND DATE(so.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     )
     ORDER BY p.name ASC
     LIMIT 1",
    "SELECT p.id AS product_id, p.name AS product_name, p.image AS main_image
     FROM products p
     WHERE NOT EXISTS (
         SELECT 1
         FROM sales_order_items soi
         WHERE soi.product_id = p.id
     )
     ORDER BY p.name ASC
     LIMIT 1"
];
foreach ($unsoldSampleQueries as $sql) {
    try {
        $stmtUnsold = $pdo->query($sql);
        if ($stmtUnsold !== false) {
            $row = $stmtUnsold->fetch(PDO::FETCH_ASSOC);
            if (!empty($row)) {
                $unsold_sample = $row;
                break;
            }
        }
    } catch (Throwable $e) {
        // Try next compatible query.
    }
}

// Product of the week (best selling by qty in 7 days)
$product_of_week_name = 'No sales this week';
$product_of_week_qty = 0;
$product_of_week_image = '';
$product_of_week_id = 0;
$productWeekQueries = [
    "SELECT p.id AS product_id, p.name AS product_name, p.image AS main_image, COALESCE(SUM(soi.quantity),0) AS total_qty
     FROM sales_order_items soi
     JOIN sales_orders so ON so.id = soi.order_id
     JOIN products p ON p.id = soi.product_id
     WHERE so.status IN ('confirmed','invoiced','shipped','paid','delivered')
       AND DATE(so.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     GROUP BY soi.product_id, p.id, p.name, p.image
     ORDER BY total_qty DESC
     LIMIT 1",
    "SELECT p.id AS product_id, p.name AS product_name, p.image AS main_image, COALESCE(SUM(soi.quantity),0) AS total_qty
     FROM sales_order_items soi
     JOIN products p ON p.id = soi.product_id
     GROUP BY soi.product_id, p.id, p.name, p.image
     ORDER BY total_qty DESC
     LIMIT 1"
];
foreach ($productWeekQueries as $sql) {
    try {
        $stmtPow = $pdo->query($sql);
        if ($stmtPow !== false) {
            $powRow = $stmtPow->fetch(PDO::FETCH_ASSOC);
            if (!empty($powRow['product_name'])) {
                $product_of_week_name = (string)$powRow['product_name'];
                $product_of_week_qty = (float)($powRow['total_qty'] ?? 0);
                $product_of_week_id = (int)($powRow['product_id'] ?? 0);
                $product_of_week_image = (string)($powRow['main_image'] ?? '');
                break;
            }
        }
    } catch (Throwable $e) {
        // Try next compatible query.
    }
}
?>
<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="/assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .mov-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .mov-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .mov-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .dash-card {
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }
    .dash-table-wrapper {
        overflow-x: auto;
        overflow-y: visible !important;
        position: relative;
    }
    .dash-table thead tr.dash-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .dash-table thead tr.dash-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .mini-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.65rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
    }
</style>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="modules/purchases/create.php" class="btn mov-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0 no-underline">
                    <i class="fas fa-plus text-sm"></i> New purchase
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-chart-line text-[#2563EB]"></i><span>Dashboard</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline" title="Stock module home">
                    <i class="fas fa-warehouse text-sm"></i> Stock
                </a>
                <a href="modules/stock/adjust.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-sliders-h text-sm"></i> Adjustment
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base bg-gray-50/80 border-b border-gray-100">
                <span class="text-gray-600"><i class="fas fa-calendar text-gray-400 me-1"></i><?php echo date('l, d M Y'); ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="text-gray-600">Operations overview of purchases, stock risk, and movement.</span>
            </div>
        </div>

        <div class="px-4 pt-4 pb-3">
            <div class="row g-3">
                <div class="col-md-6 col-xl-3">
                    <div class="dash-card p-4 h-100 border-s-4 border-s-blue-600">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Today's purchases</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums">TZS <?php echo number_format((float) $today_purchases_total, 2); ?></div>
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <span class="mini-pill bg-amber-50 text-amber-900 border border-amber-200"><i class="fas fa-clock text-xs"></i> Pending <?php echo number_format((float) $pending_purchases); ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="dash-card p-4 h-100 border-s-4 border-s-rose-600">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Low stock items</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?php echo number_format((float) $low_stock); ?></div>
                        <div class="mt-3">
                            <a href="modules/reports/stock.php" class="text-sm font-semibold text-gray-700 hover:text-[#2563EB] no-underline">
                                Open stock report <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="dash-card p-4 h-100 border-s-4 border-s-emerald-600">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Products / suppliers</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?php echo number_format((float) $total_products); ?></div>
                        <div class="mt-2 text-sm text-gray-600"><?php echo number_format((float) $total_suppliers); ?> suppliers</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="dash-card p-4 h-100 border-s-4 border-s-amber-600">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">In transit</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?php echo number_format((float) $in_transit_count); ?></div>
                        <div class="mt-2 text-sm text-gray-600">Active deliveries</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="px-4 pb-3">
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="dash-card p-4 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Outgoing (30 days)</div>
                                <div class="text-2xl font-bold text-gray-900 tabular-nums"><?php echo number_format((float) $outgoing_products, 0); ?> units</div>
                            </div>
                            <a href="/modules/sales/orders/index.php" class="text-sm font-semibold text-gray-700 hover:text-[#2563EB] no-underline">Sales</a>
                        </div>
                        <div class="mt-3">
                            <div class="text-sm text-gray-600 mb-2">Top 3 products</div>
                            <div class="d-grid gap-2">
                                <?php if (!empty($top_outgoing_products)): ?>
                                    <?php foreach ($top_outgoing_products as $row): ?>
                                        <?php
                                        $outPid = (int)($row['product_id'] ?? 0);
                                        $outName = (string)($row['product_name'] ?? 'Unknown');
                                        $outImage = (string)($row['main_image'] ?? '');
                                        $outQty = (float)($row['total_qty'] ?? 0);
                                        $outImgSrc = resolveProductImageUrl($outPid, $outImage, 'medium');
                                        ?>
                                        <div class="d-flex justify-content-between align-items-center border border-gray-200 rounded-lg px-3 py-2 bg-gray-50/50 gap-2">
                                            <div class="d-flex align-items-center gap-2 min-w-0">
                                                <?php if ($outImgSrc !== ''): ?>
                                                    <img src="<?php echo htmlspecialchars($outImgSrc); ?>"
                                                         alt="<?php echo htmlspecialchars($outName); ?>"
                                                         width="28" height="28"
                                                         class="rounded border bg-white flex-shrink-0"
                                                         style="object-fit:cover;"
                                                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                                                    <span class="rounded border bg-gray-100 text-gray-500 d-none align-items-center justify-content-center flex-shrink-0"
                                                          style="width:28px;height:28px;display:none;">
                                                        <i class="fas fa-box-open" style="font-size: 12px;"></i>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="rounded border bg-gray-100 text-gray-500 d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                                          style="width:28px;height:28px;">
                                                        <i class="fas fa-box-open" style="font-size: 12px;"></i>
                                                    </span>
                                                <?php endif; ?>
                                                <div class="text-sm fw-semibold text-gray-800 text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($outName); ?></div>
                                            </div>
                                            <div class="text-sm text-gray-600 tabular-nums flex-shrink-0"><?php echo number_format($outQty, 0); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-sm text-gray-500">No outgoing products yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="dash-card p-4 h-100">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Product of the week</div>
                        <?php
                        $powImgSrc = resolveProductImageUrl($product_of_week_id, $product_of_week_image, 'medium');
                        ?>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <?php if ($powImgSrc !== ''): ?>
                                <img src="<?php echo htmlspecialchars($powImgSrc); ?>"
                                     alt="<?php echo htmlspecialchars($product_of_week_name); ?>"
                                     width="34" height="34"
                                     class="rounded border bg-white flex-shrink-0"
                                     style="object-fit:cover;"
                                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                                <span class="rounded border bg-gray-100 text-gray-500 d-none align-items-center justify-content-center flex-shrink-0"
                                      style="width:34px;height:34px;display:none;">
                                    <i class="fas fa-box-open" style="font-size: 14px;"></i>
                                </span>
                            <?php else: ?>
                                <span class="rounded border bg-gray-100 text-gray-500 d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                      style="width:34px;height:34px;">
                                    <i class="fas fa-box-open" style="font-size: 14px;"></i>
                                </span>
                            <?php endif; ?>
                            <div class="min-w-0">
                                <div class="text-lg fw-bold text-gray-900 mb-0 text-truncate" style="max-width: 260px;"><?php echo htmlspecialchars($product_of_week_name); ?></div>
                                <div class="text-sm text-gray-600 tabular-nums"><?php echo number_format((float) $product_of_week_qty, 0); ?> units (7 days)</div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <a href="modules/reports/replenishment.php" class="text-sm font-semibold text-gray-700 hover:text-[#2563EB] no-underline">
                                Replenishment report <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="dash-card p-4 h-100">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Unsold products</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?php echo number_format((float) $unsold_products); ?></div>
                        <div class="text-sm text-gray-600">No sales in last 30 days</div>
                        <?php if (!empty($unsold_sample)): ?>
                            <?php
                            $unsoldPid = (int)($unsold_sample['product_id'] ?? 0);
                            $unsoldName = (string)($unsold_sample['product_name'] ?? '');
                            $unsoldImage = (string)($unsold_sample['main_image'] ?? '');
                            $unsoldImgSrc = resolveProductImageUrl($unsoldPid, $unsoldImage, 'medium');
                            ?>
                            <div class="mt-3 border border-gray-200 rounded-lg px-3 py-2 bg-gray-50/50">
                                <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Example</div>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($unsoldImgSrc !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($unsoldImgSrc); ?>"
                                             alt="<?php echo htmlspecialchars($unsoldName); ?>"
                                             width="28" height="28"
                                             class="rounded border bg-white flex-shrink-0"
                                             style="object-fit:cover;"
                                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                                        <span class="rounded border bg-gray-100 text-gray-500 d-none align-items-center justify-content-center flex-shrink-0"
                                              style="width:28px;height:28px;display:none;">
                                            <i class="fas fa-box-open" style="font-size: 12px;"></i>
                                        </span>
                                    <?php else: ?>
                                        <span class="rounded border bg-gray-100 text-gray-500 d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                              style="width:28px;height:28px;">
                                            <i class="fas fa-box-open" style="font-size: 12px;"></i>
                                        </span>
                                    <?php endif; ?>
                                    <div class="text-sm fw-semibold text-gray-800 text-truncate" style="max-width: 240px;"><?php echo htmlspecialchars($unsoldName); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="px-4 pb-4">
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="dash-card">
                        <div class="px-4 py-3 border-b border-gray-100 d-flex justify-content-between align-items-center">
                            <div class="fw-bold text-gray-900">Recent purchases</div>
                            <a href="modules/purchases/index.php" class="text-sm font-semibold text-gray-700 hover:text-[#2563EB] no-underline">View all</a>
                        </div>
                        <div class="dash-table-wrapper">
                            <table class="table table-hover align-middle mb-0 dash-table">
                                <thead>
                                    <tr class="dash-table-head">
                                        <th class="ps-3 py-3">PO #</th>
                                        <th class="py-3">Product</th>
                                        <th class="py-3">Supplier</th>
                                        <th class="py-3 text-center">Status</th>
                                        <th class="py-3 text-end pe-3">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_purchases)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-5">No recent purchases found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_purchases as $rp): ?>
                                            <?php
                                            $status = (string)($rp['status'] ?? '');
                                            $statusTw = match($status) {
                                                'Received' => 'bg-emerald-50 text-emerald-800 border border-emerald-200',
                                                'Cancelled' => 'bg-rose-50 text-rose-800 border border-rose-200',
                                                default => 'bg-amber-50 text-amber-900 border border-amber-200'
                                            };
                                            ?>
                                            <tr class="border-b border-gray-100 hover:bg-gray-50/80">
                                                <td class="ps-3 py-3 fw-bold text-gray-900"><?php echo htmlspecialchars((string)($rp['po_number'] ?? $rp['po_number'] ?? '')); ?></td>
                                                <td class="py-3">
                                                    <div class="fw-semibold text-gray-900"><?php echo htmlspecialchars((string)($rp['product_name'] ?? 'Unknown Product')); ?></div>
                                                    <?php if ((int)($rp['item_count'] ?? 0) > 1): ?>
                                                        <small class="text-gray-500">+<?php echo (int)$rp['item_count'] - 1; ?> more</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3 text-gray-700"><?php echo htmlspecialchars((string)($rp['supplier_name'] ?? '')); ?></td>
                                                <td class="py-3 text-center">
                                                    <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full <?php echo $statusTw; ?>"><?php echo htmlspecialchars($status !== '' ? $status : 'â€”'); ?></span>
                                                </td>
                                                <td class="py-3 text-end pe-3 fw-bold tabular-nums text-gray-900">TZS <?php echo number_format((float)($rp['total_amount'] ?? 0), 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="dash-card">
                        <div class="px-4 py-3 border-b border-gray-100 d-flex justify-content-between align-items-center">
                            <div class="fw-bold text-gray-900">Low stock alerts</div>
                            <a href="modules/reports/stock.php" class="text-sm font-semibold text-gray-700 hover:text-[#2563EB] no-underline">Open report</a>
                        </div>
                        <div class="p-4">
                            <?php if (empty($low_stock_items)): ?>
                                <div class="text-sm text-gray-600">No items below reorder level.</div>
                            <?php else: ?>
                                <div class="d-grid gap-2">
                                    <?php foreach ($low_stock_items as $item): ?>
                                        <?php
                                        $lsPid = (int)($item['product_id'] ?? 0);
                                        $lsName = (string)($item['name'] ?? '');
                                        $lsCode = (string)($item['product_code'] ?? '');
                                        $lsImage = (string)($item['main_image'] ?? '');
                                        $lsImgSrc = resolveProductImageUrl($lsPid, $lsImage, 'medium');
                                        ?>
                                        <div class="d-flex justify-content-between align-items-start gap-2 border border-gray-200 rounded-lg px-3 py-2 bg-gray-50/50">
                                            <div class="d-flex align-items-start gap-2 min-w-0">
                                                <?php if ($lsImgSrc !== ''): ?>
                                                    <img src="<?php echo htmlspecialchars($lsImgSrc); ?>"
                                                         alt="<?php echo htmlspecialchars($lsName); ?>"
                                                         width="28" height="28"
                                                         class="rounded border bg-white flex-shrink-0 mt-0.5"
                                                         style="object-fit:cover;"
                                                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                                                    <span class="rounded border bg-gray-100 text-gray-500 d-none align-items-center justify-content-center flex-shrink-0 mt-0.5"
                                                          style="width:28px;height:28px;display:none;">
                                                        <i class="fas fa-box-open" style="font-size: 12px;"></i>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="rounded border bg-gray-100 text-gray-500 d-inline-flex align-items-center justify-content-center flex-shrink-0 mt-0.5"
                                                          style="width:28px;height:28px;">
                                                        <i class="fas fa-box-open" style="font-size: 12px;"></i>
                                                    </span>
                                                <?php endif; ?>
                                                <div class="min-w-0">
                                                    <div class="fw-semibold text-gray-900 text-truncate" style="max-width: 190px;"><?php echo htmlspecialchars($lsName); ?></div>
                                                    <div class="text-xs text-gray-500 text-truncate" style="max-width: 190px;"><?php echo htmlspecialchars($lsCode); ?></div>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-bold text-rose-700 tabular-nums"><?php echo number_format((float)($item['quantity'] ?? 0), 0); ?></div>
                                                <div class="text-xs text-gray-500">Reorder <?php echo number_format((float)($item['reorder_level'] ?? 0), 0); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
