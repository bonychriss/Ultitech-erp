<?php
// stock/modules/purchases/index.php — React purchases desk (products-style)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/paths.php';
require_once __DIR__ . '/../../includes/shipment-functions.php';

$wfPath = __DIR__ . '/purchase_workflow.php';
if (is_file($wfPath)) {
    require_once $wfPath;
} else {
    if (!defined('PURCHASE_PROC_STANDARD')) {
        define('PURCHASE_PROC_STANDARD', 'standard');
    }
    if (!function_exists('ensurePurchaseWorkflowSchema')) {
        function ensurePurchaseWorkflowSchema(PDO $pdo): void { /* no-op */ }
    }
}
requireLogin();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$company_id = (int) (currentCompanyId() ?? 0);

/** @var array{title: string, message: string, variant: string}|null $poCreateSuccess */
$poCreateSuccess = null;
if (!empty($_SESSION['stock_po_create_success']) && is_array($_SESSION['stock_po_create_success'])) {
    $poCreateSuccess = $_SESSION['stock_po_create_success'];
    unset($_SESSION['stock_po_create_success']);
}

$hasLegacySuppliersTable = false;
try {
    $hasLegacySuppliersTable = (bool) $pdo->query("SHOW TABLES LIKE 'suppliers'")->fetchColumn();
} catch (Exception $e) {
    $hasLegacySuppliersTable = false;
}

if (!function_exists('purchasesTableExists')) {
    function purchasesTableExists(PDO $pdo, string $table): bool
    {
        try {
            return (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }
}

try {
    $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('purchase_type', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN purchase_type ENUM('domestic','import') NOT NULL DEFAULT 'domestic' AFTER supplier_id");
    }
    if (!in_array('supplier_invoice_no', $cols, true)) {
        $pdo->exec('ALTER TABLE stocks_purchase_orders ADD COLUMN supplier_invoice_no VARCHAR(50) NULL AFTER purchase_type');
    }
} catch (Exception $e) {
}

ensure_shipment_po_linking_schema($pdo);
ensurePurchaseWorkflowSchema($pdo);

$poCols = [];
try {
    $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $poCols = [];
}
$hasHeaderTotal = in_array('total_amount', $poCols, true);
$hasHeaderTax = in_array('tax_amount', $poCols, true);

$showDomestic = isset($_GET['domestic']) ? (int) $_GET['domestic'] : 1;
$showImport = isset($_GET['import']) ? (int) $_GET['import'] : 1;
$showDomestic = $showDomestic ? 1 : 0;
$showImport = $showImport ? 1 : 0;
if ($showDomestic === 0 && $showImport === 0) {
    $showDomestic = 1;
    $showImport = 1;
}

$supplierNameExpr = $hasLegacySuppliersTable
    ? "COALESCE(ss.name, ls.name, CONCAT('Supplier #', p.supplier_id))"
    : "COALESCE(ss.name, CONCAT('Supplier #', p.supplier_id))";

$hasPurchaseAttachments = purchasesTableExists($pdo, 'stocks_purchase_attachments');
$attachmentCountExpr = $hasPurchaseAttachments
    ? 'COALESCE((SELECT COUNT(*) FROM stocks_purchase_attachments pa WHERE pa.purchase_id = p.id), 0)'
    : '0';

$linesTotalExpr = 'COALESCE((
            SELECT SUM(COALESCE(pi.qty_ordered, 0) * COALESCE(pi.unit_cost, 0))
            FROM stocks_po_items pi
            WHERE pi.po_id = p.id
        ), 0)';
$taxExpr = $hasHeaderTax ? 'COALESCE(p.tax_amount, 0)' : '0';
$grandTotalExpr = $hasHeaderTotal
    ? "COALESCE(p.total_amount, ($linesTotalExpr + $taxExpr))"
    : "($linesTotalExpr + $taxExpr)";

$sql = "SELECT p.*,
        p.po_number as purchase_no,
        $supplierNameExpr as supplier_name,
        $attachmentCountExpr as attachment_count,
        COALESCE((
            SELECT COUNT(*)
            FROM stocks_po_items pi
            WHERE pi.po_id = p.id
        ), 0) as item_count,
        COALESCE((
            SELECT SUM(COALESCE(pi.qty_ordered, 0))
            FROM stocks_po_items pi
            WHERE pi.po_id = p.id
        ), 0) as total_qty,
        COALESCE((
            SELECT GROUP_CONCAT(si.name SEPARATOR ', ')
            FROM stocks_po_items pi
            JOIN stocks_items si ON pi.item_id = si.id
            WHERE pi.po_id = p.id
            LIMIT 1
        ), 'No items') as product_name,
        (
            SELECT COALESCE(si.sku, si.name)
            FROM stocks_po_items pi
            JOIN stocks_items si ON pi.item_id = si.id
            WHERE pi.po_id = p.id
            LIMIT 1
        ) as product_code,
        $grandTotalExpr as total_amount,
        CASE
            WHEN EXISTS (SELECT 1 FROM shipments sh WHERE sh.stocks_po_id = p.id) THEN 1
            WHEN EXISTS (SELECT 1 FROM shipment_items si WHERE si.purchase_id = p.id) THEN 1
            ELSE 0
        END AS has_shipment,
        COALESCE(
            (SELECT sh.id FROM shipments sh WHERE sh.stocks_po_id = p.id ORDER BY sh.id DESC LIMIT 1),
            (SELECT MIN(si.shipment_id) FROM shipment_items si WHERE si.purchase_id = p.id)
        ) AS linked_shipment_id,
        'stock' AS po_source
        FROM stocks_purchase_orders p
        LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id";
$sql .= $hasLegacySuppliersTable ? "\n        LEFT JOIN suppliers ls ON p.supplier_id = ls.id" : '';
$types = [];
if ($showDomestic) {
    $types[] = "'domestic'";
}
if ($showImport) {
    $types[] = "'import'";
}
$sql .= "\n        WHERE p.purchase_type IN (" . implode(',', $types) . ')';
if (in_array('company_id', $poCols, true) && $company_id > 0) {
    $sql .= "\n        AND p.company_id = " . $company_id;
}
$sql .= '
        ORDER BY p.created_at DESC, p.id DESC';

$purchases = [];
try {
    $purchases = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('Stock POs index query error: ' . $e->getMessage());
}

$hasLegacyPurchases = purchasesTableExists($pdo, 'purchases') && purchasesTableExists($pdo, 'purchase_items');
$legacyPurchases = [];
if ($hasLegacyPurchases && $showDomestic) {
    $legacyPoCols = [];
    try {
        $legacyPoCols = $pdo->query('SHOW COLUMNS FROM purchases')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $legacyPoCols = [];
    }

    $legacySupplierNameExpr = $hasLegacySuppliersTable
        ? "COALESCE(ss.name, ls.name, CONCAT('Supplier #', p.supplier_id))"
        : "COALESCE(ss.name, CONCAT('Supplier #', p.supplier_id))";

    $legacySql = "SELECT p.*,
            p.purchase_no as purchase_no,
            $legacySupplierNameExpr as supplier_name,
            0 as attachment_count,
            COALESCE((
                SELECT COUNT(*)
                FROM purchase_items pi
                WHERE pi.purchase_id = p.id
            ), 0) as item_count,
            COALESCE((
                SELECT SUM(COALESCE(pi.quantity, 0))
                FROM purchase_items pi
                WHERE pi.purchase_id = p.id
            ), 0) as total_qty,
            COALESCE((
                SELECT GROUP_CONCAT(pr.name SEPARATOR ', ')
                FROM purchase_items pi
                LEFT JOIN products pr ON pi.product_id = pr.id
                WHERE pi.purchase_id = p.id
                LIMIT 1
            ), 'No items') as product_name,
            (
                SELECT pr.product_code
                FROM purchase_items pi
                LEFT JOIN products pr ON pi.product_id = pr.id
                WHERE pi.purchase_id = p.id
                LIMIT 1
            ) as product_code,
            COALESCE(p.total_amount, (
                SELECT SUM(COALESCE(pi.quantity, 0) * COALESCE(pi.unit_price, 0))
                FROM purchase_items pi
                WHERE pi.purchase_id = p.id
            ), 0) as total_amount,
            'domestic' AS purchase_type,
            1 AS has_shipment,
            NULL AS linked_shipment_id,
            'legacy' AS po_source
            FROM purchases p
            LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id";
    $legacySql .= $hasLegacySuppliersTable ? "\n            LEFT JOIN suppliers ls ON p.supplier_id = ls.id" : '';
    if (in_array('company_id', $legacyPoCols, true) && $company_id > 0) {
        $legacySql .= "\n            WHERE p.company_id = " . $company_id;
    }
    $legacySql .= '
            ORDER BY p.created_at DESC, p.id DESC';

    try {
        $legacyPurchases = $pdo->query($legacySql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $legacyPurchases = [];
    }
}

if (!empty($legacyPurchases)) {
    $purchases = array_merge($purchases, $legacyPurchases);
    usort($purchases, static function (array $a, array $b): int {
        $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
        $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        if ($tb !== $ta) {
            return $tb <=> $ta;
        }

        return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
    });
}

$settings = [];
try {
    $settings = getCompanySettings($pdo);
} catch (Exception $e) {
    $settings = [
        'currency' => 'USD',
        'exchange_rate' => 1,
    ];
}
$defaultCurrencyCode = strtoupper(trim((string) ($settings['currency'] ?? 'TZS')));
if ($defaultCurrencyCode === '') {
    $defaultCurrencyCode = 'TZS';
}
$currency = getCurrencySymbol($defaultCurrencyCode);
$rate = $settings['exchange_rate'] ?? 1;

$formatPoTotalDisplay = static function (array $po) use ($defaultCurrencyCode): string {
    $code = strtoupper(trim((string) ($po['currency'] ?? '')));
    if ($code === '') {
        $code = $defaultCurrencyCode;
    }
    $amount = (float) ($po['total_amount'] ?? 0);
    $symbol = getCurrencySymbol($code);
    if ($symbol === '$' && $code !== 'USD') {
        return $code . ' ' . number_format($amount, 2);
    }

    return $symbol . number_format($amount, 2);
};

$poStatusClass = static function (string $status): string {
    $map = [
        'Draft' => 'po-desk-badge--draft',
        'Pending' => 'po-desk-badge--pending',
        'Pending Supplier' => 'po-desk-badge--pending',
        'Pending Approval' => 'po-desk-badge--pending',
        'Approved' => 'po-desk-badge--approved',
        'Supplier Responded' => 'po-desk-badge--pending',
        'Negotiation Requested' => 'po-desk-badge--pending',
        'Received' => 'po-desk-badge--received',
        'Cancelled' => 'po-desk-badge--rejected',
    ];

    return $map[$status] ?? 'po-desk-badge--draft';
};

$isAdmin = function_exists('stockPurchaseIsAdmin') ? stockPurchaseIsAdmin() : hasRole('admin');
$search = trim((string) ($_GET['search'] ?? ''));

$purchasesPayload = [];
foreach ($purchases as $po) {
    $status = (string) ($po['status'] ?? '');
    $poTypeRaw = trim((string) ($po['purchase_type'] ?? 'domestic'));
    $poType = in_array($poTypeRaw, ['domestic', 'import'], true) ? $poTypeRaw : 'domestic';
    $isImport = $poType === 'import';
    $rowWf = $po['procurement_workflow'] ?? (defined('PURCHASE_PROC_STANDARD') ? PURCHASE_PROC_STANDARD : 'standard');
    $hasShipment = (int) ($po['has_shipment'] ?? 0) === 1;
    $attCount = (int) ($po['attachment_count'] ?? 0);
    if ($attCount === 0 && !empty($po['invoice_attachment'])) {
        $attCount = 1;
    }

    $canEdit = function_exists('purchaseOrderEditableStatuses')
        ? in_array($status, purchaseOrderEditableStatuses($rowWf), true)
        : in_array($status, ['Pending', 'Supplier Responded', 'Draft'], true);

    $canCancel = function_exists('purchaseCancelableStatuses')
        ? in_array($status, purchaseCancelableStatuses($rowWf), true)
        : in_array($status, ['Pending', 'Supplier Responded'], true);

    $canReceive = !in_array($status, ['Received', 'Cancelled'], true)
        && (!function_exists('purchaseStatusesBlockingReceive') || !in_array($status, purchaseStatusesBlockingReceive(), true))
        && (!$isImport || $hasShipment);

    $statusLabel = function_exists('purchaseDisplayStatusLabel')
        ? purchaseDisplayStatusLabel($status, $rowWf)
        : ($status !== '' ? $status : '—');

    $amount = (float) ($po['total_amount'] ?? 0);
    $converted = function_exists('convertCurrency')
        ? (float) convertCurrency($amount, $rate)
        : $amount * (float) $rate;

    $purchasesPayload[] = [
        'id' => (int) ($po['id'] ?? 0),
        'source' => (string) ($po['po_source'] ?? 'stock'),
        'purchase_no' => (string) ($po['purchase_no'] ?? ''),
        'supplier_name' => (string) ($po['supplier_name'] ?? ''),
        'product_name' => (string) ($po['product_name'] ?? ''),
        'product_code' => (string) ($po['product_code'] ?? ''),
        'item_count' => (int) ($po['item_count'] ?? 0),
        'total_qty' => isset($po['total_qty']) ? (float) $po['total_qty'] : 0,
        'total_amount' => $amount,
        'total_amount_converted' => $converted,
        'total_display' => $formatPoTotalDisplay($po),
        'currency' => strtoupper(trim((string) ($po['currency'] ?? $defaultCurrencyCode))),
        'status' => $status,
        'status_label' => (string) $statusLabel,
        'status_class' => $poStatusClass($status),
        'payment_status' => strtolower(trim((string) ($po['payment_status'] ?? 'unpaid'))),
        'purchase_type' => $poType,
        'created_at' => (string) ($po['created_at'] ?? ''),
        'attachment_count' => $attCount,
        'has_invoice_attachment' => !empty($po['invoice_attachment']),
        'has_shipment' => $hasShipment,
        'linked_shipment_id' => !empty($po['linked_shipment_id']) ? (int) $po['linked_shipment_id'] : null,
        'can_edit' => $canEdit,
        'can_receive' => $canReceive,
        'can_cancel' => $canCancel,
    ];
}

$activePoTab = ($showDomestic && $showImport) ? 'all' : ($showDomestic ? 'domestic' : 'import');

$flashMessage = '';
$flashType = 'success';
if (!empty($_SESSION['flash_message'])) {
    $flashMessage = (string) $_SESSION['flash_message'];
    $flashType = (string) ($_SESSION['flash_type'] ?? 'success');
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
} elseif (!empty($_SESSION['success'])) {
    $flashMessage = (string) $_SESSION['success'];
    $flashType = (string) ($_SESSION['success_type'] ?? 'success');
    if ($flashType === 'danger') {
        $flashType = 'error';
    }
    unset($_SESSION['success'], $_SESSION['success_type']);
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

$page_title = 'Purchases';
$employeeHeaderTitle = 'Purchases';
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
        <div class="alert alert-warning m-3">JavaScript is required to view purchase orders.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'purchases-list',
            'data' => [
                'purchases' => $purchasesPayload,
                'search' => $search,
                'activeTab' => $activePoTab,
                'isAdmin' => (bool) $isAdmin,
                'currencySymbol' => $currency,
                'stats' => [
                    'value_note' => 'Matching current filters',
                ],
                'createSuccess' => $poCreateSuccess,
                'flashMessage' => $flashMessage,
                'flashType' => $flashType,
                'urls' => [
                    'createDomestic' => 'domestic_create.php',
                    'createImport' => 'create.php',
                    'view' => 'view_po.php',
                    'edit' => 'edit.php',
                    'receive' => 'domestic_receive.php',
                    'cancel' => 'cancel.php',
                    'delete' => 'delete.php',
                    'clone' => 'create.php',
                    'shipmentCreate' => '../shipments/create.php',
                    'shipmentView' => '../shipments/view.php',
                    'invoice' => 'download_invoice.php',
                    'attachment' => 'open_attachment.php',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"purchases-list","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
