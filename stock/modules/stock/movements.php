<?php
// stock/modules/stock/movements.php — React stock movements (in/out for all products)
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

// Optional filters (default: all products, all dates, in + out only)
$product_id = trim((string) ($_GET['product_id'] ?? ''));
$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$start_date = trim((string) ($_GET['start_date'] ?? ''));
$end_date = trim((string) ($_GET['end_date'] ?? ''));

if ($type !== '' && !in_array($type, ['in', 'out'], true)) {
    $type = '';
}

$hasProductImages = false;
try {
    $hasProductImages = (bool) $pdo->query("SHOW TABLES LIKE 'product_images'")->fetchColumn();
} catch (Throwable $e) {
    $hasProductImages = false;
}

$mainImageExpr = $hasProductImages
    ? "COALESCE(NULLIF(TRIM(p.main_image), ''), (
            SELECT pi.image_name
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_primary DESC, pi.id ASC
            LIMIT 1
       ))"
    : 'p.main_image';

$query = "SELECT sm.id, sm.product_id, sm.movement_type, sm.quantity, sm.created_at,
                 sm.reference_type, sm.reference_id, sm.notes,
                 p.name AS product_name, p.product_code, c.name AS category_name,
                 {$mainImageExpr} AS resolved_main_image
          FROM stock_movements sm
          JOIN products p ON sm.product_id = p.id
          LEFT JOIN categories c ON p.category_id = c.id
          WHERE LOWER(sm.movement_type) IN ('in', 'out')";
$params = [];

if ($product_id !== '') {
    $query .= ' AND sm.product_id = ?';
    $params[] = (int) $product_id;
}
if ($type !== '') {
    $query .= ' AND LOWER(sm.movement_type) = ?';
    $params[] = $type;
}
if ($start_date !== '') {
    $query .= ' AND DATE(sm.created_at) >= ?';
    $params[] = $start_date;
}
if ($end_date !== '') {
    $query .= ' AND DATE(sm.created_at) <= ?';
    $params[] = $end_date;
}

$query .= ' ORDER BY sm.created_at DESC, sm.id DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$movementsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalIn = 0.0;
$totalOut = 0.0;
$movementsPayload = [];
foreach ($movementsRaw as $m) {
    $mType = strtolower((string) ($m['movement_type'] ?? ''));
    $qty = (float) ($m['quantity'] ?? 0);
    $absQty = abs($qty);
    $productId = isset($m['product_id']) ? (int) $m['product_id'] : 0;
    $filename = trim((string) ($m['resolved_main_image'] ?? ''));
    $imageUrl = ($productId > 0 && function_exists('stock_product_list_image_url'))
        ? (string) stock_product_list_image_url($productId, $filename, 'medium', (string) ($stockBasePath ?? ''))
        : '';

    if ($mType === 'in') {
        $totalIn += $absQty;
    } elseif ($mType === 'out') {
        $totalOut += $absQty;
    }

    $movementsPayload[] = [
        'id' => isset($m['id']) ? (int) $m['id'] : null,
        'product_id' => $productId > 0 ? $productId : null,
        'product_name' => (string) ($m['product_name'] ?? ''),
        'product_code' => (string) ($m['product_code'] ?? ''),
        'category_name' => (string) ($m['category_name'] ?? ''),
        'image_url' => $imageUrl,
        'movement_type' => $mType,
        'quantity' => $qty,
        'reference_type' => (string) ($m['reference_type'] ?? ''),
        'reference_id' => isset($m['reference_id']) && $m['reference_id'] !== '' && $m['reference_id'] !== null
            ? (int) $m['reference_id']
            : null,
        'notes' => (string) ($m['notes'] ?? ''),
        'created_at' => (string) ($m['created_at'] ?? ''),
    ];
}

$productsRaw = $pdo->query('SELECT id, name, product_code FROM products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$productsPayload = [];
foreach ($productsRaw as $p) {
    $productsPayload[] = [
        'id' => (int) ($p['id'] ?? 0),
        'name' => (string) ($p['name'] ?? ''),
        'product_code' => (string) ($p['product_code'] ?? ''),
    ];
}

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

$page_title = 'Stock Movements';
$employeeHeaderTitle = 'Stock Movements';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk';

$assetVersion = max(
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.css') ?: 0),
    time()
);

include '../../includes/header.php';
?>
<style>
body.page-products-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-products-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-products-desk,
body.page-products-desk.dashboard,
body.page-products-desk .layout-main-wrapper,
body.page-products-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-products-desk .employee-header.employee-header--products-desk {
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
    align-items: stretch !important;
}
body.page-products-desk .employee-header--products-desk::after { display: none !important; }
body.page-products-desk .employee-header--products-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-products-desk .employee-header--products-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-products-desk .employee-header--products-desk .employee-header-page-title {
    font-size: clamp(1.05rem, 2vw, 1.35rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: min(42rem, 70vw);
}
body.page-products-desk .employee-header--products-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
main.main-content.products-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.products-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    body.page-products-desk .employee-header.employee-header--products-desk { padding: 0 0.75rem !important; }
    body.page-products-desk .employee-header--products-desk .header-content {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        justify-content: flex-start !important;
    }
    body.page-products-desk .employee-header--products-desk .header-left {
        order: 0 !important;
        flex: 0 0 auto !important;
        margin: 0 !important;
    }
    body.page-products-desk .employee-header--products-desk .employee-header-page-heading {
        order: 1 !important;
        flex: 1 1 auto !important;
        min-width: 0 !important;
        margin-left: 0 !important;
    }
    body.page-products-desk .employee-header--products-desk .header-right.header-actions-tray {
        order: 2 !important;
        margin-left: auto !important;
    }
    main.main-content.products-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-products-desk,
html[data-theme="dark"] body.page-products-desk.dashboard,
html[data-theme="dark"] body.page-products-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-products-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-products-desk main.main-content.products-desk-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-products-desk .employee-header.employee-header--products-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-products-desk .employee-header--products-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>
<main class="main-content products-desk-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to view stock movements.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'stock-movements',
            'data' => [
                'movements' => $movementsPayload,
                'products' => $productsPayload,
                'product_id' => (string) $product_id,
                'type' => (string) $type,
                'start_date' => (string) $start_date,
                'end_date' => (string) $end_date,
                'formAction' => 'movements.php',
                'stats' => [
                    'total_in' => $totalIn,
                    'total_out' => $totalOut,
                    'net' => $totalIn - $totalOut,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"stock-movements","data":{"movements":[]}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
