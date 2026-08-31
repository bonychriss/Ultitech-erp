<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
requireLogin();

$salesFunctionsFile = dirname(__DIR__, 4) . '/modules/sales/functions.php';
if (is_file($salesFunctionsFile)) {
    require_once $salesFunctionsFile;
}

/**
 * @return array{0:string,1:bool}
 */
function replenishment_schema_hints(PDO $db): array
{
    static $cache = [];
    $key = spl_object_id($db);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $lineQtyExpr = 'soi.quantity';
    $hasShippedQty = false;
    try {
        $soiCols = $db->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('shipped_quantity', $soiCols, true)) {
            $hasShippedQty = true;
            $lineQtyExpr = 'GREATEST(soi.quantity - COALESCE(soi.shipped_quantity, 0), 0)';
        }
    } catch (Throwable $e) {
    }

    $cache[$key] = [$lineQtyExpr, $hasShippedQty];
    return $cache[$key];
}

function replenishment_open_order_filter(array $soCols, bool $hasShippedQty): string
{
    $filter = "so.status NOT IN ('cancelled', 'canceled', 'shipped', 'delivered')";
    if (!$hasShippedQty && in_array('shipped_at', $soCols, true)) {
        $filter .= " AND (so.shipped_at IS NULL OR so.shipped_at = '0000-00-00 00:00:00' OR so.shipped_at = '')";
    }

    return $filter;
}

function replenishment_committed_status_filter(): string
{
    return "so.status IN ('confirmed', 'invoiced', 'paid', 'processing', 'on_hold')";
}

/**
 * @return array<int, PDO>
 */
function replenishment_resolve_sales_pdos(): array
{
    global $pdo, $control_pdo;

    $connections = [];
    $seen = [];

    $add = static function ($conn) use (&$connections, &$seen): void {
        if (!$conn instanceof PDO) {
            return;
        }
        $key = spl_object_id($conn);
        if (isset($seen[$key])) {
            return;
        }
        try {
            $conn->query('SELECT 1 FROM sales_order_items LIMIT 1');
            $seen[$key] = true;
            $connections[] = $conn;
        } catch (Throwable $e) {
        }
    };

    $add($pdo ?? null);
    if (function_exists('sales_pdo')) {
        try {
            $add(sales_pdo());
        } catch (Throwable $e) {
        }
    }
    $add($control_pdo ?? null);

    return $connections;
}

/**
 * @return array<int, array<string, mixed>>
 */
function replenishment_query_product_sources(PDO $db, int $productId): array
{
    if ($productId <= 0) {
        return [];
    }

    try {
        $db->query('SELECT 1 FROM sales_order_items LIMIT 1');
    } catch (Throwable $e) {
        return [];
    }

    $soCols = [];
    $soiCols = [];
    $invCols = [];
    $hasCustomers = false;
    $hasInvoices = false;
    $hasInvoiceItems = false;
    $hasProducts = false;

    try {
        $soCols = $db->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $soiCols = $db->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    try {
        $hasCustomers = (bool) $db->query("SHOW TABLES LIKE 'customers'")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $hasProducts = (bool) $db->query("SHOW TABLES LIKE 'products'")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $hasInvoices = (bool) $db->query("SHOW TABLES LIKE 'invoices'")->fetchColumn();
        if ($hasInvoices) {
            $invCols = $db->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    } catch (Throwable $e) {
    }
    try {
        $hasInvoiceItems = (bool) $db->query("SHOW TABLES LIKE 'invoice_items'")->fetchColumn();
    } catch (Throwable $e) {
    }

    if (in_array('order_number', $soCols, true)) {
        $orderNumberExpr = 'so.order_number';
    } elseif (in_array('formatted_number', $soCols, true)) {
        $orderNumberExpr = 'so.formatted_number';
    } else {
        $orderNumberExpr = "CONCAT('SO-', so.id)";
    }

    [$lineQtyExpr, $hasShippedQty] = replenishment_schema_hints($db);

    $customerSelect = $hasCustomers ? 'c.company_name AS customer_name' : "'' AS customer_name";
    $customerJoin = $hasCustomers ? 'LEFT JOIN customers c ON so.customer_id = c.id' : '';

    $invoiceNumberExpr = "CONCAT('INV-', i.id)";
    $invoiceDateExpr = 'NULL';
    $invoiceStatusExpr = "''";
    if ($hasInvoices) {
        $invoiceNumberExpr = in_array('invoice_number', $invCols, true)
            ? 'i.invoice_number'
            : "CONCAT('INV-', i.id)";
        $invoiceDateExpr = in_array('invoice_date', $invCols, true)
            ? 'i.invoice_date'
            : (in_array('created_at', $invCols, true) ? 'i.created_at' : 'NULL');
        $invoiceStatusExpr = in_array('status', $invCols, true) ? 'i.status' : "''";
    }

    $productMatch = 'soi.product_id = ?';
    $params = [$productId];
    if ($hasProducts) {
        $productMatch = '(soi.product_id = ? OR soi.product_id IN (SELECT id FROM products WHERE id = ? OR product_code = (SELECT product_code FROM products WHERE id = ? LIMIT 1)))';
        $params = [$productId, $productId, $productId];
    }

    $openOrderFilter = replenishment_open_order_filter($soCols, $hasShippedQty);
    $detailStatusFilter = replenishment_committed_status_filter();

    $queries = [];

    if ($hasInvoices) {
        $invoiceCancelFilter = in_array('status', $invCols, true)
            ? " AND (i.status IS NULL OR i.status NOT IN ('cancelled', 'canceled'))"
            : '';
        $queries[] = [
            'sql' => "
                SELECT
                    so.id AS order_id,
                    {$orderNumberExpr} AS order_number,
                    so.status AS order_status,
                    COALESCE(so.created_at, so.quote_date) AS order_date,
                    {$lineQtyExpr} AS line_qty,
                    {$customerSelect},
                    i.id AS invoice_id,
                    {$invoiceNumberExpr} AS invoice_number,
                    {$invoiceDateExpr} AS invoice_date,
                    {$invoiceStatusExpr} AS invoice_status
                FROM sales_order_items soi
                INNER JOIN sales_orders so ON soi.order_id = so.id
                INNER JOIN invoices i ON i.order_id = so.id
                {$customerJoin}
                WHERE {$productMatch}
                  AND {$detailStatusFilter}
                  AND {$openOrderFilter}
                  {$invoiceCancelFilter}
                  AND {$lineQtyExpr} > 0
            ",
            'params' => $params,
        ];
    }

    $invoiceSelect = $hasInvoices
        ? "i.id AS invoice_id, {$invoiceNumberExpr} AS invoice_number, {$invoiceDateExpr} AS invoice_date, {$invoiceStatusExpr} AS invoice_status"
        : 'NULL AS invoice_id, NULL AS invoice_number, NULL AS invoice_date, NULL AS invoice_status';
    $invoiceJoin = $hasInvoices ? 'LEFT JOIN invoices i ON i.order_id = so.id' : '';
    if ($hasInvoices && in_array('status', $invCols, true)) {
        $invoiceJoin = "LEFT JOIN invoices i ON i.order_id = so.id AND (i.status IS NULL OR i.status NOT IN ('cancelled', 'canceled'))";
    }

    $queries[] = [
        'sql' => "
            SELECT
                so.id AS order_id,
                {$orderNumberExpr} AS order_number,
                so.status AS order_status,
                COALESCE(so.created_at, so.quote_date) AS order_date,
                {$lineQtyExpr} AS line_qty,
                {$customerSelect},
                {$invoiceSelect}
            FROM sales_order_items soi
            INNER JOIN sales_orders so ON soi.order_id = so.id
            {$customerJoin}
            {$invoiceJoin}
            WHERE {$productMatch}
              AND {$detailStatusFilter}
              AND {$openOrderFilter}
              AND {$lineQtyExpr} > 0
        ",
        'params' => $params,
    ];

    if ($hasInvoiceItems && $hasInvoices) {
        $iiCols = [];
        try {
            $iiCols = $db->query('SHOW COLUMNS FROM invoice_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $iiCols = [];
        }
        $iiQtyExpr = in_array('quantity', $iiCols, true) ? 'ii.quantity' : '1';
        $iiProductMatch = in_array('product_id', $iiCols, true)
            ? ( $hasProducts
                ? '(ii.product_id = ? OR ii.product_id IN (SELECT id FROM products WHERE id = ? OR product_code = (SELECT product_code FROM products WHERE id = ? LIMIT 1)))'
                : 'ii.product_id = ?' )
            : '1 = 0';
        $iiParams = $hasProducts ? [$productId, $productId, $productId] : [$productId];
        $invoiceCancelFilter = in_array('status', $invCols, true)
            ? " AND (i.status IS NULL OR i.status NOT IN ('cancelled', 'canceled'))"
            : '';
        $orderJoin = in_array('order_id', $invCols, true) ? 'LEFT JOIN sales_orders so ON so.id = i.order_id' : 'LEFT JOIN sales_orders so ON 1 = 0';
        $invCustomerJoin = ($hasCustomers && in_array('customer_id', $invCols, true))
            ? 'LEFT JOIN customers c ON c.id = COALESCE(so.customer_id, i.customer_id)'
            : $customerJoin;
        $queries[] = [
            'sql' => "
                SELECT
                    so.id AS order_id,
                    {$orderNumberExpr} AS order_number,
                    so.status AS order_status,
                    COALESCE(i.invoice_date, i.created_at, so.created_at) AS order_date,
                    {$iiQtyExpr} AS line_qty,
                    {$customerSelect},
                    i.id AS invoice_id,
                    {$invoiceNumberExpr} AS invoice_number,
                    {$invoiceDateExpr} AS invoice_date,
                    {$invoiceStatusExpr} AS invoice_status
                FROM invoice_items ii
                INNER JOIN invoices i ON ii.invoice_id = i.id
                {$orderJoin}
                {$invCustomerJoin}
                WHERE {$iiProductMatch}
                  {$invoiceCancelFilter}
                  AND (so.id IS NULL OR ({$detailStatusFilter} AND {$openOrderFilter}))
            ",
            'params' => $iiParams,
        ];
    }

    $merged = [];
    $seenKeys = [];

    foreach ($queries as $query) {
        $sql = $query['sql'] . ' ORDER BY order_date DESC, order_id DESC';
        $qParams = $query['params'];
        if (function_exists('salesCompanyScopeSql')) {
            $scope = salesCompanyScopeSql('sales_orders', 'so');
            if (!empty($scope[0])) {
                $sql = str_replace('WHERE ', 'WHERE 1=1' . $scope[0] . ' AND ', $sql);
                $qParams = array_merge($qParams, $scope[1] ?? []);
            }
        }

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($qParams);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $orderId = (int) ($row['order_id'] ?? 0);
                $invoiceId = (int) ($row['invoice_id'] ?? 0);
                $dedupeKey = $orderId . ':' . $invoiceId . ':' . ($row['invoice_number'] ?? '');
                if (isset($seenKeys[$dedupeKey])) {
                    continue;
                }
                $seenKeys[$dedupeKey] = true;
                $merged[] = $row;
            }
        } catch (Throwable $e) {
            error_log('replenishment_query_product_sources: ' . $e->getMessage());
        }
    }

    return $merged;
}

/**
 * @return array<int, array<string, mixed>>
 */
function replenishment_fetch_product_demand_sources(int $productId): array
{
    $merged = [];
    $seenKeys = [];

    foreach (replenishment_resolve_sales_pdos() as $db) {
        foreach (replenishment_query_product_sources($db, $productId) as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $dedupeKey = $orderId . ':' . $invoiceId . ':' . ($row['invoice_number'] ?? '');
            if (isset($seenKeys[$dedupeKey])) {
                continue;
            }
            $seenKeys[$dedupeKey] = true;
            $merged[] = $row;
        }
    }

    usort($merged, static function ($a, $b) {
        return strcmp((string) ($b['order_date'] ?? ''), (string) ($a['order_date'] ?? ''));
    });

    return $merged;
}

$replenishmentApiUrl = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'replenishment.php'), '?') . '?action=invoices';

if (isset($_GET['action']) && $_GET['action'] === 'invoices') {
    header('Content-Type: application/json; charset=utf-8');

    $productId = (int) ($_GET['product_id'] ?? 0);
    if ($productId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid product id.', 'items' => []]);
        exit;
    }

    $rows = replenishment_fetch_product_demand_sources($productId);
    $invoiceViewBase = function_exists('app_url')
        ? app_url('modules/sales/invoices/view.php?id=')
        : '/modules/sales/invoices/view.php?id=';
    $orderViewBase = function_exists('app_url')
        ? app_url('modules/sales/orders/view.php?id=')
        : '/modules/sales/orders/view.php?id=';

    $out = [];
    foreach ($rows as $row) {
        $invoiceId = (int) ($row['invoice_id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);
        $out[] = [
            'order_id' => $orderId,
            'order_number' => (string) ($row['order_number'] ?? ($orderId > 0 ? 'SO-' . $orderId : '')),
            'order_status' => (string) ($row['order_status'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? ''),
            'line_qty' => (float) ($row['line_qty'] ?? 0),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceId > 0 ? (string) ($row['invoice_number'] ?? ('INV-' . $invoiceId)) : '',
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'invoice_status' => (string) ($row['invoice_status'] ?? ''),
            'invoice_url' => $invoiceId > 0 ? $invoiceViewBase . $invoiceId : '',
            'order_url' => $orderId > 0 ? $orderViewBase . $orderId : '',
        ];
    }

    echo json_encode([
        'ok' => true,
        'product_id' => $productId,
        'count' => count($out),
        'items' => $out,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ensure schema
try {
    $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('hide_replenishment', $cols, true)) {
        $pdo->exec('ALTER TABLE products ADD COLUMN hide_replenishment TINYINT(1) DEFAULT 0');
    }
} catch (Exception $e) {
}

// Logic: products with negative warehouse stock only
$items = [];
$sales_tables_exist = false;
try {
    $pdo->query('SELECT 1 FROM sales_order_items LIMIT 1');
    $sales_tables_exist = true;
} catch (Throwable $e) {
}

if ($sales_tables_exist) {
    [$pendingQtyExpr, $hasShippedQtyMain] = replenishment_schema_hints($pdo);
    $pendingShippedFilter = $hasShippedQtyMain ? " AND {$pendingQtyExpr} > 0" : '';
    $soColsMain = [];
    try {
        $soColsMain = $pdo->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
    }
    $pendingStatusFilter = replenishment_committed_status_filter();
    $pendingOpenFilter = replenishment_open_order_filter($soColsMain, $hasShippedQtyMain);

    $sql = "
        SELECT 
            p.id, 
            p.product_code, 
            p.name, 
            p.main_image,
            COALESCE(s.quantity, 0) as current_stock,
            (
                SELECT COALESCE(SUM({$pendingQtyExpr}), 0)
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.order_id = so.id
                WHERE soi.product_id = p.id
                AND {$pendingStatusFilter}
                AND {$pendingOpenFilter}
                {$pendingShippedFilter}
            ) as pending_demand,
            p.hide_replenishment
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id
        WHERE (p.hide_replenishment IS NULL OR p.hide_replenishment = 0)
        AND COALESCE(s.quantity, 0) < 0
        ORDER BY current_stock ASC
    ";
    $stmt = $pdo->query($sql);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = "
        SELECT 
            p.id, 
            p.product_code, 
            p.name, 
            p.main_image,
            COALESCE(s.quantity, 0) as current_stock,
            0 as pending_demand,
            p.hide_replenishment
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id
        WHERE (p.hide_replenishment IS NULL OR p.hide_replenishment = 0)
        AND COALESCE(s.quantity, 0) < 0
        ORDER BY COALESCE(s.quantity, 0) ASC
    ";
    try {
        $stmt = $pdo->query($sql);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $items = [];
    }
}

$search = trim((string) ($_GET['search'] ?? ''));

$shortageCount = 0;
foreach ($items as $row) {
    if ((int) ($row['current_stock'] ?? 0) < 0) {
        $shortageCount++;
    }
}

$purchasesHref = '../purchases/index.php';
$stockReportHref = 'stock.php';

require_once __DIR__ . '/../../config/paths.php';

$invoiceViewBase = function_exists('app_url')
    ? app_url('modules/sales/invoices/view.php?id=')
    : '/modules/sales/invoices/view.php?id=';
$invoicePrintBase = function_exists('app_url')
    ? app_url('modules/sales/invoices/print.php?id=')
    : '/modules/sales/invoices/print.php?id=';
$orderViewBase = function_exists('app_url')
    ? app_url('modules/sales/orders/view.php?id=')
    : '/modules/sales/orders/view.php?id=';

$itemsPayload = [];
foreach ($items as $item) {
    $productId = (int) ($item['id'] ?? 0);
    $filename = (string) ($item['main_image'] ?? '');
    $img = function_exists('stock_product_list_image_url')
        ? stock_product_list_image_url($productId, $filename, 'medium', (string) ($stockBasePath ?? ''))
        : '';

    $sourceRows = $productId > 0 ? replenishment_fetch_product_demand_sources($productId) : [];
    $demandSources = [];
    $references = [];
    $seenRefs = [];

    foreach ($sourceRows as $row) {
        $invoiceId = (int) ($row['invoice_id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);
        $demandSources[] = [
            'order_id' => $orderId,
            'order_number' => (string) ($row['order_number'] ?? ($orderId > 0 ? 'SO-' . $orderId : '')),
            'order_status' => (string) ($row['order_status'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? ''),
            'line_qty' => (float) ($row['line_qty'] ?? 0),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceId > 0 ? (string) ($row['invoice_number'] ?? ('INV-' . $invoiceId)) : '',
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'invoice_status' => (string) ($row['invoice_status'] ?? ''),
            'invoice_url' => $invoiceId > 0 ? $invoiceViewBase . $invoiceId : '',
            'invoice_print_url' => $invoiceId > 0 ? $invoicePrintBase . $invoiceId : '',
            'order_url' => $orderId > 0 ? $orderViewBase . $orderId : '',
        ];

        if ($invoiceId > 0) {
            $refKey = 'inv:' . $invoiceId;
            if (!isset($seenRefs[$refKey])) {
                $seenRefs[$refKey] = true;
                $references[] = [
                    'type' => 'invoice',
                    'id' => $invoiceId,
                    'label' => (string) ($row['invoice_number'] ?? ('INV-' . $invoiceId)),
                    'url' => $invoiceViewBase . $invoiceId,
                    'print_url' => $invoicePrintBase . $invoiceId,
                ];
            }
        } elseif ($orderId > 0) {
            $refKey = 'so:' . $orderId;
            if (!isset($seenRefs[$refKey])) {
                $seenRefs[$refKey] = true;
                $references[] = [
                    'type' => 'order',
                    'id' => $orderId,
                    'label' => (string) ($row['order_number'] ?? ('SO-' . $orderId)),
                    'url' => $orderViewBase . $orderId,
                ];
            }
        }
    }

    $itemsPayload[] = [
        'id' => $productId,
        'product_code' => (string) ($item['product_code'] ?? ''),
        'name' => (string) ($item['name'] ?? ''),
        'main_image' => $filename,
        'image_url' => (string) $img,
        'current_stock' => (int) ($item['current_stock'] ?? 0),
        'pending_demand' => (int) ($item['pending_demand'] ?? 0),
        'references' => $references,
        'demand_sources' => $demandSources,
    ];
}

$flashMessage = '';
$flashType = '';

$base = !empty($stockBasePath)
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

$page_title = 'Replenishment Report';
$employeeHeaderTitle = 'Replenishment';
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
        <div class="alert alert-warning m-3">JavaScript is required to view the replenishment report.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'reports-replenishment',
            'data' => [
                'items' => $itemsPayload,
                'initialSearch' => (string) $search,
                'shortageCount' => (int) $shortageCount,
                'invoicesApiUrl' => $replenishmentApiUrl,
                'flashMessage' => $flashMessage,
                'flashType' => $flashType,
                'urls' => [
                    'stockReport' => $stockReportHref,
                    'purchases' => $purchasesHref,
                    'createDomestic' => '../purchases/domestic_create.php',
                    'createImport' => '../purchases/domestic_create.php?purchase_type=import',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"reports-replenishment","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
