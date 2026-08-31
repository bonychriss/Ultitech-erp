<?php
// stock/modules/shipments/index.php — React shipments desk
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
require_once '../../includes/shipment-functions.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$shipmentCreateSuccess = null;
if (!empty($_SESSION['stock_shipment_create_success']) && is_array($_SESSION['stock_shipment_create_success'])) {
    $shipmentCreateSuccess = $_SESSION['stock_shipment_create_success'];
    unset($_SESSION['stock_shipment_create_success']);
}

$shipmentNotice = '';
if (!empty($_SESSION['shipment_notice'])) {
    $shipmentNotice = trim((string) $_SESSION['shipment_notice']);
    unset($_SESSION['shipment_notice']);
}

ensure_shipment_po_linking_schema($pdo);
updateShipmentStatusesAutomatically($pdo);

$search = trim((string) ($_GET['search'] ?? ''));

$stmt = $pdo->query(
    "SELECT s.*, su.name AS supplier_name, sh.name AS shipper_real_name,
            spo.po_number AS linked_po_number
     FROM shipments s
     LEFT JOIN stocks_suppliers su ON s.supplier_id = su.id
     LEFT JOIN shippers sh ON s.shipper_id = sh.id
     LEFT JOIN stocks_purchase_orders spo ON spo.id = s.stocks_po_id
     ORDER BY s.created_at DESC"
);
$shipmentsRaw = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$shipmentPayload = [];
foreach ($shipmentsRaw as $ship) {
    $currency = (string) ($ship['total_value_currency'] ?? 'USD');
    $prefix = function_exists('shipment_currency_display_prefix')
        ? shipment_currency_display_prefix($currency)
        : '$';

    $shipmentPayload[] = [
        'id' => (int) ($ship['id'] ?? 0),
        'supplier_name' => (string) ($ship['supplier_name'] ?? ''),
        'stocks_po_id' => !empty($ship['stocks_po_id']) ? (int) $ship['stocks_po_id'] : null,
        'linked_po_number' => (string) ($ship['linked_po_number'] ?? ''),
        'contact_number' => (string) ($ship['contact_number'] ?? ''),
        'invoice_number' => (string) ($ship['invoice_number'] ?? ''),
        'tracking_number' => (string) ($ship['tracking_number'] ?? ''),
        'packages_count' => (int) ($ship['packages_count'] ?? 0),
        'cbm' => isset($ship['cbm']) ? (float) $ship['cbm'] : null,
        'total_value' => isset($ship['total_value']) ? (float) $ship['total_value'] : null,
        'total_value_currency' => $currency,
        'value_prefix' => $prefix,
        'description' => (string) ($ship['description'] ?? ''),
        'shipment_date' => (string) ($ship['shipment_date'] ?? ''),
        'shipper_real_name' => (string) ($ship['shipper_real_name'] ?? ''),
        'shipper_name' => (string) ($ship['shipper_name'] ?? ''),
        'shipper' => (string) ($ship['shipper'] ?? ''),
        'estimated_clearance_cost' => isset($ship['estimated_clearance_cost'])
            ? (float) $ship['estimated_clearance_cost']
            : null,
        'etd' => (string) ($ship['etd'] ?? ''),
        'eta' => (string) ($ship['eta'] ?? ''),
        'status' => (string) ($ship['status'] ?? ''),
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

$page_title = 'Shipments';
$employeeHeaderTitle = 'Shipments';
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
        <div class="alert alert-warning m-3">JavaScript is required to manage shipments.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'shipments-list',
            'data' => [
                'search' => $search,
                'notice' => $shipmentNotice,
                'createSuccess' => $shipmentCreateSuccess,
                'shipments' => $shipmentPayload,
                'baseUrl' => $assetBase,
                'urls' => [
                    'create' => 'create.php',
                    'import' => 'import.php',
                    'shippers' => '../shippers/index.php',
                    'purchases' => '../purchases/index.php',
                    'view' => 'view.php',
                    'edit' => 'edit.php',
                    'poView' => '../purchases/view_po.php',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"shipments-list","data":{"shipments":[]}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
