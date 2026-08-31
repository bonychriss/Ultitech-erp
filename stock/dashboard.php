<?php
// stock/dashboard.php — React stock home (simple desk for new users)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/classes/StockStatistics.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$base = isset($stockBasePath) && $stockBasePath !== ''
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');
if (function_exists('app_url')) {
    $assetBase = rtrim(app_url('/stock'), '/') . '/';
} else {
    $assetBase = preg_replace('#/([A-Za-z0-9-]+)/stock/#', '/stock/', $base) ?: $base;
}
if (strpos($assetBase, '/stock/') === false) {
    $assetBase = $base;
}

$total_products = 0;
$low_stock_count = 0;
$out_of_stock = 0;
$in_stock = 0;
$low_stock_items = [];
$pending_purchases = 0;
$total_suppliers = 0;
$today_purchases_total = 0.0;
$in_transit_count = 0;
$top_sellers = [];
$total_outgoing_units = 0;
$pow = false;
$recent_purchases = [];
$products_growth_pct = null;
$company_display = $_SESSION['company_name'] ?? 'Stock';

try {
    $stats = new StockStatistics($pdo);
    $mainImageSql = function_exists('stock_product_main_image_sql')
        ? stock_product_main_image_sql($pdo, 'p')
        : 'p.main_image';

    $total_products = (int) ($pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() ?: 0);

    $low_stock_count = (int) ($pdo->query(
        "SELECT COUNT(*) FROM products p
         LEFT JOIN stock s ON p.id = s.product_id
         WHERE COALESCE(s.quantity, 0) <= p.reorder_level AND COALESCE(s.quantity, 0) > 0"
    )->fetchColumn() ?: 0);

    $out_of_stock = (int) ($pdo->query(
        "SELECT COUNT(*) FROM products p
         LEFT JOIN stock s ON p.id = s.product_id
         WHERE COALESCE(s.quantity, 0) <= 0"
    )->fetchColumn() ?: 0);

    $in_stock = (int) ($pdo->query(
        "SELECT COUNT(*) FROM products p
         LEFT JOIN stock s ON p.id = s.product_id
         WHERE COALESCE(s.quantity, 0) > p.reorder_level"
    )->fetchColumn() ?: 0);

    $stmtLow = $pdo->query(
        "SELECT p.id, p.name, p.product_code, COALESCE(s.quantity, 0) AS quantity, p.reorder_level,
                ({$mainImageSql}) AS resolved_main_image
         FROM products p
         LEFT JOIN stock s ON p.id = s.product_id
         WHERE COALESCE(s.quantity, 0) <= p.reorder_level
         ORDER BY quantity ASC
         LIMIT 8"
    );
    $low_stock_items = $stmtLow->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pending_purchases = (int) ($pdo->query("SELECT COUNT(*) FROM purchases WHERE status = 'Pending'")->fetchColumn() ?: 0);
    $total_suppliers = (int) ($pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn() ?: 0);

    $today = date('Y-m-d');
    $stmtToday = $pdo->prepare(
        "SELECT SUM(total_amount) FROM purchases
         WHERE DATE(created_at) = ? AND status NOT IN ('Cancelled', 'Draft')"
    );
    $stmtToday->execute([$today]);
    $today_purchases_total = (float) ($stmtToday->fetchColumn() ?: 0);

    try {
        $in_transit_count = (int) ($pdo->query(
            "SELECT COUNT(*) FROM shipments WHERE status IN ('shipped', 'in_transit', 'on_way')"
        )->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $in_transit_count = 0;
    }

    try {
        $top_sellers = $pdo->query(
            "SELECT p.id, p.name, ({$mainImageSql}) AS resolved_main_image,
                    SUM(soi.quantity) AS total_qty
             FROM sales_order_items soi
             JOIN products p ON soi.product_id = p.id
             JOIN sales_orders so ON soi.order_id = so.id
             WHERE so.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY p.id ORDER BY total_qty DESC LIMIT 3"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total_outgoing_units = (int) array_sum(array_column($top_sellers, 'total_qty'));
    } catch (Throwable $e) {
        $top_sellers = [];
        $total_outgoing_units = 0;
    }

    try {
        $pow = $pdo->query(
            "SELECT p.id, p.name, ({$mainImageSql}) AS resolved_main_image,
                    SUM(soi.quantity) AS total_qty
             FROM sales_order_items soi
             JOIN products p ON soi.product_id = p.id
             JOIN sales_orders so ON soi.order_id = so.id
             WHERE so.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY p.id ORDER BY total_qty DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: false;
    } catch (Throwable $e) {
        $pow = false;
    }

    try {
        $mainImageSqlPr = function_exists('stock_product_main_image_sql')
            ? stock_product_main_image_sql($pdo, 'pr')
            : 'pr.main_image';
        $recent_purchases = $pdo->query(
            "SELECT p.id, p.created_at, p.total_amount, p.status,
                    s.name AS supplier_name, pr.id AS product_id, pr.name AS product_name,
                    pr.product_code, ({$mainImageSqlPr}) AS resolved_main_image
             FROM purchases p
             LEFT JOIN suppliers s ON p.supplier_id = s.id
             LEFT JOIN products pr ON p.product_id = pr.id
             ORDER BY p.created_at DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $recent_purchases = [];
    }

    try {
        $endLastMonth = date('Y-m-t', strtotime('last month'));
        $prevTotal = (int) $stats->getCumulativeProductCountAsOf($endLastMonth);
        if ($prevTotal > 0) {
            $products_growth_pct = (int) round((($total_products - $prevTotal) / $prevTotal) * 100);
        }
    } catch (Throwable $e) {
        $products_growth_pct = null;
    }

    if (function_exists('getCompanySettings')) {
        $settings = getCompanySettings($pdo);
        if (!empty($settings['company_name'])) {
            $company_display = $settings['company_name'];
        }
    }
} catch (Throwable $e) {
    error_log('stock dashboard.php: ' . $e->getMessage());
}

$imgUrl = static function ($productId, $filename) use ($base) {
    $productId = (int) $productId;
    $filename = trim((string) $filename);
    if ($productId <= 0 || !function_exists('stock_product_list_image_url')) {
        return '';
    }
    return (string) stock_product_list_image_url($productId, $filename, 'medium', (string) ($GLOBALS['stockBasePath'] ?? $base));
};

$lowStockPayload = [];
foreach ($low_stock_items as $item) {
    $pid = (int) ($item['id'] ?? 0);
    $qty = (float) ($item['quantity'] ?? 0);
    $lowStockPayload[] = [
        'id' => $pid,
        'name' => (string) ($item['name'] ?? ''),
        'product_code' => (string) ($item['product_code'] ?? ''),
        'quantity' => $qty,
        'reorder_level' => (float) ($item['reorder_level'] ?? 0),
        'status' => $qty <= 0 ? 'out' : 'low',
        'image_url' => $imgUrl($pid, $item['resolved_main_image'] ?? ''),
    ];
}

$recentPayload = [];
foreach ($recent_purchases as $rp) {
    $productId = (int) ($rp['product_id'] ?? 0);
    $recentPayload[] = [
        'id' => (int) ($rp['id'] ?? 0),
        'supplier_name' => (string) ($rp['supplier_name'] ?? ''),
        'product_name' => (string) ($rp['product_name'] ?? ''),
        'product_code' => (string) ($rp['product_code'] ?? ''),
        'total_amount' => (float) ($rp['total_amount'] ?? 0),
        'status' => (string) ($rp['status'] ?? ''),
        'created_at' => (string) ($rp['created_at'] ?? ''),
        'image_url' => $productId > 0 ? $imgUrl($productId, $rp['resolved_main_image'] ?? '') : '',
        'product_id' => $productId > 0 ? $productId : null,
    ];
}

/** Recently purchased products (for card-fan UI) */
$recentPurchaseProducts = [];
$seenProductIds = [];
try {
    $purchaseIds = [];
    foreach ($recent_purchases as $rp) {
        $pid = (int) ($rp['id'] ?? 0);
        if ($pid > 0) {
            $purchaseIds[] = $pid;
        }
    }
    $purchaseIds = array_values(array_unique($purchaseIds));
    if ($purchaseIds !== []) {
        $mainImageSqlFan = function_exists('stock_product_main_image_sql')
            ? stock_product_main_image_sql($pdo, 'pr')
            : 'pr.main_image';
        $placeholders = implode(',', array_fill(0, count($purchaseIds), '?'));
        $fanSql = "SELECT pr.id, pr.name, pr.product_code, ({$mainImageSqlFan}) AS resolved_main_image,
                          MAX(p.created_at) AS last_bought, MAX(p.id) AS purchase_id
                   FROM purchase_items pi
                   INNER JOIN products pr ON pr.id = pi.product_id
                   INNER JOIN purchases p ON p.id = pi.purchase_id
                   WHERE pi.purchase_id IN ({$placeholders})
                   GROUP BY pr.id, pr.name, pr.product_code
                   ORDER BY last_bought DESC
                   LIMIT 7";
        $fanStmt = $pdo->prepare($fanSql);
        $fanStmt->execute($purchaseIds);
        foreach ($fanStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $prId = (int) ($row['id'] ?? 0);
            if ($prId <= 0 || isset($seenProductIds[$prId])) {
                continue;
            }
            $seenProductIds[$prId] = true;
            $recentPurchaseProducts[] = [
                'id' => $prId,
                'name' => (string) ($row['name'] ?? ''),
                'product_code' => (string) ($row['product_code'] ?? ''),
                'image_url' => $imgUrl($prId, $row['resolved_main_image'] ?? ''),
                'purchase_id' => (int) ($row['purchase_id'] ?? 0) ?: null,
            ];
        }
    }
} catch (Throwable $e) {
    $recentPurchaseProducts = [];
}
if ($recentPurchaseProducts === []) {
    foreach ($recentPayload as $rp) {
        $prId = (int) ($rp['product_id'] ?? 0);
        if ($prId <= 0 || isset($seenProductIds[$prId])) {
            continue;
        }
        $seenProductIds[$prId] = true;
        $recentPurchaseProducts[] = [
            'id' => $prId,
            'name' => (string) ($rp['product_name'] ?? ''),
            'product_code' => (string) ($rp['product_code'] ?? ''),
            'image_url' => (string) ($rp['image_url'] ?? ''),
            'purchase_id' => (int) ($rp['id'] ?? 0) ?: null,
        ];
        if (count($recentPurchaseProducts) >= 7) {
            break;
        }
    }
}

$sellersPayload = [];
foreach ($top_sellers as $seller) {
    $sid = (int) ($seller['id'] ?? 0);
    $sellersPayload[] = [
        'id' => $sid,
        'name' => (string) ($seller['name'] ?? ''),
        'quantity' => (float) ($seller['total_qty'] ?? 0),
        'image_url' => $imgUrl($sid, $seller['resolved_main_image'] ?? ''),
    ];
}

$powPayload = null;
if (is_array($pow) && !empty($pow['id'])) {
    $powId = (int) $pow['id'];
    $powPayload = [
        'id' => $powId,
        'name' => (string) ($pow['name'] ?? ''),
        'quantity' => (float) ($pow['total_qty'] ?? 0),
        'image_url' => $imgUrl($powId, $pow['resolved_main_image'] ?? ''),
    ];
}

$attentionCount = $low_stock_count + $out_of_stock + $pending_purchases + $in_transit_count;

$page_title = 'Stock';
$employeeHeaderTitle = null;
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk page-stock-dash';

$assetVersion = max(
    (int) (@filemtime(__DIR__ . '/stock-ui/dist/assets/stock-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/stock-ui/dist/assets/stock-ui.css') ?: 0),
    time()
);

include __DIR__ . '/includes/header.php';
?>
<style>
body.page-stock-dash.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-stock-dash.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-stock-dash,
body.page-stock-dash.dashboard,
body.page-stock-dash .layout-main-wrapper,
body.page-stock-dash .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-stock-dash .employee-header.employee-header--products-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
}
body.page-stock-dash .employee-header--products-desk::after { display: none !important; }
body.page-stock-dash .employee-header--products-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.65rem 0 !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem;
}
body.page-stock-dash .employee-header--products-desk .header-right.header-actions-tray {
    margin-left: auto !important;
}
main.main-content.stock-dash-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2.5rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.stock-dash-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    body.page-stock-dash .employee-header.employee-header--products-desk { padding: 0 0.75rem !important; }
    main.main-content.stock-dash-react-root { padding: 0 0.75rem 2rem !important; }
}
html[data-theme="dark"] body.page-stock-dash,
html[data-theme="dark"] body.page-stock-dash.dashboard,
html[data-theme="dark"] body.page-stock-dash .layout-main-wrapper,
html[data-theme="dark"] body.page-stock-dash .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-stock-dash main.main-content.stock-dash-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-stock-dash .employee-header.employee-header--products-desk {
    background: #0f172a !important;
}
</style>

<main class="main-content stock-dash-react-root" role="main">
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'dashboard',
            'data' => [
                'company_name' => (string) $company_display,
                'date_label' => date('l, j M Y'),
                'total_products' => $total_products,
                'in_stock' => $in_stock,
                'low_stock' => $low_stock_count,
                'out_of_stock' => $out_of_stock,
                'attention_count' => $attentionCount,
                'pending_purchases' => $pending_purchases,
                'total_suppliers' => $total_suppliers,
                'today_purchases_total' => $today_purchases_total,
                'in_transit_count' => $in_transit_count,
                'products_growth_pct' => $products_growth_pct,
                'outgoing_units' => $total_outgoing_units,
                'low_stock_items' => $lowStockPayload,
                'recent_purchases' => $recentPayload,
                'recent_purchase_products' => $recentPurchaseProducts,
                'top_sellers' => $sellersPayload,
                'product_of_week' => $powPayload,
                'links' => [
                    'products' => $base . 'modules/products/index.php',
                    'products_low' => $base . 'modules/products/index.php?filter=low_stock',
                    'add_product' => $base . 'modules/products/add.php',
                    'purchases' => $base . 'modules/purchases/index.php',
                    'purchase_create' => $base . 'modules/purchases/domestic_create.php',
                    'suppliers' => $base . 'modules/suppliers/index.php',
                    'shipments' => $base . 'modules/shipments/index.php',
                    'movements' => $base . 'modules/stock/movements.php',
                    'uploads' => $base . 'modules/uploads/index.php',
                    'product_view' => $base . 'modules/products/view.php?id=',
                    'purchase_view' => $base . 'modules/purchases/view_po.php?id=',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"dashboard","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root">
        <div class="dash-desk dash-desk-skeleton" role="status" aria-live="polite" aria-busy="true">
            <span class="sr-only">Loading stock home…</span>
            <div class="dash-desk-bone dash-desk-bone--title"></div>
            <div class="dash-desk-bone dash-desk-bone--sub"></div>
            <div class="dash-desk-skeleton-actions">
                <span class="dash-desk-bone dash-desk-bone--btn"></span>
                <span class="dash-desk-bone dash-desk-bone--btn"></span>
            </div>
        </div>
    </div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
