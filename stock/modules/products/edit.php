<?php
// stock/modules/products/edit.php — React product edit (expenses create layout)
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
requireLogin();

if (!isset($_GET['id']) || (int) $_GET['id'] < 1) {
    redirect('index.php');
}

$id = (int) $_GET['id'];

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$isUltimateStockSimple = (
    (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
);

$showCost = in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true);

$stmt = $pdo->prepare(
    'SELECT p.*, st.quantity, st.location
     FROM products p
     LEFT JOIN stock st ON p.id = st.product_id
     WHERE p.id = ?'
);
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    flash('success', 'Product not found', 'danger');
    redirect('index.php');
}

$images = [];
try {
    $imgStmt = $pdo->prepare(
        'SELECT id, image_name, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC'
    );
    $imgStmt->execute([$id]);
    $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $images = [];
}

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$brands = [];
try {
    $brands = $pdo->query('SELECT id, name, brand_type FROM brands ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        $brands = $pdo->query('SELECT id, name FROM brands ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $brands = [];
    }
}

$base = isset($stockBasePath) && $stockBasePath !== ''
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');
$__sn = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (preg_match('#^(.*?/stock)/#', $__sn, $__m)) {
    $base = rtrim($__m[1], '/') . '/';
}
if (strpos($base, '/ultimate/stock') !== false) {
    $base = preg_replace('#/ultimate/stock#', '/stock', $base, 1);
}
$uiCss = $base . 'stock-ui/dist/assets/stock-ui.css';
$uiJs = $base . 'stock-ui/dist/assets/stock-ui.js';
$assetVersion = (string) max(
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.css') ?: 0),
    time()
);

$imagePayload = [];
foreach ($images as $img) {
    $fn = (string) ($img['image_name'] ?? '');
    $imagePayload[] = [
        'id' => (int) ($img['id'] ?? 0),
        'image_name' => $fn,
        'is_primary' => !empty($img['is_primary']),
        'thumbnail_url' => function_exists('stock_product_list_image_url')
            ? stock_product_list_image_url($id, $fn, 'thumbnail', $base)
            : '',
        'medium_url' => function_exists('stock_product_list_image_url')
            ? stock_product_list_image_url($id, $fn, 'medium', $base)
            : '',
    ];
}

$itemType = (string) ($product['item_type'] ?? 'general');
$registerType = 'spare_part';
if ($itemType === 'vehicle') {
    $registerType = 'truck';
} elseif ($itemType === 'general') {
    $registerType = 'general';
}

$productPayload = [
    'id' => (int) $product['id'],
    'product_code' => (string) ($product['product_code'] ?? ''),
    'name' => (string) ($product['name'] ?? ''),
    'description' => (string) ($product['description'] ?? ''),
    'category_id' => isset($product['category_id']) ? (int) $product['category_id'] : null,
    'supplier_id' => isset($product['supplier_id']) ? (int) $product['supplier_id'] : null,
    'brand' => (string) ($product['brand'] ?? ''),
    'currency' => (string) ($product['currency'] ?? 'TZS'),
    'unit_price' => (float) ($product['unit_price'] ?? 0),
    'buying_price' => (float) ($product['buying_price'] ?? 0),
    'wholesale_price' => (float) ($product['wholesale_price'] ?? 0),
    'reorder_level' => (int) ($product['reorder_level'] ?? 10),
    'quantity' => (int) ($product['quantity'] ?? 0),
    'location' => (string) ($product['location'] ?? ''),
    'item_type' => $itemType,
    'register_type' => $registerType,
    'part_condition' => (string) ($product['part_condition'] ?? 'new'),
    'unit_of_measure' => (string) ($product['unit_of_measure'] ?? 'pcs'),
    'compatibility' => (string) ($product['compatibility'] ?? ($product['compatible_truck_model'] ?? '')),
    'oem_number' => (string) ($product['oem_number'] ?? ''),
    'vin' => (string) ($product['vin'] ?? ''),
    'engine_number' => (string) ($product['engine_number'] ?? ''),
    'chassis_number' => (string) ($product['chassis_number'] ?? ''),
    'model_year' => $product['model_year'] ?? null,
    'mileage' => isset($product['mileage']) ? (float) $product['mileage'] : null,
    'color' => (string) ($product['color'] ?? ''),
    'truck_type' => (string) ($product['truck_type'] ?? ''),
    'model_number' => (string) ($product['model_number'] ?? ''),
    'engine_model' => (string) ($product['engine_model'] ?? ''),
    'transmission_model' => (string) ($product['transmission_model'] ?? ''),
];

$page_title = 'Edit Product';
$employeeHeaderTitle = 'Edit Product';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk';

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
    flex: 0 0 auto !important;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: visible !important;
    box-sizing: border-box;
    background: #f8fafc !important;
    height: auto !important;
    min-height: 0 !important;
    display: block !important;
}
main.main-content.products-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    height: auto !important;
    min-height: 0 !important;
    display: block;
}
main.main-content.products-desk-react-root #root .prod-create-shell {
    height: auto !important;
    min-height: 0 !important;
}
@media (max-width: 767.98px) {
    body.page-products-desk .employee-header.employee-header--products-desk {
        padding: 0 0.75rem !important;
    }
    body.page-products-desk .employee-header--products-desk .header-content {
        padding: 0.65rem 0 0.4rem !important;
        gap: 0.4rem !important;
    }
    body.page-products-desk .employee-header--products-desk .employee-header-page-title {
        font-size: 1.1rem !important;
        max-width: min(100%, 58vw) !important;
    }
    main.main-content.products-desk-react-root {
        padding: 0 0.75rem 1.5rem !important;
        -webkit-overflow-scrolling: touch;
    }
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
body.page-products-desk a.prod-create-btn-cancel,
body.page-products-desk button.prod-create-btn-save {
    border-radius: 9999px !important;
}
</style>
<main class="main-content products-desk-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to edit a product.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'product-create',
            'data' => [
                'mode' => 'edit',
                'isUltimate' => $isUltimateStockSimple,
                'showCost' => $showCost,
                'product' => $productPayload,
                'existingImages' => $imagePayload,
                'categories' => array_map(static function ($c) {
                    return ['id' => (int) $c['id'], 'name' => (string) $c['name']];
                }, $categories),
                'suppliers' => array_map(static function ($s) {
                    return ['id' => (int) $s['id'], 'name' => (string) $s['name']];
                }, $suppliers),
                'brands' => array_map(static function ($b) {
                    return [
                        'id' => (int) ($b['id'] ?? 0),
                        'name' => (string) ($b['name'] ?? ''),
                        'brand_type' => (string) ($b['brand_type'] ?? ''),
                    ];
                }, $brands),
                'useBrandFreeText' => count($brands) === 0,
                'currencies' => ['TZS', 'USD', 'EUR'],
                'defaultCurrency' => (string) ($product['currency'] ?? 'TZS'),
                'listUrl' => 'index.php',
                'viewUrl' => 'view.php?id=' . $id,
                'createApiUrl' => 'api/create-product.php',
                'updateApiUrl' => 'api/update-product.php',
                'baseUrl' => $base,
                'updated' => (isset($_GET['updated']) && (string) $_GET['updated'] === '1'),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"product-create","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($uiCss, ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
    <style id="prod-create-mobile-force">
    /* Force compact stacking & kill any cached wildcard bottom margins */
    @media (max-width: 900px) {
        body.dashboard .prod-create-shell,
        body.dashboard .prod-create-layout,
        body.dashboard .prod-create-main,
        body.dashboard .prod-create-section,
        body.dashboard .prod-create-row,
        body.dashboard .prod-create-row > * {
            height: auto !important;
            min-height: 0 !important;
            max-height: none !important;
            flex: none !important;
            align-content: start !important;
        }
        body.dashboard .prod-create-row {
            display: block !important;
            margin-top: 0 !important;
            margin-bottom: 16px !important;
        }
        body.dashboard .prod-create-label {
            display: block !important;
            padding: 0 0 4px !important;
            margin: 0 0 4px 0 !important;
        }
        body.dashboard .prod-create-row > div {
            display: block !important;
            height: auto !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        body.dashboard .prod-create-help {
            display: block !important;
            margin: 4px 0 0 0 !important;
            padding: 0 !important;
        }
        body.dashboard .prod-create-input,
        body.dashboard .prod-create-select,
        body.dashboard .prod-create-textarea,
        body.dashboard .prod-create-currency {
            width: 100% !important;
            max-width: none !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        body.dashboard .prod-create-section {
            padding-bottom: 1.25rem !important;
            margin-bottom: 1.25rem !important;
        }
        body.dashboard .prod-create-section-header {
            margin-bottom: 1rem !important;
        }
        body.dashboard .prod-create-actions {
            display: flex !important;
            flex-direction: column !important;
            gap: 0.65rem !important;
            margin-bottom: 1.5rem !important;
        }
        body.dashboard .prod-create-actions a,
        body.dashboard .prod-create-actions button {
            width: 100% !important;
        }
    }
    </style>
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($uiJs, ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
