<?php

declare(strict_types=1);

/**
 * React shell for Customer Reviews list.
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
    echo '<!DOCTYPE html><html><head><title>Customer Reviews</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Customer Reviews</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>deliveries/deliveries-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$initPayload = deliveries_load_customer_reviews_payload($pdo, $_GET);
$initData = $initPayload['data'] ?? [];

$dlvConfig = [
    'page' => 'customer-reviews',
    'customerReviewsInitUrl' => $assets['customerReviewsInitUrl'],
    'kpiAiAssistUrl' => $assets['kpiAiAssistUrl'],
    'gradeFeedbackUrl' => $assets['gradeFeedbackUrl'],
    'data' => $initData,
];

$page_title = 'Customer Reviews';
$employeeHeaderTitle = 'Customer Reviews';
$employeeHeaderExtraClass = 'employee-header--deliveries';
$hideHeaderCompanyBranding = true;
$employeeHeaderCenterHtml = '<div id="dlv-header-search-mount" class="dlv-header-search-mount"></div>';
$employeeHeaderRightHtml = '';
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
</head>
<body class="dashboard page-dlv-reviews">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = $initData['urls']['dashboard'] ?? '/select-module.php';
require_once dirname($deliveriesRoot) . '/includes/header_employee.php';
?>

<style>
body.page-dlv-reviews.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-dlv-reviews.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-dlv-reviews,
body.page-dlv-reviews.dashboard,
body.page-dlv-reviews .layout-main-wrapper,
body.page-dlv-reviews .layout-main-wrapper > .flex-grow-1 {
    background: #f1f5f9 !important;
}
html, body.page-dlv-reviews.dashboard, .main-content, .layout-main-wrapper {
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
html::-webkit-scrollbar, body.page-dlv-reviews.dashboard::-webkit-scrollbar,
.main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}
body.page-dlv-reviews .employee-header.employee-header--deliveries {
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
body.page-dlv-reviews .employee-header--deliveries::after { display: none !important; }
body.page-dlv-reviews .employee-header--deliveries .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-dlv-reviews .employee-header--deliveries .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-dlv-reviews .employee-header--deliveries .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-dlv-reviews .employee-header--deliveries .header-right.header-actions-tray {
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
body.page-dlv-reviews .employee-header--deliveries .header-actions-tray .notif { order: 2; position: relative; z-index: 2; flex-shrink: 0; }
body.page-dlv-reviews .employee-header--deliveries .header-actions-tray #themeToggleBtn {
    order: 3;
    display: inline-flex !important;
    flex-shrink: 0;
    visibility: visible !important;
    opacity: 1 !important;
    width: 38px;
    height: 38px;
    margin: 0;
    padding: 0;
    border: none;
    background: transparent;
    border-radius: 50%;
    color: #64748b;
}
body.page-dlv-reviews .employee-header--deliveries .header-notif-bell-btn {
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
    body.page-dlv-reviews .employee-header--deliveries.employee-header--has-center-slot .header-content {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) minmax(280px, 440px) minmax(0, 1fr);
        align-items: center !important;
        gap: 0.75rem 1.25rem !important;
        padding-bottom: 0.35rem !important;
    }
    body.page-dlv-reviews .employee-header--deliveries .header-left { display: none !important; }
    body.page-dlv-reviews .employee-header--deliveries .employee-header-page-heading--with-center {
        grid-column: 1;
        justify-self: start;
        flex: none;
        min-width: 0;
    }
    body.page-dlv-reviews .employee-header--deliveries .employee-header-center-slot {
        grid-column: 2;
        flex: none;
        min-width: 0;
        max-width: none;
        width: 100%;
        margin: 0;
        padding: 0 !important;
        justify-content: center !important;
    }
    body.page-dlv-reviews .employee-header--deliveries .header-right.header-actions-tray {
        grid-column: 3;
        justify-self: end;
        margin-left: 0 !important;
        align-self: center !important;
    }
    body.page-dlv-reviews .employee-header--deliveries .dlv-header-search-mount { width: 100%; }
    body.page-dlv-reviews .employee-header--deliveries .dlv-header-search-mount .dlv-search-field {
        position: relative;
        display: flex;
        align-items: center;
        width: 100%;
        border-radius: 9999px;
        background: #fff;
        border: 1px solid #e2e8f0;
        overflow: hidden;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    body.page-dlv-reviews .employee-header--deliveries .dlv-header-search-mount .dlv-search-field:focus-within {
        border-color: #a5b4fc;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
    }
    body.page-dlv-reviews .employee-header--deliveries .dlv-header-search-mount .dlv-search-icon {
        position: absolute;
        left: 14px;
        color: #94a3b8;
        pointer-events: none;
        z-index: 1;
    }
    body.page-dlv-reviews .employee-header--deliveries .dlv-header-search-mount .dlv-search-input {
        width: 100%;
        padding: 0.55rem 1rem 0.55rem 2.35rem;
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
}
@media (max-width: 767.98px) {
    body.page-dlv-reviews .employee-header--deliveries .employee-header-center-slot { display: none !important; }
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
@media (max-width: 1280px) { main.main-content.dlv-react-root { padding: 0 1rem 1.75rem !important; } }
@media (max-width: 1024px) { main.main-content.dlv-react-root { padding: 0 0.875rem 1.5rem !important; } }
@media (max-width: 767.98px) {
    body.page-dlv-reviews { --header-height: 3rem; }
    body.page-dlv-reviews .employee-header.employee-header--deliveries { padding: 0 0.75rem !important; }
    body.page-dlv-reviews .employee-header--deliveries .header-content {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 0.5rem !important;
        min-height: 3rem !important;
        padding: 0.5rem 0 !important;
    }
    body.page-dlv-reviews .employee-header--deliveries .header-left { position: static !important; flex: 0 0 auto; order: 1; }
    body.page-dlv-reviews .employee-header--deliveries .employee-header-page-heading {
        order: 2;
        flex: 1 1 auto;
        min-width: 0;
        margin-left: 0 !important;
        padding-left: 0 !important;
        padding-right: 0.25rem !important;
    }
    body.page-dlv-reviews .employee-header--deliveries .employee-header-page-title {
        font-size: 1rem !important;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    body.page-dlv-reviews .employee-header--deliveries .header-right.header-actions-tray {
        order: 3;
        flex: 0 0 auto;
        gap: 0.35rem !important;
        margin-left: auto !important;
        align-self: center !important;
    }
    main.main-content.dlv-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-dlv-reviews,
html[data-theme="dark"] body.page-dlv-reviews.dashboard,
html[data-theme="dark"] body.page-dlv-reviews .layout-main-wrapper,
html[data-theme="dark"] body.page-dlv-reviews .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-dlv-reviews main.main-content.dlv-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-dlv-reviews .employee-header.employee-header--deliveries {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-dlv-reviews .employee-header--deliveries .employee-header-page-title {
    color: #f8fafc !important;
}
</style>

<main class="main-content dlv-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to use Delivery Logistics.</div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
