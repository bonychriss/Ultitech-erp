<?php
// stock/modules/products/index.php - React products desk (expenses-style layout)
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
require_once __DIR__ . '/includes/product_search.inc.php';
requireLogin();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$page_title = 'Products';
$employeeHeaderTitle = 'Products';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk';

$showCost = in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true);

$catList = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$supList = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$brandList = [];
try {
    $brandList = $pdo->query('SELECT id, name FROM brands ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

$dupSql = "SELECT product_code, name, COUNT(*) AS cnt
           FROM products
           WHERE product_code != ''
           GROUP BY product_code, name
           HAVING cnt > 1";
$duplicatesFound = $pdo->query($dupSql)->fetchAll(PDO::FETCH_ASSOC);
$totalDuplicateRows = count($duplicatesFound);

$isFilteringDuplicates = isset($_GET['show_duplicates']) && (string) $_GET['show_duplicates'] === '1';
$createdId = isset($_GET['created_id']) ? (int) $_GET['created_id'] : 0;

$whereClause = 'WHERE 1=1';
$params = [];

$hasItemType = false;
$hasProductImages = false;
try {
    $pdo->query('SELECT item_type FROM products LIMIT 1');
    $hasItemType = true;
} catch (PDOException $e) {
}
try {
    $pdo->query('SELECT image_name FROM product_images LIMIT 1');
    $hasProductImages = true;
} catch (PDOException $e) {
}

$filterSearch = isset($_GET['search']) ? stock_products_normalize_query((string) $_GET['search']) : '';
$filterCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$filterItemType = isset($_GET['item_type']) ? trim((string) $_GET['item_type']) : '';
$filterSupplier = isset($_GET['supplier']) ? trim((string) $_GET['supplier']) : '';
$filterBrand = isset($_GET['brand']) ? trim((string) $_GET['brand']) : '';

if ($filterSearch !== '') {
    $hasBrandCol = false;
    try {
        $pdo->query('SELECT brand FROM products LIMIT 1');
        $hasBrandCol = true;
    } catch (PDOException $e) {
    }
    [$searchSql, $searchParams] = stock_products_build_search_clause($filterSearch, 'p', $hasBrandCol);
    if ($searchSql !== '') {
        $whereClause .= ' AND ' . $searchSql;
        foreach ($searchParams as $sp) {
            $params[] = $sp;
        }
    }
}
if ($filterCategory !== '') {
    $whereClause .= ' AND p.category_id = ?';
    $params[] = $filterCategory;
}
if ($hasItemType && $filterItemType !== '') {
    $whereClause .= ' AND p.item_type = ?';
    $params[] = $filterItemType;
}
if ($filterSupplier !== '') {
    $whereClause .= ' AND p.supplier_id = ?';
    $params[] = $filterSupplier;
}
if ($filterBrand !== '') {
    $whereClause .= ' AND p.brand = ?';
    $params[] = $filterBrand;
}

if ($isFilteringDuplicates) {
    $whereClause .= " AND (p.product_code, p.name) IN (
        SELECT product_code, name FROM products
        WHERE product_code != ''
        GROUP BY product_code, name
        HAVING COUNT(*) > 1
    )";
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

$itemTypeSelect = $hasItemType ? 'p.item_type' : "'general' AS item_type";

$orderBySql = 'p.id DESC';
if ($filterSearch !== '') {
    [$ordSql, $ordParams] = stock_products_search_order_sql($filterSearch, 'p');
    $orderBySql = $ordSql;
    foreach ($ordParams as $op) {
        $params[] = $op;
    }
}
if ($createdId > 0) {
    $orderBySql = "(p.id = {$createdId}) DESC, " . $orderBySql;
}

$sql = "SELECT p.id, p.name, p.product_code, p.brand, p.currency, p.unit_price, p.buying_price,
               p.reorder_level, p.main_image, {$itemTypeSelect},
               {$mainImageExpr} AS resolved_main_image,
               c.name AS category_name, s.name AS supplier_name,
               st.quantity, st.location
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN stock st ON p.id = st.product_id
        {$whereClause}
        GROUP BY p.id
        ORDER BY {$orderBySql}";

$products = [];
$dbError = '';
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
    $dbError = $e->getMessage();
}

$base = isset($stockBasePath) && $stockBasePath !== ''
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');
$imported = (int) ($_GET['imported'] ?? 0);
$updated = (int) ($_GET['updated'] ?? 0);
$totalGlow = $imported + $updated;
$glowCount = 0;

$lowStockCount = 0;
$outOfStockCount = 0;

foreach ($rows as $row) {
    $filename = !empty($row['resolved_main_image'])
        ? (string) $row['resolved_main_image']
        : (string) ($row['main_image'] ?? '');
    $filename = trim($filename);
    $imageUrl = '';
    if ($filename !== '' && function_exists('stock_product_list_image_url')) {
        $imageUrl = stock_product_list_image_url((int) $row['id'], $filename, 'medium', (string) $base);
    }

    $qty = (int) ($row['quantity'] ?? 0);
    $reorder = (int) ($row['reorder_level'] ?? 0);
    if ($qty <= 0) {
        $outOfStockCount++;
    } elseif ($qty <= $reorder) {
        $lowStockCount++;
    }

    $isRecent = (($_GET['bulk_import'] ?? '') === 'success' && $glowCount < $totalGlow);
    if ($isRecent) {
        $glowCount++;
    }
    $isCreated = $createdId > 0 && (int) $row['id'] === $createdId;

    $products[] = [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'product_code' => (string) ($row['product_code'] ?? ''),
        'brand' => (string) ($row['brand'] ?? ''),
        'currency' => (string) ($row['currency'] ?? 'USD'),
        'unit_price' => (float) ($row['unit_price'] ?? 0),
        'buying_price' => (float) ($row['buying_price'] ?? 0),
        'reorder_level' => $reorder,
        'item_type' => (string) ($row['item_type'] ?? 'general'),
        'main_image' => $filename,
        'image_url' => $imageUrl,
        'category_name' => $row['category_name'] ?? null,
        'supplier_name' => $row['supplier_name'] ?? null,
        'quantity' => $qty,
        'location' => $row['location'] ?? null,
        'is_recent' => $isRecent || $isCreated,
    ];
}

$totalProductsAll = 0;
try {
    $totalProductsAll = (int) ($pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() ?: 0);
} catch (PDOException $e) {
    $totalProductsAll = count($products);
}

// Missing photos = products that would show no image on the list (same rules as image_url).
$missingImagesCount = 0;
$missingImageSamples = [];
try {
    $missImageExpr = $hasProductImages
        ? "COALESCE(NULLIF(TRIM(p.main_image), ''), (
                SELECT pi.image_name
                FROM product_images pi
                WHERE pi.product_id = p.id
                  AND TRIM(IFNULL(pi.image_name, '')) <> ''
                ORDER BY pi.is_primary DESC, pi.id ASC
                LIMIT 1
           ))"
        : 'p.main_image';

    $missStmt = $pdo->query(
        "SELECT p.id, p.name, p.product_code, {$missImageExpr} AS resolved_main_image
         FROM products p
         ORDER BY p.id DESC"
    );
    $missingRows = [];
    if ($missStmt) {
        while ($mrow = $missStmt->fetch(PDO::FETCH_ASSOC)) {
            $fn = trim((string) ($mrow['resolved_main_image'] ?? ''));
            $url = '';
            if ($fn !== '' && function_exists('stock_product_list_image_url')) {
                $url = stock_product_list_image_url((int) $mrow['id'], $fn, 'medium', (string) $base);
            }
            if ($url === '') {
                $missingRows[] = [
                    'id' => (int) ($mrow['id'] ?? 0),
                    'name' => (string) ($mrow['name'] ?? ''),
                    'product_code' => (string) ($mrow['product_code'] ?? ''),
                ];
            }
        }
    }
    $missingImagesCount = count($missingRows);
    $missingImageSamples = array_slice($missingRows, 0, 8);
} catch (Throwable $e) {
    $missingImagesCount = 0;
    $missingImageSamples = [];
}

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
body.page-products-desk .employee-header--products-desk::after {
    display: none !important;
}
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
    align-self: flex-start;
    overflow: visible;
    flex-shrink: 0;
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
    min-width: 0;
}
@media (max-width: 1024px) {
    main.main-content.products-desk-react-root {
        padding: 0 0.875rem 1.5rem !important;
    }
}
@media (max-width: 767.98px) {
    body.page-products-desk .employee-header.employee-header--products-desk {
        padding: 0 0.75rem !important;
    }
    body.page-products-desk .employee-header--products-desk .employee-header-page-title {
        font-size: 1rem !important;
    }
    main.main-content.products-desk-react-root {
        padding: 0 0.75rem 1.5rem !important;
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
</style>
<main class="main-content products-desk-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to use the Products page.</div>
    </noscript>
    <?php if ($dbError !== ''): ?>
        <div class="alert alert-danger m-3">Database error: <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'products-list',
            'data' => [
                'products' => $products,
                'categories' => array_map(static function ($c) {
                    return ['id' => (int) $c['id'], 'name' => (string) $c['name']];
                }, $catList),
                'suppliers' => array_map(static function ($s) {
                    return ['id' => (int) $s['id'], 'name' => (string) $s['name']];
                }, $supList),
                'brands' => array_map(static function ($b) {
                    return [
                        'id' => isset($b['id']) ? (int) $b['id'] : 0,
                        'name' => (string) ($b['name'] ?? ''),
                    ];
                }, $brandList),
                'hasItemType' => $hasItemType,
                'showCost' => $showCost,
                'baseUrl' => $base,
                'searchApiUrl' => $base . 'modules/products/api/search.php',
                'uploadsUrl' => '../uploads/index.php?folder=uploads',
                'filterSearch' => $filterSearch,
                'filterCategory' => $filterCategory,
                'filterItemType' => $filterItemType,
                'filterSupplier' => $filterSupplier,
                'filterBrand' => $filterBrand,
                'totalDuplicateRows' => $totalDuplicateRows,
                'isFilteringDuplicates' => $isFilteringDuplicates,
                'createdId' => $createdId,
                'bulkImportSuccess' => (($_GET['bulk_import'] ?? '') === 'success'),
                'imported' => $imported,
                'updated' => $updated,
                'created' => (($_GET['created'] ?? '') === '1'),
                'stats' => [
                    'total_count' => $totalProductsAll,
                    'listed_count' => count($products),
                    'low_stock_count' => $lowStockCount,
                    'out_of_stock_count' => $outOfStockCount,
                    'missing_images_count' => $missingImagesCount,
                ],
                'missingImages' => [
                    'count' => $missingImagesCount,
                    'samples' => $missingImageSamples,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"products-list","data":{"products":[]}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root">
      <div class="prod-desk-page prod-desk-skeleton" role="status" aria-live="polite" aria-busy="true">
        <span class="sr-only">Loading products…</span>
        <div class="prod-desk-page-header">
          <div class="prod-desk-page-header-search"><span class="prod-desk-bone prod-desk-bone--search"></span></div>
          <div class="prod-desk-page-header-actions">
            <span class="prod-desk-bone prod-desk-bone--icon"></span>
            <span class="prod-desk-bone prod-desk-bone--btn"></span>
          </div>
        </div>
        <section class="prod-desk-kpi-grid" aria-hidden="true">
          <?php for ($i = 0; $i < 4; $i++): ?>
          <div class="prod-desk-kpi-card prod-desk-kpi-card--skeleton">
            <span class="prod-desk-bone prod-desk-bone--kpi-icon"></span>
            <div class="prod-desk-skeleton-kpi-text">
              <span class="prod-desk-bone prod-desk-bone--label"></span>
              <span class="prod-desk-bone prod-desk-bone--value"></span>
            </div>
          </div>
          <?php endfor; ?>
        </section>
        <section class="prod-desk-results" aria-hidden="true">
          <div class="prod-desk-results-head"><span class="prod-desk-bone prod-desk-bone--count"></span></div>
          <div class="prod-desk-table-wrap">
            <table class="prod-desk-table">
              <thead>
                <tr>
                  <th style="width:40px"><span class="prod-desk-bone prod-desk-bone--check"></span></th>
                  <th><span class="prod-desk-bone prod-desk-bone--th" style="width:40%"></span></th>
                  <th><span class="prod-desk-bone prod-desk-bone--th" style="width:18%"></span></th>
                  <th><span class="prod-desk-bone prod-desk-bone--th" style="width:16%"></span></th>
                  <th><span class="prod-desk-bone prod-desk-bone--th" style="width:48px;margin:0 auto;display:block"></span></th>
                  <th><span class="prod-desk-bone prod-desk-bone--th" style="width:72px;margin-left:auto;display:block"></span></th>
                </tr>
              </thead>
              <tbody>
                <?php for ($r = 0; $r < 8; $r++): ?>
                <tr>
                  <td><span class="prod-desk-bone prod-desk-bone--check"></span></td>
                  <td>
                    <div class="prod-desk-product">
                      <span class="prod-desk-bone prod-desk-bone--thumb"></span>
                      <div class="prod-desk-skeleton-kpi-text" style="flex:1">
                        <span class="prod-desk-bone prod-desk-bone--name"></span>
                        <span class="prod-desk-bone prod-desk-bone--code"></span>
                      </div>
                    </div>
                  </td>
                  <td><span class="prod-desk-bone prod-desk-bone--cell"></span></td>
                  <td>
                    <span class="prod-desk-bone prod-desk-bone--cell"></span>
                    <span class="prod-desk-bone prod-desk-bone--code" style="margin-top:6px"></span>
                  </td>
                  <td style="text-align:center"><span class="prod-desk-bone prod-desk-bone--stock" style="margin:0 auto"></span></td>
                  <td>
                    <div class="prod-desk-actions" style="justify-content:flex-end">
                      <span class="prod-desk-bone prod-desk-bone--icon-sm"></span>
                      <span class="prod-desk-bone prod-desk-bone--icon-sm"></span>
                      <span class="prod-desk-bone prod-desk-bone--icon-sm"></span>
                    </div>
                  </td>
                </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
    <script type="module" src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
