<?php
// stock/modules/products/view.php — React product detail (stock-ui)
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

$showCost = in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true);

$sql = "SELECT p.*, c.name AS category_name, s.name AS supplier_name, st.quantity, st.location
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN stock st ON p.id = st.product_id
        WHERE p.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    flash('success', 'Product not found', 'danger');
    redirect('index.php');
}

$movements = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM stock_movements WHERE product_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$id]);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

$images = [];
try {
    $stmtImg = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC');
    $stmtImg->execute([$id]);
    $images = $stmtImg->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

$base = isset($stockBasePath) && $stockBasePath !== ''
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');

$mainFilename = '';
if (!empty($images[0]['image_name'])) {
    $mainFilename = (string) $images[0]['image_name'];
} elseif (!empty($product['main_image'])) {
    $mainFilename = (string) $product['main_image'];
}

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
        'large_url' => function_exists('stock_product_list_image_url')
            ? stock_product_list_image_url($id, $fn, 'large', $base)
            : '',
    ];
}

if ($imagePayload === [] && $mainFilename !== '') {
    $imagePayload[] = [
        'id' => 0,
        'image_name' => $mainFilename,
        'is_primary' => true,
        'thumbnail_url' => function_exists('stock_product_list_image_url')
            ? stock_product_list_image_url($id, $mainFilename, 'thumbnail', $base)
            : '',
        'medium_url' => function_exists('stock_product_list_image_url')
            ? stock_product_list_image_url($id, $mainFilename, 'medium', $base)
            : '',
        'large_url' => function_exists('stock_product_list_image_url')
            ? stock_product_list_image_url($id, $mainFilename, 'large', $base)
            : '',
    ];
}

$qty = (int) ($product['quantity'] ?? 0);
$reorder = (int) ($product['reorder_level'] ?? 0);

$productPayload = [
    'id' => (int) $product['id'],
    'name' => (string) ($product['name'] ?? ''),
    'product_code' => (string) ($product['product_code'] ?? ''),
    'description' => (string) ($product['description'] ?? ''),
    'brand' => (string) ($product['brand'] ?? ''),
    'currency' => (string) ($product['currency'] ?? 'USD'),
    'unit_price' => (float) ($product['unit_price'] ?? 0),
    'buying_price' => (float) ($product['buying_price'] ?? 0),
    'wholesale_price' => (float) ($product['wholesale_price'] ?? 0),
    'reorder_level' => $reorder,
    'quantity' => $qty,
    'location' => (string) ($product['location'] ?? ''),
    'category_name' => $product['category_name'] ?? null,
    'supplier_name' => $product['supplier_name'] ?? null,
    'item_type' => (string) ($product['item_type'] ?? 'general'),
    'part_condition' => (string) ($product['part_condition'] ?? ''),
    'compatibility' => (string) ($product['compatibility'] ?? ($product['compatible_truck_model'] ?? '')),
    'oem_number' => (string) ($product['oem_number'] ?? ''),
    'model_number' => (string) ($product['model_number'] ?? ''),
    'unit_of_measure' => (string) ($product['unit_of_measure'] ?? ''),
    'vin' => (string) ($product['vin'] ?? ''),
    'engine_number' => (string) ($product['engine_number'] ?? ''),
    'chassis_number' => (string) ($product['chassis_number'] ?? ''),
    'model_year' => $product['model_year'] ?? null,
    'mileage' => isset($product['mileage']) ? (float) $product['mileage'] : null,
    'color' => (string) ($product['color'] ?? ''),
    'is_low_stock' => $qty <= $reorder,
    'is_out_of_stock' => $qty <= 0,
];

$movementPayload = [];
foreach ($movements as $mov) {
    $movementPayload[] = [
        'id' => (int) ($mov['id'] ?? 0),
        'created_at' => (string) ($mov['created_at'] ?? ''),
        'movement_type' => (string) ($mov['movement_type'] ?? ''),
        'quantity' => (float) ($mov['quantity'] ?? 0),
        'reference_type' => (string) ($mov['reference_type'] ?? ''),
        'reference_id' => $mov['reference_id'] ?? null,
        'notes' => (string) ($mov['notes'] ?? ''),
    ];
}

$page_title = 'Product';
$employeeHeaderTitle = (string) ($product['name'] ?? 'Product');
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk';

$assetVersion = @filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.js') ?: time();

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
        <div class="alert alert-warning m-3">JavaScript is required to view this product.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'product-view',
            'data' => [
                'product' => $productPayload,
                'images' => $imagePayload,
                'movements' => $movementPayload,
                'showCost' => $showCost,
                'baseUrl' => $base,
                'listUrl' => 'index.php',
                'editUrl' => 'edit.php?id=' . $id,
                'duplicateUrl' => 'duplicate.php?id=' . $id,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"product-view","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
