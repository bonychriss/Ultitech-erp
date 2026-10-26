<?php
// stock/modules/products/add.php — React product create (expenses create layout)
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$isUltimateStockSimple = (
    (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
);

$requireProductImage = $isUltimateStockSimple
    || (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/roadmaster/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'roadmaster');

$showCost = in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true);

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

$year = date('Y');
function stock_products_add_preview_code(PDO $pdo, string $prefix): string
{
    $stmtMax = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(product_code, '-', -1) AS UNSIGNED)) FROM products WHERE product_code LIKE ?"
    );
    $stmtMax->execute([$prefix . '%']);
    $maxNum = $stmtMax->fetchColumn();
    $nextNum = $maxNum ? ((int) $maxNum + 1) : 1;
    return $prefix . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);
}

$previewCode = stock_products_add_preview_code($pdo, "PRD-{$year}-");
$previewTruckCode = stock_products_add_preview_code($pdo, "TRK-{$year}-");

$base = isset($stockBasePath) && $stockBasePath !== ''
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');
/* Prefer SCRIPT_NAME so /public_html/stock/... resolves correctly on XAMPP */
$__sn = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (preg_match('#^(.*?/stock)/#', $__sn, $__m)) {
    $base = rtrim($__m[1], '/') . '/';
}
/* Tenant URLs like /ultimate/stock/... or /roadmaster/stock/... load shared /stock/ UI assets */
if (strpos($base, '/ultimate/stock') !== false) {
    $base = preg_replace('#/ultimate/stock#', '/stock', $base, 1);
} elseif (strpos($base, '/roadmaster/stock') !== false) {
    $base = preg_replace('#/roadmaster/stock#', '/stock', $base, 1);
}
$uiCss = $base . 'stock-ui/dist/assets/stock-ui.css';
$uiJs = $base . 'stock-ui/dist/assets/stock-ui.js';
$assetVersion = (string) max(
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.css') ?: 0),
    time()
);

$page_title = 'Add Product';
$employeeHeaderTitle = '';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--product-create';
$bodyExtraClass = 'page-product-create';

include '../../includes/header.php';
?>
<style>
:root { --bg-body: #f1f5f9; }
body.page-product-create.dashboard { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
body.page-product-create.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-product-create.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-product-create,
body.page-product-create.dashboard,
body.page-product-create .layout-main-wrapper,
body.page-product-create .layout-main-wrapper > .flex-grow-1 {
    background: #f1f5f9 !important;
}
body.page-product-create .header,
body.page-product-create .employee-header,
body.page-product-create .employee-header.employee-header--product-create {
    background: #f1f5f9 !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
}
body.page-product-create .employee-header--product-create::after { display: none !important; }
body.page-product-create .employee-header--product-create .header-content {
    background: transparent !important;
    padding: 0.5rem 0 !important;
}
body.page-product-create .employee-header--product-create .employee-header-page-title:empty,
body.page-product-create .employee-header--product-create .employee-header-page-heading:empty {
    display: none !important;
}
main.main-content.product-create-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    box-sizing: border-box;
    background: #f1f5f9 !important;
    overflow: auto !important;
}
main.main-content.product-create-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
}
@media (max-width: 767.98px) {
    main.main-content.product-create-react-root {
        padding: 0 0.75rem 1.5rem !important;
        -webkit-overflow-scrolling: touch;
    }
}
html[data-theme="dark"] body.page-product-create,
html[data-theme="dark"] body.page-product-create.dashboard,
html[data-theme="dark"] body.page-product-create .layout-main-wrapper,
html[data-theme="dark"] body.page-product-create .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-product-create .employee-header,
html[data-theme="dark"] main.main-content.product-create-react-root {
    background: #0f172a !important;
}
</style>
<main class="main-content product-create-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to add a product.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'product-create',
            'data' => [
                'isUltimate' => $isUltimateStockSimple,
                'requireProductImage' => $requireProductImage,
                'showCost' => $showCost,
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
                'previewCode' => $previewCode,
                'previewTruckCode' => $previewTruckCode,
                'currencies' => ['TZS', 'USD', 'EUR'],
                'defaultCurrency' => 'TZS',
                'listUrl' => function_exists('stock_desk_url')
                    ? stock_desk_url('products')
                    : ($base . 'modules/products/index.php'),
                'createApiUrl' => function_exists('stock_products_api_url')
                    ? stock_products_api_url('create-product.php')
                    : ($base . 'modules/products/api/create-product.php'),
                'baseUrl' => $base,
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
