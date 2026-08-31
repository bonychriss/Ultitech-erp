<?php
// stock/modules/shipments/edit.php — React shipment edit desk
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

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = (int) $_GET['id'];
if ($id <= 0) {
    redirect('index.php');
}

$stmt = $pdo->prepare(
    'SELECT s.*, spo.po_number AS linked_po_number
     FROM shipments s
     LEFT JOIN stocks_purchase_orders spo ON spo.id = s.stocks_po_id
     WHERE s.id = ?'
);
$stmt->execute([$id]);
$shipment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shipment) {
    flash('success', 'Shipment not found', 'danger');
    redirect('index.php');
}

$suppliers = [];
$shippers = [];
try {
    $suppliers = $pdo->query('SELECT id, name FROM stocks_suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Shipment edit suppliers: ' . $e->getMessage());
}
try {
    $shippers = $pdo->query('SELECT id, name FROM shippers WHERE is_active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Shipment edit shippers: ' . $e->getMessage());
}

$plainInput = static function ($value): string {
    $value = trim((string) ($value ?? ''));
    $value = stripslashes($value);
    for ($i = 0; $i < 3; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) {
            break;
        }
        $value = $decoded;
    }
    $value = str_replace("\xEF\xBF\xBD", '', $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

    return $value;
};

$dateOnly = static function ($value): ?string {
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return null;
    }

    return strlen($value) >= 10 ? substr($value, 0, 10) : $value;
};

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $invoice_number = $plainInput($_POST['invoice_number'] ?? '');
    $tracking_number = $plainInput($_POST['tracking_number'] ?? '');
    $contact_number = $plainInput($_POST['contact_number'] ?? '');
    $shipper_id = !empty($_POST['shipper_id']) ? (int) $_POST['shipper_id'] : null;
    $est_cost = $_POST['estimated_clearance_cost'] ?? 0;
    $shipment_date = $dateOnly($_POST['shipment_date'] ?? '');
    $etd = $dateOnly($_POST['etd'] ?? '');
    $eta = $dateOnly($_POST['eta'] ?? '');
    $packages = $_POST['packages_count'] ?? 1;
    $cbm = $_POST['cbm'] ?? 0;
    $value = $_POST['total_value'] ?? 0;
    $total_value_currency = normalize_shipment_total_value_currency((string) ($_POST['total_value_currency'] ?? 'USD'));
    $description = $plainInput($_POST['description'] ?? '');
    $status = $plainInput($_POST['status'] ?? 'pending');
    if ($status === '') {
        $status = 'pending';
    }

    if ($supplier_id <= 0) {
        $error = 'Supplier is required.';
    } elseif ($invoice_number === '') {
        $error = 'Invoice number is required.';
    } else {
        $stmt = $pdo->prepare(
            'UPDATE shipments SET
                supplier_id=?, invoice_number=?, tracking_number=?, contact_number=?,
                shipper_id=?, estimated_clearance_cost=?, shipment_date=?, etd=?, eta=?,
                packages_count=?, cbm=?, total_value=?, total_value_currency=?, description=?, status=?,
                updated_at=NOW()
             WHERE id=?'
        );

        if ($stmt->execute([
            $supplier_id,
            $invoice_number,
            $tracking_number,
            $contact_number,
            $shipper_id,
            $est_cost,
            $shipment_date,
            $etd,
            $eta,
            $packages,
            $cbm,
            $value,
            $total_value_currency,
            $description,
            $status,
            $id,
        ])) {
            flash('success', 'Shipment updated successfully');
            redirect('view.php?id=' . $id);
        }
        $error = 'Failed to update shipment';
    }

    // Re-display submitted values after error
    $shipment['supplier_id'] = $supplier_id;
    $shipment['invoice_number'] = $invoice_number;
    $shipment['tracking_number'] = $tracking_number;
    $shipment['contact_number'] = $contact_number;
    $shipment['shipper_id'] = $shipper_id;
    $shipment['estimated_clearance_cost'] = $est_cost;
    $shipment['shipment_date'] = $shipment_date;
    $shipment['etd'] = $etd;
    $shipment['eta'] = $eta;
    $shipment['packages_count'] = $packages;
    $shipment['cbm'] = $cbm;
    $shipment['total_value'] = $value;
    $shipment['total_value_currency'] = $total_value_currency;
    $shipment['description'] = $description;
    $shipment['status'] = $status;
}

$status_opts = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'shipped' => 'Shipped',
    'in_transit' => 'In transit',
    'arrived_at_port' => 'Arrived at port',
    'in_customs' => 'In customs',
    'ready_for_pickup' => 'Ready for pickup',
    'out_for_delivery' => 'Out for delivery',
    'delivered' => 'Delivered',
    'delayed' => 'Delayed',
    'cancelled' => 'Cancelled',
];
$currentStatus = (string) ($shipment['status'] ?? 'pending');
if ($currentStatus !== '' && !isset($status_opts[$currentStatus])) {
    $status_opts = [$currentStatus => ucwords(str_replace('_', ' ', $currentStatus))] + $status_opts;
}

$statusOptionsPayload = [];
foreach ($status_opts as $val => $label) {
    $statusOptionsPayload[] = ['value' => (string) $val, 'label' => (string) $label];
}

$currenciesPayload = [];
$currencyHelpers = dirname(__DIR__, 3) . '/modules/expenses/includes/currency_helpers.php';
if (is_file($currencyHelpers)) {
    require_once $currencyHelpers;
}
foreach (shipment_total_value_currency_options() as $code => $label) {
    $codeStr = (string) $code;
    $entry = [
        'code' => $codeStr,
        'label' => (string) $label,
    ];
    if (function_exists('expenses_currency_flag_country')) {
        $entry['flag'] = expenses_currency_flag_country($codeStr);
    }
    if (function_exists('expenses_currency_flag_url')) {
        $entry['flag_url'] = expenses_currency_flag_url($codeStr);
    }
    $currenciesPayload[] = $entry;
}

$suppliersPayload = array_map(static function ($row) use ($plainInput) {
    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => $plainInput($row['name'] ?? ''),
    ];
}, $suppliers);

$shippersPayload = array_map(static function ($row) use ($plainInput) {
    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => $plainInput($row['name'] ?? ''),
    ];
}, $shippers);

$shipmentPayload = [
    'id' => $id,
    'supplier_id' => isset($shipment['supplier_id']) ? (int) $shipment['supplier_id'] : null,
    'invoice_number' => $plainInput($shipment['invoice_number'] ?? ''),
    'tracking_number' => $plainInput($shipment['tracking_number'] ?? ''),
    'contact_number' => $plainInput($shipment['contact_number'] ?? ''),
    'shipper_id' => !empty($shipment['shipper_id']) ? (int) $shipment['shipper_id'] : null,
    'estimated_clearance_cost' => isset($shipment['estimated_clearance_cost'])
        ? (float) $shipment['estimated_clearance_cost']
        : 0,
    'shipment_date' => $dateOnly($shipment['shipment_date'] ?? '') ?? '',
    'etd' => $dateOnly($shipment['etd'] ?? '') ?? '',
    'eta' => $dateOnly($shipment['eta'] ?? '') ?? '',
    'packages_count' => isset($shipment['packages_count']) ? (int) $shipment['packages_count'] : 1,
    'cbm' => isset($shipment['cbm']) ? (float) $shipment['cbm'] : 0,
    'total_value' => isset($shipment['total_value']) ? (float) $shipment['total_value'] : 0,
    'total_value_currency' => normalize_shipment_total_value_currency((string) ($shipment['total_value_currency'] ?? 'USD')),
    'description' => $plainInput($shipment['description'] ?? ''),
    'status' => $currentStatus !== '' ? $currentStatus : 'pending',
    'stocks_po_id' => !empty($shipment['stocks_po_id']) ? (int) $shipment['stocks_po_id'] : null,
    'linked_po_number' => $plainInput($shipment['linked_po_number'] ?? ''),
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

$page_title = 'Edit Shipment';
$employeeHeaderTitle = 'Edit shipment';
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
        <div class="alert alert-warning m-3">JavaScript is required to edit shipments.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'shipment-edit',
            'data' => [
                'indexUrl' => 'index.php',
                'viewUrl' => 'view.php',
                'poViewUrl' => '../purchases/view_po.php',
                'formAction' => 'edit.php?id=' . $id,
                'error' => $error,
                'shipment' => $shipmentPayload,
                'suppliers' => $suppliersPayload,
                'shippers' => $shippersPayload,
                'currencies' => $currenciesPayload,
                'statusOptions' => $statusOptionsPayload,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"shipment-edit","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
