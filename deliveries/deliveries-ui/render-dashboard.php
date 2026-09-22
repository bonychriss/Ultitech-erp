<?php

declare(strict_types=1);

/**
 * Shared React shell for the Delivery Logistics dashboard.
 */
$deliveriesRoot = dirname(__DIR__);
require_once $deliveriesRoot . '/config/database.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/load-data.php';

requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}
$_SESSION['active_module'] = 'deliveries';

$assets = deliveriesUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Delivery Logistics</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Delivery Logistics</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>deliveries/deliveries-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$initPayload = deliveries_load_dashboard_payload($pdo, $_GET);
$initData = $initPayload['data'] ?? [];

$dlvConfig = [
    'page' => 'dashboard',
    'apiUrl' => $assets['apiUrl'],
    'actionUrl' => $assets['actionUrl'],
    'aiSearchUrl' => $assets['aiSearchUrl'],
    'kpiAiAssistUrl' => $assets['kpiAiAssistUrl'],
    'data' => $initData,
];

$page_title = 'Delivery Logistics';
$employeeHeaderTitle = 'Dashboard';
$employeeHeaderExtraClass = 'employee-header--deliveries';
$hideHeaderCompanyBranding = true;
$employeeHeaderCenterHtml = '<div id="dlv-header-search-mount" class="dlv-header-search-mount"></div>';
$employeeHeaderRightHtml = '<div id="dlv-header-actions-mount" class="dlv-header-actions-mount"></div>';
$GLOBALS['_erp_header_style_linked'] = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Deliveries</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__DELIVERIES_CFG__ = <?= json_encode($dlvConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <?php require dirname($deliveriesRoot) . '/includes/nav-back-script.php'; ?>
</head>
<body class="dashboard page-dlv-dashboard">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = $initData['urls']['modules'] ?? '/select-module.php';
require_once dirname($deliveriesRoot) . '/includes/header_employee.php';
?>

<style>
body.page-dlv-dashboard.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-dlv-dashboard.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-dlv-dashboard,
body.page-dlv-dashboard.dashboard,
body.page-dlv-dashboard .layout-main-wrapper,
body.page-dlv-dashboard .layout-main-wrapper > .flex-grow-1 {
    background: #f1f5f9 !important;
}
html, body.page-dlv-dashboard.dashboard, .main-content, .layout-main-wrapper {
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
html::-webkit-scrollbar, body.page-dlv-dashboard.dashboard::-webkit-scrollbar,
.main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}
body.page-dlv-dashboard .employee-header.employee-header--deliveries {
    background: #f1f5f9 !important;
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
body.page-dlv-dashboard .employee-header--deliveries::after {
    display: none !important;
}
body.page-dlv-dashboard .employee-header--deliveries .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
        padding: 0.75rem 0 0.25rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-dlv-dashboard .employee-header--deliveries .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-dlv-dashboard .employee-header--deliveries .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-dlv-dashboard .employee-header--deliveries .header-right.header-actions-tray {
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
body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount {
    order: 1;
    display: inline-flex !important;
    align-items: center;
    gap: 0.4rem;
    flex-shrink: 0;
}
body.page-dlv-dashboard .employee-header--deliveries .header-actions-tray .notif {
    order: 2;
    position: relative;
    z-index: 2;
    flex-shrink: 0;
}
body.page-dlv-dashboard .employee-header--deliveries .header-actions-tray #themeToggleBtn {
    order: 3;
    display: inline-flex !important;
    flex-shrink: 0;
    visibility: visible !important;
    opacity: 1 !important;
    margin: 0;
    padding: 0;
    border: none;
    background: transparent;
}
body.page-dlv-dashboard .employee-header--deliveries .header-actions-tray #themeToggleBtn.theme-toggle-glass {
    width: auto;
    height: auto;
    border-radius: 999px;
    color: inherit;
}
body.page-dlv-dashboard .employee-header--deliveries .header-notif-bell-btn {
    align-self: flex-start;
    width: 38px;
    height: 38px;
    margin: 0;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #fff;
    flex-shrink: 0;
}
@media (min-width: 768px) {
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount {
        display: inline-flex !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-filter-btn {
        width: 2.25rem;
        height: 2.25rem;
        border: 1px solid #e2e8f0;
        border-radius: 50%;
        background: #fff;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        cursor: pointer;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn--create {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        height: 38px;
        padding: 0 16px;
        border-radius: 10px;
        background: #6d5df6;
        color: #fff !important;
        font-weight: 600;
        font-size: 14px;
        line-height: 1;
        text-decoration: none;
        box-shadow: 0 6px 16px rgba(109, 93, 246, .22);
        white-space: nowrap;
        border: none;
        flex-shrink: 0;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn-label-mobile {
        display: none;
    }
    body.page-dlv-dashboard .employee-header--deliveries.employee-header--has-center-slot .header-content {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) minmax(280px, 440px) minmax(0, 1fr);
        align-items: center !important;
        gap: 0.75rem 1.25rem !important;
        padding-bottom: 0.15rem !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-left {
        display: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .employee-header-page-heading--with-center {
        grid-column: 1;
        justify-self: start;
        flex: none;
        min-width: 0;
    }
    body.page-dlv-dashboard .employee-header--deliveries .employee-header-center-slot {
        grid-column: 2;
        flex: none;
        min-width: 0;
        max-width: none;
        width: 100%;
        margin: 0;
        padding: 0 !important;
        justify-content: center !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-right.header-actions-tray {
        grid-column: 3;
        justify-self: end;
        margin-left: 0 !important;
        align-self: center !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount {
        width: 100%;
        overflow: visible;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-search-field {
        position: relative;
        display: flex;
        align-items: center;
        width: 100%;
        border-radius: 9999px;
        background: #fff;
        border: 1px solid #e2e8f0;
        overflow: visible;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-search-field:focus-within {
        border-color: #a5b4fc;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-search-icon {
        position: absolute;
        left: 14px;
        color: #94a3b8;
        pointer-events: none;
        z-index: 1;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-search-input {
        width: 100%;
        padding: 0.55rem 2.75rem 0.55rem 2.35rem;
        border: none !important;
        border-radius: 9999px !important;
        font-size: 0.875rem;
        background: transparent !important;
        color: #0f172a;
        outline: none !important;
        box-shadow: none !important;
        -webkit-appearance: none;
        appearance: none;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-ai-btn {
        right: 5px;
        width: 28px;
        height: 28px;
    }
}
@media (max-width: 767.98px) {
    body.page-dlv-dashboard .employee-header--deliveries .employee-header-center-slot {
        display: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-actions-tray .notif,
    body.page-dlv-dashboard .employee-header--deliveries .header-actions-tray #themeToggleBtn {
        display: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount {
        display: inline-flex !important;
        align-items: center;
        gap: 0.35rem;
        max-width: min(70vw, 16rem);
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-expand {
        display: flex !important;
        align-items: center;
        flex: 0 0 auto;
        min-width: 0;
        position: relative;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-expand.is-open {
        flex: 1 1 auto;
        min-width: 8rem;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-toggle,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-filter-btn {
        width: 2.5rem;
        height: 2.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 50% !important;
        background: #fff;
        color: #475569;
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        padding: 0;
        cursor: pointer;
        flex-shrink: 0;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-toggle svg,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-filter-btn svg,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn--create svg {
        width: 1.35rem !important;
        height: 1.35rem !important;
        flex-shrink: 0;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn--create {
        display: inline-flex !important;
        align-items: center;
        gap: 0.3rem;
        min-height: 2.5rem;
        padding: 0.4rem 0.85rem;
        border-radius: 9999px !important;
        background: #6d5df6;
        color: #fff !important;
        font-weight: 600;
        font-size: 0.8125rem;
        text-decoration: none;
        border: none;
        white-space: nowrap;
        flex-shrink: 0;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn-label-desktop {
        display: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn-label-mobile {
        display: inline !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel {
        position: fixed !important;
        top: calc(var(--header-height, 3rem) + 0.45rem) !important;
        left: 0.75rem !important;
        right: 0.75rem !important;
        width: auto !important;
        max-width: none !important;
        min-width: 0 !important;
        flex: none !important;
        margin: 0 !important;
        z-index: 1300 !important;
        display: none;
        padding: 0.55rem !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 14px !important;
        background: #fff !important;
        box-shadow: 0 14px 32px rgba(15, 23, 42, 0.16) !important;
        opacity: 1 !important;
        overflow: visible !important;
        pointer-events: auto !important;
        box-sizing: border-box !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel.is-open {
        display: block !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-search-field {
        position: relative !important;
        display: flex !important;
        align-items: center !important;
        width: 100% !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 9999px !important;
        background: #fff !important;
        box-shadow: none !important;
        overflow: hidden !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-search-field:focus-within {
        border-color: #a5b4fc !important;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12) !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-search-icon {
        position: absolute !important;
        left: 0.85rem !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        color: #94a3b8 !important;
        pointer-events: none !important;
        z-index: 2 !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-search-input,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel input.dlv-search-input[type="search"] {
        width: 100% !important;
        height: 2.5rem !important;
        margin: 0 !important;
        padding: 0.55rem 2.75rem 0.55rem 2.35rem !important;
        border: none !important;
        border-radius: 9999px !important;
        outline: none !important;
        box-shadow: none !important;
        background: transparent !important;
        font-size: 0.875rem !important;
        color: #0f172a !important;
        -webkit-appearance: none !important;
        appearance: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-ai-btn,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel button.dlv-ai-btn {
        position: absolute !important;
        right: 0.35rem !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        width: 1.85rem !important;
        height: 1.85rem !important;
        min-width: 1.85rem !important;
        min-height: 1.85rem !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        border-radius: 50% !important;
        background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
        color: #fff !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        z-index: 3 !important;
    }
}
main.main-content.dlv-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f1f5f9 !important;
    min-height: calc(100vh - 80px);
}
main.main-content.dlv-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: calc(100vh - 80px);
    min-width: 0;
}
@media (max-width: 1280px) {
    main.main-content.dlv-react-root {
        padding: 0 1rem 1.75rem !important;
    }
}
@media (max-width: 1024px) {
    main.main-content.dlv-react-root {
        padding: 0 0.875rem 1.5rem !important;
    }
}
@media (max-width: 767.98px) {
    body.page-dlv-dashboard {
        --header-height: 3rem;
    }
    body.page-dlv-dashboard .employee-header.employee-header--deliveries {
        padding: 0 0.75rem !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-content {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 0.5rem !important;
        min-height: 3rem !important;
        padding: 0.5rem 0 !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-left {
        position: static !important;
        flex: 0 0 auto;
        order: 1;
    }
    body.page-dlv-dashboard .employee-header--deliveries .employee-header-page-heading {
        order: 2;
        flex: 1 1 auto;
        min-width: 0;
        margin-left: 0 !important;
        padding-left: 0 !important;
        padding-right: 0.25rem !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .employee-header-page-title {
        font-size: 1rem !important;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-right.header-actions-tray {
        order: 3;
        flex: 0 0 auto;
        gap: 0.35rem !important;
        margin-left: auto !important;
        align-self: center !important;
    }
    main.main-content.dlv-react-root {
        padding: 0.65rem 0.75rem 1.5rem !important;
    }
}
html[data-theme="dark"] body.page-dlv-dashboard,
html[data-theme="dark"] body.page-dlv-dashboard.dashboard,
html[data-theme="dark"] body.page-dlv-dashboard .layout-main-wrapper,
html[data-theme="dark"] body.page-dlv-dashboard .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-dlv-dashboard main.main-content.dlv-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-dlv-dashboard .employee-header.employee-header--deliveries {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-dlv-dashboard .employee-header--deliveries .employee-header-page-title {
    color: #f8fafc !important;
}
</style>

<style id="dlv-dashboard-mobile-toolbar-fix">
/* Toolbar is always visible. Desktop keeps search in the header; mobile
   collapses search to an icon inside this toolbar. */
.dlv-dashboard-toolbar {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 0.4rem !important;
    width: 100% !important;
    margin: 0 !important;
    position: relative !important;
    z-index: 6 !important;
    visibility: visible !important;
    opacity: 1 !important;
}
.dlv-dashboard-toolbar .dlv-search-expand {
    display: none;
}
.dlv-dashboard-toolbar .dlv-search-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    flex-shrink: 0;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #475569;
    padding: 0;
    cursor: pointer;
    box-shadow: none;
}
.dlv-dashboard-toolbar .dlv-search-panel.is-open {
    flex: 1 1 auto;
    width: auto;
    max-width: 100%;
    opacity: 1;
    overflow: visible;
    pointer-events: auto;
    margin-left: 0.5rem;
}

@media (max-width: 1023.98px) {
    .dlv-dashboard-toolbar {
        justify-content: flex-start !important;
        min-height: 2.75rem !important;
        margin: 0 !important;
    }
    .dlv-dashboard-toolbar .dlv-search-expand {
        display: flex !important;
        align-items: center !important;
        flex: 0 0 auto !important;
        min-width: 0 !important;
    }
    .dlv-dashboard-toolbar .dlv-search-expand.is-open {
        flex: 1 1 auto !important;
    }
}
</style>

<main class="main-content dlv-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to use Delivery Logistics.</div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const logoutBtn = document.querySelector('.logout-btn');
        if (logoutBtn) {
            logoutBtn.href = <?= json_encode($initData['urls']['modules'] ?? '/select-module.php') ?>;
            logoutBtn.textContent = 'Exit';
            logoutBtn.classList.remove('text-danger');
            logoutBtn.style.color = '#64748b';
        }
    });
</script>
</body>
</html>
