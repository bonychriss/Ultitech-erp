<?php
// stock/modules/suppliers/index.php — React suppliers desk
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$companyCtx = function_exists('stock_image_company_context')
    ? stock_image_company_context()
    : ['slug' => (string) ($_SESSION['company_slug'] ?? ''), 'company_id' => (int) ($_SESSION['company_id'] ?? 0)];
$isRoadmasterCompany = strtolower((string) ($companyCtx['slug'] ?? '')) === 'roadmaster'
    || (int) ($companyCtx['company_id'] ?? 0) === 2;
$isUltimateStockSimple = (
    (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
);

$typeFilter = strtolower(trim((string) ($_GET['type'] ?? 'all')));
if (!in_array($typeFilter, ['all', 'vehicle', 'spare_part'], true)) {
    $typeFilter = 'all';
}
if (!$isRoadmasterCompany) {
    $typeFilter = 'all';
}
$search = trim((string) ($_GET['search'] ?? ''));

$sql = 'SELECT * FROM suppliers ORDER BY name ASC';
$stmt = $pdo->query($sql);
$suppliers_raw = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$supplierPayload = [];
foreach ($suppliers_raw as $s) {
    $type = (string) ($s['supplier_type'] ?? 'general');
    $name_upper = strtoupper((string) ($s['name'] ?? ''));

    if ($isRoadmasterCompany) {
        if ($type === 'general' || $type === '') {
            if (
                strpos($name_upper, 'MOTOR') !== false
                || strpos($name_upper, 'TRUCK') !== false
                || strpos($name_upper, 'VEHICLE') !== false
                || strpos($name_upper, 'JIEFANG') !== false
            ) {
                $type = 'vehicle';
            } elseif (strpos($name_upper, 'SPARE') !== false || strpos($name_upper, 'PART') !== false) {
                $type = 'spare_part';
            }
        }
    } else {
        $type = 'general';
    }

    $sid = (int) ($s['id'] ?? 0);
    $initials = function_exists('stock_profile_initials')
        ? stock_profile_initials($s['name'] ?? '')
        : strtoupper(substr((string) ($s['name'] ?? 'SU'), 0, 2));
    $avatarKey = $sid . '|' . (string) ($s['name'] ?? '');
    $avatarStyle = function_exists('stock_profile_avatar_style')
        ? stock_profile_avatar_style($avatarKey)
        : 'background:#7c3aed;color:#fff;';

    $supplierCode = '';
    foreach (['supplier_code', 'code', 'reference_no'] as $codeCol) {
        if (!empty($s[$codeCol])) {
            $supplierCode = trim((string) $s[$codeCol]);
            break;
        }
    }
    if ($supplierCode === '' && $sid > 0) {
        $supplierCode = 'SUP-' . str_pad((string) $sid, 4, '0', STR_PAD_LEFT);
    }

    $locationLine = trim((string) ($s['location'] ?? $s['address'] ?? ''));
    if ($locationLine === '') {
        $locationLine = 'Registered partner';
    }

    $supplierPayload[] = [
        'id' => $sid,
        'name' => (string) ($s['name'] ?? ''),
        'contact_person' => (string) ($s['contact_person'] ?? ''),
        'phone' => (string) ($s['phone'] ?? ''),
        'email' => (string) ($s['email'] ?? ''),
        'address' => (string) ($s['address'] ?? ''),
        'location' => (string) ($s['location'] ?? ''),
        'location_line' => $locationLine,
        'notes' => (string) ($s['notes'] ?? ''),
        'status' => (string) ($s['status'] ?? ''),
        'supplier_type' => (string) ($s['supplier_type'] ?? 'general'),
        'detected_type' => $type,
        'supplier_code' => $supplierCode,
        'initials' => $initials,
        'avatar_style' => $avatarStyle,
    ];
}

$toast = '';
if (!empty($_SESSION['success'])) {
    $toast = trim((string) $_SESSION['success']);
    unset($_SESSION['success'], $_SESSION['success_type']);
} elseif (isset($_GET['msg']) && (string) $_GET['msg'] === 'added') {
    $toast = 'Supplier added successfully.';
} elseif (isset($_GET['delete']) && (string) $_GET['delete'] === 'success') {
    $toast = 'Supplier removed successfully.';
} elseif (isset($_GET['update']) && (string) $_GET['update'] === 'success') {
    $toast = 'Supplier updated successfully.';
}
$createdSupplierId = isset($_GET['created_id']) ? (int) $_GET['created_id'] : 0;

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

$page_title = 'Suppliers';
$employeeHeaderTitle = 'Suppliers';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk';

$assetVersion = max(
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.css') ?: 0),
    time()
);

$supplier_lottie_show = $toast !== '';
$supplier_lottie_message = $toast !== '' ? $toast : 'Supplier added successfully!';
$supplier_lottie_view_url = $createdSupplierId > 0 ? ('view.php?id=' . $createdSupplierId) : '';

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
        <div class="alert alert-warning m-3">JavaScript is required to manage suppliers.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'suppliers-list',
            'data' => [
                'isRoadmaster' => $isRoadmasterCompany,
                'isUltimate' => $isUltimateStockSimple,
                'typeFilter' => $typeFilter,
                'search' => $search,
                'suppliers' => $supplierPayload,
                'baseUrl' => $assetBase,
                'addUrl' => 'add.php',
                'viewUrl' => 'view.php',
                'editUrl' => 'edit.php',
                'deleteUrl' => 'delete.php',
                'toast' => $toast,
                'createdId' => $createdSupplierId,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"suppliers-list","data":{"suppliers":[]}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php
include __DIR__ . '/includes/supplier-success-lottie.php';
include '../../includes/footer.php';
?>
