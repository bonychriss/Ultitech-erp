<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/paths.php';
require_once __DIR__ . '/purchase_workflow.php';
requireLogin();

$company_id = function_exists('stockPurchaseActiveCompanyId') ? stockPurchaseActiveCompanyId() : (int) (currentCompanyId() ?? 0);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('success_type', 'error');
    flash('success', 'Invalid Purchase Order ID.');
    redirect('index.php');
}

$po = function_exists('fetchStockPurchaseOrderById')
    ? fetchStockPurchaseOrderById($pdo, $id, true)
    : null;

if (!$po) {
    flash('success_type', 'error');
    flash('success', 'Purchase Order not found.');
    redirect('index.php');
}

$isLegacyReceive = (($po['_po_table'] ?? 'stocks_purchase_orders') === 'purchases');
$po['po_number'] = $po['po_number'] ?? $po['purchase_no'] ?? ('PO-' . $id);
if (function_exists('enrichPurchaseOrderSupplierDisplay')) {
    enrichPurchaseOrderSupplierDisplay($po, $pdo, $company_id);
}
if (empty($po['supplier_name'])) {
    $po['supplier_name'] = $po['supplier_name'] ?? '';
}

$poType = $isLegacyReceive ? 'domestic' : ($po['purchase_type'] ?? 'domestic');
$poType = in_array($poType, ['domestic', 'import'], true) ? $poType : 'domestic';

if (!$isLegacyReceive) {
    $shipmentFunctions = dirname(__DIR__, 2) . '/includes/shipment-functions.php';
    if (is_file($shipmentFunctions)) {
        require_once $shipmentFunctions;
    }
    if (function_exists('ensure_shipment_po_linking_schema')) {
        ensure_shipment_po_linking_schema($pdo);
    }
    if ($poType === 'import' && function_exists('stocks_po_has_linked_shipment') && !stocks_po_has_linked_shipment($pdo, $id)) {
        flash('success_type', 'error');
        flash('success', 'Outdoor POs need a linked shipment before you can receive stock. Open this PO on the purchase list and use Create shipment.');
        redirect('index.php');
    }
}

$warehouseList = [];
try {
    if (function_exists('ensureWarehousesSchema')) {
        ensureWarehousesSchema($pdo);
    }
    $warehouseList = $pdo->query('SELECT * FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    try {
        $warehouseList = $pdo->query('SELECT * FROM warehouses ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {
        $warehouseList = [];
    }
}
if ($warehouseList === []) {
    $warehouseList = [['id' => 1, 'name' => 'Main Warehouse', 'code' => 'MAIN']];
}

// Product images for line items (match view_po.php resolution).
$productCols = [];
try {
    $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $productCols = [];
}
$hasProductImageCol = in_array('image', $productCols, true);
$hasProductMainImageCol = in_array('main_image', $productCols, true);

if (!function_exists('dr_pick_product_image_file')) {
    function dr_pick_product_image_file(array $row, bool $hasImage, bool $hasMainImage): string
    {
        if ($hasImage) {
            $image = trim((string) ($row['image'] ?? ''));
            if ($image !== '') {
                return $image;
            }
        }
        if ($hasMainImage) {
            $main = trim((string) ($row['main_image'] ?? ''));
            if ($main !== '') {
                return $main;
            }
        }

        return '';
    }
}

if (!function_exists('dr_lookup_product_for_stock_item')) {
    function dr_lookup_product_for_stock_item(PDO $pdo, int $itemId, string $itemName, string $sku, bool $hasImage, bool $hasMainImage): ?array
    {
        $cols = ['id'];
        if ($hasImage) {
            $cols[] = 'image';
        }
        if ($hasMainImage) {
            $cols[] = 'main_image';
        }
        $select = implode(', ', $cols);

        if ($itemId > 0) {
            $stmt = $pdo->prepare("SELECT {$select} FROM products WHERE id = ? LIMIT 1");
            $stmt->execute([$itemId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        if ($sku !== '') {
            $stmt = $pdo->prepare("SELECT {$select} FROM products WHERE LOWER(TRIM(product_code)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->execute([$sku]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        if ($itemName !== '') {
            $stmt = $pdo->prepare("SELECT {$select} FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->execute([$itemName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return null;
    }
}

if (!function_exists('dr_resolve_product_image_url')) {
    function dr_resolve_product_image_url(int $productId, string $imageValue): ?string
    {
        if ($productId <= 0) {
            return null;
        }
        $imageValue = trim($imageValue);
        if ($imageValue !== '' && preg_match('~^https?://~i', $imageValue)) {
            return $imageValue;
        }
        if (function_exists('stock_product_list_image_url')) {
            global $stockBasePath;
            $url = stock_product_list_image_url($productId, $imageValue, 'medium', (string) ($stockBasePath ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
        if ($imageValue === '') {
            return null;
        }
        $params = ['product_id' => $productId, 'size' => 'medium'];
        $params['file'] = basename(str_replace('\\', '/', $imageValue));
        $query = http_build_query($params);
        global $stockBasePath;
        if (!empty($stockBasePath)) {
            return rtrim((string) $stockBasePath, '/') . '/product_image.php?' . $query;
        }

        return function_exists('app_url')
            ? app_url('stock/product_image.php?' . $query)
            : '/stock/product_image.php?' . $query;
    }
}

// Fetch PO Items with current stock info (one row per PO line).
$items = [];
$seenLineIds = [];
if ($isLegacyReceive) {
    if (!function_exists('stockPurchaseFetchLegacyReceiveLineItems')) {
        flash('success_type', 'error');
        flash('success', 'Legacy receive is not available on this server yet. Please deploy the latest purchase module update.');
        redirect('view_po.php?id=' . $id);
    }
    try {
        foreach (stockPurchaseFetchLegacyReceiveLineItems($pdo, $id) as $row) {
            $lineId = (int) ($row['id'] ?? 0);
            if ($lineId > 0 && isset($seenLineIds[$lineId])) {
                continue;
            }
            if ($lineId > 0) {
                $seenLineIds[$lineId] = true;
            }
            $row['image_url'] = dr_resolve_product_image_url(
                (int) ($row['image_product_id'] ?? $row['item_id'] ?? 0),
                (string) ($row['product_image'] ?? '')
            ) ?? '';
            $items[] = $row;
        }
    } catch (Throwable $e) {
        error_log('domestic_receive legacy items PO#' . $id . ': ' . $e->getMessage());
        flash('success_type', 'error');
        flash('success', 'Could not load purchase order lines for receiving. ' . $e->getMessage());
        redirect('view_po.php?id=' . $id);
    }
} else {
    $stmtItems = $pdo->prepare("
        SELECT pi.*, si.name as item_name, si.sku, si.stock_quantity as current_stock
        FROM stocks_po_items pi
        JOIN stocks_items si ON pi.item_id = si.id
        WHERE pi.po_id = ?
        ORDER BY pi.id ASC
    ");
    $stmtItems->execute([$id]);
    foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $lineId = (int) ($row['id'] ?? 0);
        if ($lineId > 0 && isset($seenLineIds[$lineId])) {
            continue;
        }
        if ($lineId > 0) {
            $seenLineIds[$lineId] = true;
        }

        $productRow = dr_lookup_product_for_stock_item(
            $pdo,
            (int) ($row['item_id'] ?? 0),
            trim((string) ($row['item_name'] ?? '')),
            trim((string) ($row['sku'] ?? '')),
            $hasProductImageCol,
            $hasProductMainImageCol
        );
        $row['image_product_id'] = $productRow ? (int) ($productRow['id'] ?? 0) : (int) ($row['item_id'] ?? 0);
        $row['product_image'] = $productRow
            ? dr_pick_product_image_file($productRow, $hasProductImageCol, $hasProductMainImageCol)
            : '';
        $row['image_url'] = dr_resolve_product_image_url((int) $row['image_product_id'], (string) $row['product_image']) ?? '';

        $items[] = $row;
    }
}

$noImageUrl = !empty($stockBasePath)
    ? rtrim((string) $stockBasePath, '/') . '/assets/images/no-image.png'
    : (function_exists('app_url') ? app_url('/stock/assets/images/no-image.png') : '/stock/assets/images/no-image.png');

$remainingTotal = 0.0;
$fullyReceived = false;
if (!empty($items)) {
    foreach ($items as $it) {
        $ordered = (float)($it['qty_ordered'] ?? 0);
        $received = (float)($it['qty_received'] ?? 0);
        $remainingTotal += max(0, $ordered - $received);
    }
    $fullyReceived = $remainingTotal <= 0;
}

// Reconcile status if quantities show fully received but status isn't updated.
if ($fullyReceived && (($po['status'] ?? '') !== 'Received')) {
    try {
        if ($isLegacyReceive) {
            $poCols = $pdo->query('SHOW COLUMNS FROM purchases')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $sets = ["status = 'Received'"];
            if (in_array('received_date', $poCols, true)) {
                $sets[] = 'received_date = NOW()';
            }
            if (in_array('updated_at', $poCols, true)) {
                $sets[] = 'updated_at = NOW()';
            }
            $pdo->exec('UPDATE purchases SET ' . implode(', ', $sets) . ' WHERE id = ' . (int) $id);
        } else {
            $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (in_array('updated_at', $poCols, true)) {
                $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received', updated_at = NOW() WHERE id = ?")->execute([$id]);
            } else {
                $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received' WHERE id = ?")->execute([$id]);
            }
        }
        $po['status'] = 'Received';
    } catch (Throwable $e) {
        // ignore
    }
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$itemsPayload = [];
foreach ($items as $item) {
    $ordered = (float) ($item['qty_ordered'] ?? 0);
    $received = (float) ($item['qty_received'] ?? 0);
    $imgUrl = trim((string) ($item['image_url'] ?? ''));
    if ($imgUrl === '') {
        $imgPid = (int) ($item['image_product_id'] ?? $item['item_id'] ?? 0);
        $imgFile = (string) ($item['product_image'] ?? '');
        $resolvedImg = dr_resolve_product_image_url($imgPid, $imgFile);
        $imgUrl = $resolvedImg ?: $noImageUrl;
    }
    $itemsPayload[] = [
        'id' => (int) ($item['id'] ?? 0),
        'item_id' => (int) ($item['item_id'] ?? 0),
        'item_name' => (string) ($item['item_name'] ?? ''),
        'sku' => (string) ($item['sku'] ?? ''),
        'qty_ordered' => $ordered,
        'qty_received' => $received,
        'image_url' => $imgUrl,
    ];
}

$warehousesPayload = array_map(static function ($wh) {
    return [
        'id' => (int) ($wh['id'] ?? 1),
        'name' => (string) ($wh['name'] ?? 'Warehouse'),
        'code' => (string) ($wh['code'] ?? 'WH'),
    ];
}, $warehouseList);

$poPayload = [
    'id' => $id,
    'po_number' => (string) ($po['po_number'] ?? ('PO-' . $id)),
    'supplier_name' => (string) ($po['supplier_name'] ?? ''),
    'status' => (string) ($po['status'] ?? ''),
    'purchase_type' => $poType,
    'created_at' => (string) ($po['created_at'] ?? ''),
    'po_table' => $isLegacyReceive ? 'purchases' : 'stocks_purchase_orders',
];

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

$page_title = 'Receive stock - ' . ($poPayload['po_number'] !== '' ? $poPayload['po_number'] : '#' . $id);
$employeeHeaderTitle = 'Receive stock';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk';

$assetVersion = max(
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.css') ?: 0),
    time()
);

include __DIR__ . '/../../includes/header.php';
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
        <div class="alert alert-warning m-3">JavaScript is required to receive stock.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'purchases-receive',
            'data' => [
                'indexUrl' => 'index.php',
                'viewUrl' => 'view_po.php',
                'formAction' => 'domestic_receive_process.php',
                'productsUrl' => '../products/index.php',
                'auditUrl' => 'receipt_audit.php',
                'noImageUrl' => $noImageUrl,
                'fullyReceived' => (bool) $fullyReceived,
                'po' => $poPayload,
                'items' => $itemsPayload,
                'warehouses' => $warehousesPayload,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"purchases-receive","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
