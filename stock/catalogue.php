<?php
/**
 * Product catalogue — React desk.
 * URL: /stock/catalogue.php
 *      /{slug}/stock/catalogue.php
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/paths.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$showCost = in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true);

$categories = [];
try {
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $categories = [];
}

$whereClause = 'WHERE 1=1';
$params = [];
$filterSearch = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$filterCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';

$hasProductImages = false;
$hasStockTable = false;
$hasBrandCol = false;
try {
    $pdo->query('SELECT image_name FROM product_images LIMIT 1');
    $hasProductImages = true;
} catch (Throwable $e) {
}
try {
    $pdo->query('SELECT quantity FROM stock LIMIT 1');
    $hasStockTable = true;
} catch (Throwable $e) {
}
try {
    $pdo->query('SELECT brand FROM products LIMIT 1');
    $hasBrandCol = true;
} catch (Throwable $e) {
}

if ($filterSearch !== '') {
    if ($hasBrandCol) {
        $whereClause .= ' AND (p.name LIKE ? OR p.product_code LIKE ? OR IFNULL(p.brand, \'\') LIKE ?)';
        $like = '%' . $filterSearch . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    } else {
        $whereClause .= ' AND (p.name LIKE ? OR p.product_code LIKE ?)';
        $like = '%' . $filterSearch . '%';
        $params[] = $like;
        $params[] = $like;
    }
}
if ($filterCategory !== '') {
    $whereClause .= ' AND p.category_id = ?';
    $params[] = $filterCategory;
}

$imageSelect = $hasProductImages
    ? "COALESCE(NULLIF(TRIM(p.main_image), ''), (
            SELECT pi.image_name
            FROM product_images pi
            WHERE pi.product_id = p.id
              AND TRIM(IFNULL(pi.image_name, '')) <> ''
            ORDER BY pi.is_primary DESC, pi.id ASC
            LIMIT 1
       )) AS resolved_image"
    : 'p.main_image AS resolved_image';

$qtySelect = $hasStockTable ? 'COALESCE(s.quantity, 0) AS quantity' : '0 AS quantity';
$brandSelect = $hasBrandCol ? 'p.brand' : "'' AS brand";
$stockJoin = $hasStockTable ? 'LEFT JOIN stock s ON p.id = s.product_id' : '';

$sql = "SELECT p.id, p.product_code, p.name, p.unit_price, p.buying_price, p.currency,
               {$brandSelect}, p.category_id, p.main_image,
               c.name AS category_name, sup.name AS supplier_name,
               {$qtySelect},
               {$imageSelect}
        FROM products p
        {$stockJoin}
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers sup ON p.supplier_id = sup.id
        {$whereClause}
        ORDER BY p.name ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('catalogue.php query: ' . $e->getMessage());
    $sql = "SELECT p.id, p.product_code, p.name, p.unit_price, p.buying_price, p.currency,
                   p.category_id, p.main_image, 0 AS quantity,
                   c.name AS category_name, '' AS supplier_name, '' AS brand,
                   p.main_image AS resolved_image
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE 1=1";
    $fallbackParams = [];
    if ($filterSearch !== '') {
        $sql .= ' AND (p.name LIKE ? OR p.product_code LIKE ?)';
        $like = '%' . $filterSearch . '%';
        $fallbackParams[] = $like;
        $fallbackParams[] = $like;
    }
    if ($filterCategory !== '') {
        $sql .= ' AND p.category_id = ?';
        $fallbackParams[] = $filterCategory;
    }
    $sql .= ' ORDER BY p.name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($fallbackParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$base = isset($stockBasePath) && $stockBasePath !== ''
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');

$products = [];
foreach ($rows as $row) {
    $pid = (int) ($row['id'] ?? 0);
    $filename = trim((string) ($row['resolved_image'] ?? $row['main_image'] ?? ''));
    $imageUrl = '';
    if ($filename !== '' && function_exists('stock_product_image_url')) {
        $imageUrl = (string) stock_product_image_url($pid, $filename, 'medium');
    } elseif ($filename !== '' && function_exists('stock_product_list_image_url')) {
        $imageUrl = (string) stock_product_list_image_url($pid, $filename, 'medium', $base);
    }
    $products[] = [
        'id' => $pid,
        'product_code' => (string) ($row['product_code'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'unit_price' => (float) ($row['unit_price'] ?? 0),
        'buying_price' => (float) ($row['buying_price'] ?? 0),
        'currency' => (string) ($row['currency'] ?? 'TZS'),
        'brand' => (string) ($row['brand'] ?? ''),
        'category_id' => (int) ($row['category_id'] ?? 0),
        'category_name' => (string) ($row['category_name'] ?? ''),
        'supplier_name' => (string) ($row['supplier_name'] ?? ''),
        'quantity' => (float) ($row['quantity'] ?? 0),
        'main_image' => $filename,
        'image_url' => $imageUrl,
    ];
}

$page_title = 'Product Catalogue';
$employeeHeaderTitle = 'Catalogue';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk';

$assetVersion = max(
    (int) (@filemtime(__DIR__ . '/stock-ui/dist/assets/stock-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/stock-ui/dist/assets/stock-ui.css') ?: 0),
    time()
);

include __DIR__ . '/includes/header.php';
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
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
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
    min-height: 280px;
}
@media (max-width: 767.98px) {
    body.page-products-desk .employee-header.employee-header--products-desk { padding: 0 0.75rem !important; }
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
<main class="main-content products-desk-react-root" role="main">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to view the catalogue.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'catalogue',
            'data' => [
                'products' => $products,
                'categories' => array_map(static function ($c) {
                    return ['id' => (int) ($c['id'] ?? 0), 'name' => (string) ($c['name'] ?? '')];
                }, $categories),
                'showCost' => $showCost,
                'baseUrl' => $base,
                'filterSearch' => $filterSearch,
                'filterCategory' => $filterCategory,
                'detailUrl' => $base . 'product-detail.php',
                'productsListUrl' => $base . 'modules/products/index.php',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"catalogue","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root">
        <div class="cat-desk-boot" role="status" aria-live="polite">Loading catalogue…</div>
    </div>
    <script type="module" src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
