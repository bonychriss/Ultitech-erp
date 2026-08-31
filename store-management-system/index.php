<?php
/**
 * Store Management — warehouse inventory UI inside the ERP layout (React).
 */
require_once __DIR__ . '/../stock/config/database.php';
require_once __DIR__ . '/lib.php';
requireLogin();

$page = (isset($_GET['page']) && $_GET['page'] === 'labels') ? 'labels' : 'desk';

$requestedModule = strtolower(trim((string) ($_GET['module'] ?? '')));
$active_module = ($requestedModule === 'warehouses') ? 'warehouses' : 'store-management';
$page_title = ($active_module === 'warehouses') ? 'Warehouses' : 'Store Management';
$employeeHeaderTitle = '';
$employeeHeaderSubtitle = '';

if (function_exists('app_url')) {
    $rootPath = app_url('/');
    $stockBasePath = app_url('stock/');
} else {
    $rootPath = '../';
    $stockBasePath = '../stock/';
}
$logoBase = $rootPath;
$modulesLink = rtrim($rootPath, '/') . '/select-module.php';

$warehouseRows = [];
try {
    $warehouseRows = $pdo->query('SELECT id, code, name, address FROM warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $warehouseRows = [];
}

$currentWarehouseId = (int) ($_GET['warehouse_id'] ?? 0);
if ($currentWarehouseId <= 0 && !empty($warehouseRows)) {
    $currentWarehouseId = (int) $warehouseRows[0]['id'];
}

$currentWarehouse = null;
foreach ($warehouseRows as $wh) {
    if ((int) $wh['id'] === $currentWarehouseId) {
        $currentWarehouse = $wh;
        break;
    }
}

$hideHeaderCompanyBranding = true;
$hideHeaderThemeAndNotifications = true;
$employeeHeaderExtraClass = 'employee-header--store-mgmt';
$employeeHeaderRightHtml = '';
$bodyExtraClass = 'page-store-management';

$assets = storeManagementUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Store Management</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Store Management</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>store-management-system/</code>.</p>';
    echo '</body></html>';
    exit;
}

$storeMgmtConfig = [
    'apiUrl' => $assets['apiUrl'],
    'page' => $page,
];

if ($page === 'labels') {
    $storeMgmtConfig['labelDownloadUrl'] = storeManagementUiPublicUrl('label-download.php');
    $storeMgmtConfig['labelStarUrl'] = storeManagementUiPublicUrl('label-star.php');
}

include __DIR__ . '/../stock/includes/header.php';
?>

<link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
<script>
    window.__STORE_MGMT_CFG__ = <?= json_encode($storeMgmtConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>

<style>
    body.page-store-management,
    body.page-store-management.dashboard,
    body.page-store-management .layout-main-wrapper,
    body.page-store-management .layout-main-wrapper > .flex-grow-1 {
        background: #f8fafc !important;
    }

    body.page-store-management .employee-header.employee-header--store-mgmt {
        height: auto !important;
        min-height: 0;
        background: #f8fafc !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0 1.25rem !important;
        margin-bottom: 0;
        position: static !important;
        top: auto !important;
        z-index: auto !important;
    }

    body.page-store-management .employee-header--store-mgmt .header-content {
        display: flex !important;
        align-items: center !important;
        flex-wrap: nowrap !important;
        gap: 0.5rem 1rem;
        padding: 0.5rem 0 !important;
        width: 100%;
        min-height: 0;
        background: transparent !important;
    }

    body.page-store-management .employee-header--store-mgmt .header-left {
        flex-shrink: 0;
    }

    body.page-store-management .employee-header--store-mgmt .employee-header-page-heading {
        display: none !important;
    }

    body.page-store-management .employee-header--store-mgmt .header-right.header-actions-tray {
        display: none !important;
    }

    main.main-content.store-management-shell {
        flex: 1 1 auto;
        min-height: 0;
        overflow: auto;
        padding: 0 1.25rem 2rem !important;
        background: #f8fafc;
        width: 100% !important;
        max-width: none !important;
        box-sizing: border-box;
    }

    main.main-content.store-management-shell #root {
        width: 100%;
        max-width: none;
        margin: 0;
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: calc(100vh - 4rem);
    }

    @media (max-width: 767.98px) {
        body.page-store-management .employee-header--store-mgmt .header-content {
            flex-wrap: wrap !important;
            padding: 0.5rem 0 !important;
        }

        main.main-content.store-management-shell {
            padding: 0 0.75rem 1.5rem !important;
        }
    }

    html[data-theme="dark"] body.page-store-management,
    html[data-theme="dark"] body.page-store-management.dashboard,
    html[data-theme="dark"] body.page-store-management .layout-main-wrapper,
    html[data-theme="dark"] body.page-store-management .layout-main-wrapper > .flex-grow-1,
    html[data-theme="dark"] body.page-store-management .employee-header.employee-header--store-mgmt,
    html[data-theme="dark"] main.main-content.store-management-shell {
        background: #0f172a !important;
    }

    body.page-store-management #native-sidebar .sidebar-theme-toggle {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.55rem 1rem;
        color: inherit;
        font-weight: 500;
        font-size: 0.95rem;
        cursor: pointer;
    }

    body.page-store-management #native-sidebar .sidebar-theme-toggle:hover,
    body.page-store-management #native-sidebar .sidebar-notif-trigger:hover {
        opacity: 0.85;
    }

    body.page-store-management #native-sidebar .sidebar-theme-toggle i {
        width: 1.25rem;
        text-align: center;
        flex-shrink: 0;
    }
</style>

<script>document.body.classList.add('page-store-management');</script>

<main class="main-content store-management-shell">
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>

<?php include __DIR__ . '/../stock/includes/footer.php'; ?>
