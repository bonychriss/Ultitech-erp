<?php
// stock/modules/shipments/view.php — React shipment view desk
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
require_once '../../includes/shipment-functions.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

ensure_shipment_po_linking_schema($pdo);

// Handle Add Package
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_package') {
    $shipment_id = (int) ($_POST['shipment_id'] ?? 0);
    $pkg_num = trim((string) ($_POST['package_number'] ?? ''));
    $track = trim((string) ($_POST['tracking_number'] ?? ''));
    $dims = trim((string) ($_POST['dimensions'] ?? ''));
    $weight = $_POST['weight_kg'] ?? 0;
    $cbm = $_POST['cbm'] ?? 0;

    if ($shipment_id > 0 && $pkg_num !== '') {
        $stmt = $pdo->prepare(
            'INSERT INTO shipment_packages (shipment_id, package_number, tracking_number, dimensions, weight_kg, cbm)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$shipment_id, $pkg_num, $track, $dims, $weight, $cbm]);
    }

    redirect('view.php?id=' . $shipment_id . '&tab=packages');
}

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = (int) $_GET['id'];
if ($id <= 0) {
    redirect('index.php');
}

$plain = static function ($value): string {
    $value = trim((string) ($value ?? ''));
    for ($i = 0; $i < 3; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) {
            break;
        }
        $value = $decoded;
    }

    return $value;
};

$stmt = $pdo->prepare(
    'SELECT s.*,
            su.name AS supplier_name,
            sh.name AS shipper_name, sh.phone AS shipper_phone, sh.email AS shipper_email, sh.website AS shipper_website,
            spo.po_number AS linked_po_number
     FROM shipments s
     LEFT JOIN stocks_suppliers su ON s.supplier_id = su.id
     LEFT JOIN shippers sh ON s.shipper_id = sh.id
     LEFT JOIN stocks_purchase_orders spo ON spo.id = s.stocks_po_id
     WHERE s.id = ?'
);
try {
    $stmt->execute([$id]);
    $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Fallback without stocks_suppliers if table/columns differ
    $stmt = $pdo->prepare(
        'SELECT s.*,
                sh.name AS shipper_name, sh.phone AS shipper_phone, sh.email AS shipper_email, sh.website AS shipper_website,
                spo.po_number AS linked_po_number
         FROM shipments s
         LEFT JOIN shippers sh ON s.shipper_id = sh.id
         LEFT JOIN stocks_purchase_orders spo ON spo.id = s.stocks_po_id
         WHERE s.id = ?'
    );
    $stmt->execute([$id]);
    $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($shipment) {
        $shipment['supplier_name'] = '';
    }
}

if ($shipment && empty($shipment['supplier_name']) && !empty($shipment['supplier_id'])) {
    foreach (['stocks_suppliers', 'suppliers'] as $supTable) {
        try {
            $supStmt = $pdo->prepare("SELECT name FROM `{$supTable}` WHERE id = ? LIMIT 1");
            $supStmt->execute([(int) $shipment['supplier_id']]);
            $supName = $supStmt->fetchColumn();
            if ($supName !== false && $supName !== null && trim((string) $supName) !== '') {
                $shipment['supplier_name'] = (string) $supName;
                break;
            }
        } catch (Throwable $e) {
            // try next table
        }
    }
}

if (!$shipment) {
    redirect('index.php');
}

$stmtItems = $pdo->prepare(
    'SELECT si.*, p.name AS product_name, p.product_code,
            stk.name AS stocks_item_name, stk.sku AS stocks_item_sku
     FROM shipment_items si
     LEFT JOIN products p ON si.product_id = p.id
     LEFT JOIN stocks_items stk ON si.stocks_item_id = stk.id
     WHERE si.shipment_id = ?'
);
$stmtItems->execute([$id]);
$itemsRaw = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmtPkgs = $pdo->prepare('SELECT * FROM shipment_packages WHERE shipment_id = ? ORDER BY id ASC');
$stmtPkgs->execute([$id]);
$packagesRaw = $stmtPkgs->fetchAll(PDO::FETCH_ASSOC) ?: [];

$eccDocsRaw = [];
try {
    $stmtDocs = $pdo->prepare('SELECT * FROM ecc_documents WHERE shipment_id = ? ORDER BY created_at DESC');
    $stmtDocs->execute([$id]);
    $eccDocsRaw = $stmtDocs->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $eccDocsRaw = [];
}

$currency = normalize_shipment_total_value_currency((string) ($shipment['total_value_currency'] ?? 'USD'));
$valuePrefix = function_exists('shipment_currency_display_prefix')
    ? shipment_currency_display_prefix($currency)
    : '$';

$shipmentPayload = [
    'id' => $id,
    'status' => (string) ($shipment['status'] ?? ''),
    'invoice_number' => $plain($shipment['invoice_number'] ?? ''),
    'supplier_id' => !empty($shipment['supplier_id']) ? (int) $shipment['supplier_id'] : null,
    'supplier_name' => $plain($shipment['supplier_name'] ?? ''),
    'shipper_id' => !empty($shipment['shipper_id']) ? (int) $shipment['shipper_id'] : null,
    'shipper_name' => $plain($shipment['shipper_name'] ?? ''),
    'shipper_phone' => $plain($shipment['shipper_phone'] ?? ''),
    'shipper_email' => $plain($shipment['shipper_email'] ?? ''),
    'shipper_website' => $plain($shipment['shipper_website'] ?? ''),
    'service_type' => $plain($shipment['service_type'] ?? ''),
    'stocks_po_id' => !empty($shipment['stocks_po_id']) ? (int) $shipment['stocks_po_id'] : null,
    'linked_po_number' => $plain($shipment['linked_po_number'] ?? ''),
    'contact_number' => $plain($shipment['contact_number'] ?? ''),
    'tracking_number' => $plain($shipment['tracking_number'] ?? ''),
    'description' => $plain($shipment['description'] ?? ''),
    'shipment_date' => (string) ($shipment['shipment_date'] ?? ''),
    'etd' => (string) ($shipment['etd'] ?? ''),
    'eta' => (string) ($shipment['eta'] ?? ''),
    'packages_count' => (int) ($shipment['packages_count'] ?? 0),
    'cbm' => isset($shipment['cbm']) ? (float) $shipment['cbm'] : null,
    'total_value' => isset($shipment['total_value']) ? (float) $shipment['total_value'] : 0,
    'total_value_currency' => $currency,
    'estimated_clearance_cost' => isset($shipment['estimated_clearance_cost'])
        ? (float) $shipment['estimated_clearance_cost']
        : 0,
    'shipping_cost' => isset($shipment['shipping_cost']) ? (float) $shipment['shipping_cost'] : 0,
    'insurance_cost' => isset($shipment['insurance_cost']) ? (float) $shipment['insurance_cost'] : 0,
    'shipping_method' => (string) ($shipment['shipping_method'] ?? 'sea'),
    'customs_duty' => isset($shipment['customs_duty']) ? (float) $shipment['customs_duty'] : 0,
    'customs_brokerage' => isset($shipment['customs_brokerage']) ? (float) $shipment['customs_brokerage'] : 0,
    'port_charges' => isset($shipment['port_charges']) ? (float) $shipment['port_charges'] : 0,
    'local_transport' => isset($shipment['local_transport']) ? (float) $shipment['local_transport'] : 0,
    'warehousing_fees' => isset($shipment['warehousing_fees']) ? (float) $shipment['warehousing_fees'] : 0,
    'other_costs' => isset($shipment['other_costs']) ? (float) $shipment['other_costs'] : 0,
];

$itemsPayload = array_map(static function ($row) use ($plain) {
    return [
        'id' => (int) ($row['id'] ?? 0),
        'product_name' => $plain($row['product_name'] ?? ''),
        'product_code' => $plain($row['product_code'] ?? ''),
        'stocks_item_name' => $plain($row['stocks_item_name'] ?? ''),
        'stocks_item_sku' => $plain($row['stocks_item_sku'] ?? ''),
        'quantity' => isset($row['quantity']) ? (float) $row['quantity'] : 0,
        'unit_price' => isset($row['unit_price']) ? (float) $row['unit_price'] : 0,
    ];
}, $itemsRaw);

$packagesPayload = array_map(static function ($row) use ($plain) {
    return [
        'id' => (int) ($row['id'] ?? 0),
        'package_number' => $plain($row['package_number'] ?? ''),
        'tracking_number' => $plain($row['tracking_number'] ?? ''),
        'dimensions' => $plain($row['dimensions'] ?? ''),
        'weight_kg' => isset($row['weight_kg']) ? (float) $row['weight_kg'] : null,
        'cbm' => isset($row['cbm']) ? (float) $row['cbm'] : null,
        'status' => $plain($row['status'] ?? ''),
    ];
}, $packagesRaw);

$eccPayload = array_map(static function ($row) use ($plain) {
    return [
        'id' => (int) ($row['id'] ?? 0),
        'doc_type' => $plain($row['doc_type'] ?? $row['type'] ?? ''),
        'authority' => $plain($row['authority'] ?? ''),
        'doc_date' => (string) ($row['doc_date'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'status' => $plain($row['status'] ?? ''),
    ];
}, $eccDocsRaw);

$initialTab = strtolower(trim((string) ($_GET['tab'] ?? 'details')));
if ($initialTab === '') {
    $initialTab = 'details';
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

$page_title = 'Shipment: ' . ($shipmentPayload['invoice_number'] !== '' ? $shipmentPayload['invoice_number'] : '#' . $id);
$employeeHeaderTitle = 'Shipment details';
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
        <div class="alert alert-warning m-3">JavaScript is required to view shipments.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'shipment-view',
            'data' => [
                'indexUrl' => 'index.php',
                'editUrl' => 'edit.php',
                'poViewUrl' => '../purchases/view_po.php',
                'formAction' => 'view.php',
                'landedCostAction' => 'save_landed_cost.php',
                'initialTab' => $initialTab,
                'valuePrefix' => $valuePrefix,
                'shipment' => $shipmentPayload,
                'items' => $itemsPayload,
                'packages' => $packagesPayload,
                'eccDocs' => $eccPayload,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"shipment-view","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
