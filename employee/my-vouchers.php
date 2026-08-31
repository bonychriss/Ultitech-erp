<?php
require_once '../includes/functions.php';
requireLogin();
ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();
ensureRestrictedColumn();
ensureVoucherReferenceColumn();
ensureWhatsAppColumn();

// -------------------------------------------------------------------------
// My Vouchers uses the same React desk table as the admin dashboard
// (dark header, zebra rows, star + ⋮ actions) — without KPI cards.
// -------------------------------------------------------------------------
require_once __DIR__ . '/dashboard-ui/lib.php';

$assets = dashboardUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>My Vouchers</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>My Vouchers</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>employee/dashboard-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$flashMsg = $_SESSION['success_msg'] ?? ($_SESSION['error_msg'] ?? '');
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
if ($flashMsg === '' && isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $flashMsg = 'Voucher deleted permanently.';
} elseif ($flashMsg === '' && isset($_GET['error'])) {
    $errMap = [
        'invalid' => 'Invalid request.',
        'forbidden' => 'You do not have permission to perform that action.',
        'delete_failed' => 'Could not delete the voucher. Please try again.',
    ];
    $flashMsg = $errMap[(string) $_GET['error']] ?? '';
}

$qsModule = isset($_GET['module']) && (string) $_GET['module'] !== '' ? (string) $_GET['module'] : 'voucher';
$employeeDir = dashboardUiWebBasePath();
$rootDir = rtrim(dirname($employeeDir), '/');
$adminDir = ($rootDir === '' || $rootDir === '/') ? '/admin' : ($rootDir . '/admin');
$viewUrl = ($rootDir === '' || $rootDir === '/') ? '/view-voucher.php' : ($rootDir . '/view-voucher.php');

$referenceToggleUrl = function_exists('paymentVoucherReferenceToggleUrl')
    ? paymentVoucherReferenceToggleUrl()
    : (function_exists('app_url') ? app_url('/toggle-voucher-reference.php') : '/toggle-voucher-reference.php');

$isAdminViewer = function_exists('isAdmin') && isAdmin();

$dashboardConfig = [
    'role' => $isAdminViewer ? 'admin' : 'employee',
    'inlineApproveReject' => $isAdminViewer,
    'hideKpis' => true,
    'ownVouchersOnly' => true,
    'listTitle' => 'My Vouchers',
    'share' => false,
    'module' => $qsModule,
    'myVouchersUrl' => $employeeDir . '/my-vouchers.php',
    'createUrl' => $employeeDir . '/create-voucher.php',
    'viewUrl' => $viewUrl,
    'editUrl' => $employeeDir . '/edit-voucher.php',
    'deleteUrl' => $isAdminViewer ? ($adminDir . '/dashboard.php') : ($employeeDir . '/delete-voucher.php'),
    'deleteMode' => $isAdminViewer ? 'action' : 'simple',
    'approveUrl' => $adminDir . '/dashboard.php',
    'markPaidUrl' => $isAdminViewer ? ($adminDir . '/dashboard.php') : ($employeeDir . '/mark-paid.php'),
    'reportsUrl' => $adminDir . '/reports.php',
    'userManualUrl' => $employeeDir . '/user-manual.php',
    'referenceToggleUrl' => $referenceToggleUrl,
    'flash' => $flashMsg,
    'mountSearchInHeader' => true,
];

$GLOBALS['_erp_header_style_linked'] = false;
$employeeHeaderTitle = 'My Vouchers';
$hideHeaderCompanyBranding = true;

$userManualHref = htmlspecialchars($employeeDir . '/user-manual.php?module=' . rawurlencode($qsModule), ENT_QUOTES, 'UTF-8');
$createHref = htmlspecialchars($employeeDir . '/create-voucher.php?module=' . rawurlencode($qsModule), ENT_QUOTES, 'UTF-8');
$reportsHref = htmlspecialchars($adminDir . '/reports.php?module=' . rawurlencode($qsModule), ENT_QUOTES, 'UTF-8');
$canSeeReports = $isAdminViewer || (function_exists('isFinance') && isFinance());

$employeeHeaderCenterHtml = '<div class="ad-header-toolbar">'
    . ($canSeeReports
        ? '<a href="' . $reportsHref . '" class="ad-chip-btn ad-chip-btn--ghost">'
            . '<span class="ad-chip-dollar" aria-hidden="true">$</span> Reports</a>'
        : '')
    . '<div id="ed-dashboard-search-slot" class="ed-dashboard-search-slot"></div>'
    . '<div class="ad-header-actions">'
    . '<a href="' . $userManualHref . '" class="ad-chip-btn ad-chip-btn--ghost ad-chip-btn--icon" title="User Guide" aria-label="User Guide">'
    . '<i class="fas fa-book-open" aria-hidden="true"></i></a>'
    . '<a href="' . $createHref . '" class="ad-chip-btn ad-chip-btn--primary">'
    . '<i class="fas fa-plus" aria-hidden="true"></i> Create Voucher</a>'
    . '</div>'
    . '</div>';

$employeeHeaderRightHtml = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Vouchers - Ultimate General Trading</title>
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
        window.__DASHBOARD_API_BASE__ = <?= json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) ?>;
        window.__DASHBOARD_CFG__ = <?= json_encode($dashboardConfig, JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php require __DIR__ . '/../includes/nav-back-script.php'; ?>
    <style>
        :root { --bg-body: #f1f5f9; --header-height: 72px; --ad-header-h: 64px; }
        body.dashboard { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
        html, body.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
        html::-webkit-scrollbar, body.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }

        .main-content.dashboard-react-root {
            width: 100% !important;
            max-width: none !important;
            padding: 0.35rem 1.25rem 2rem !important;
            box-sizing: border-box;
            background: #f1f5f9 !important;
        }
        .main-content.dashboard-react-root #root { width: 100%; max-width: none; margin: 0; }

        body.dashboard .header.employee-header {
            background: #f1f5f9 !important;
            border: none !important;
            box-shadow: none !important;
            height: auto !important;
            min-height: 64px;
            overflow: visible !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1020 !important;
            padding-bottom: 0 !important;
        }
        body.dashboard .header.employee-header::after { display: none !important; }

        body.dashboard .header.employee-header .header-content {
            display: grid !important;
            grid-template-columns: auto minmax(280px, 1fr) auto;
            align-items: center !important;
            gap: 14px;
            min-height: 64px;
            padding: 8px 20px !important;
            position: static !important;
        }
        body.dashboard .header.employee-header .header-left { display: none !important; }
        body.dashboard .header.employee-header .employee-header-page-heading {
            grid-column: 1;
            margin: 0 !important;
            padding: 0 !important;
            max-width: none;
            align-self: center !important;
        }
        body.dashboard .header.employee-header .employee-header-page-title {
            font-size: 1.35rem !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            white-space: nowrap;
            line-height: 1.2 !important;
        }
        body.dashboard .employee-header-center-slot {
            grid-column: 2;
            display: flex !important;
            align-items: center !important;
            justify-content: stretch !important;
            min-width: 0 !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100%;
        }
        body.dashboard .ad-header-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            min-width: 0;
        }
        body.dashboard .ed-dashboard-search-slot {
            flex: 1 1 auto;
            min-width: 180px;
            max-width: 520px;
        }
        body.dashboard .ad-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
            margin-left: auto;
        }

        .ad-chip-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 40px;
            padding: 0 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none !important;
            white-space: nowrap;
            border: 1px solid transparent;
            transition: background .15s, box-shadow .15s, transform .15s;
        }
        .ad-chip-btn--ghost {
            background: #fff;
            color: #374151 !important;
            border-color: #e5e7eb;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        }
        .ad-chip-btn--ghost:hover { background: #f8fafc; color: #111827 !important; }
        .ad-chip-btn--primary {
            background: #6d5df6;
            color: #fff !important;
            box-shadow: 0 6px 16px rgba(109, 93, 246, .25);
        }
        .ad-chip-btn--primary:hover {
            background: #5b4bd6;
            color: #fff !important;
            transform: translateY(-1px);
        }
        .ad-chip-btn--icon {
            width: 40px;
            padding: 0 !important;
            justify-content: center;
        }
        .ad-chip-dollar { font-weight: 700; }

        body.dashboard .ed-dashboard-search-slot .ed-search,
        body.dashboard .ed-dashboard-search-slot .ed-search--in-header {
            position: static !important;
            width: 100% !important;
            margin: 0 !important;
        }
        body.dashboard .ed-dashboard-search-slot .ed-search-wrap {
            position: relative !important;
            display: flex !important;
            align-items: center !important;
            width: 100%;
        }
        body.dashboard .ed-dashboard-search-slot .ed-search-icon {
            position: absolute !important;
            left: 14px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            color: #9ca3af !important;
            pointer-events: none !important;
            z-index: 1;
        }
        body.dashboard .ed-dashboard-search-slot input.ed-search-input,
        body.dashboard .ed-dashboard-search-slot input.ed-search-input[type="search"] {
            width: 100% !important;
            height: 40px !important;
            padding: 0 44px 0 38px !important;
            border: 1px solid #d1d5db !important;
            border-radius: 9999px !important;
            font-size: 14px !important;
            background: #fff !important;
            color: #111827 !important;
            box-shadow: none !important;
            outline: none !important;
            -webkit-appearance: none !important;
            appearance: none !important;
        }
        body.dashboard .ed-dashboard-search-slot input.ed-search-input:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12) !important;
        }
        body.dashboard .ed-dashboard-search-slot .ed-ai-btn {
            position: absolute !important;
            right: 6px !important;
            top: 50% !important;
            left: auto !important;
            transform: translateY(-50%) !important;
            width: 30px !important;
            height: 30px !important;
            margin: 0 !important;
            border: none !important;
            border-radius: 50% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
            color: #fff !important;
            z-index: 2;
        }
        body.dashboard .ed-dashboard-search-slot .ed-suggestions { z-index: 50; }

        body.dashboard .header.employee-header .header-right.header-actions-tray {
            grid-column: 3;
            position: static !important;
            top: auto !important;
            right: auto !important;
            margin: 0 !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            flex-wrap: nowrap !important;
        }
        body.dashboard .header.employee-header .theme-toggle-btn {
            width: 36px;
            height: 36px;
            flex-shrink: 0;
        }

        html[data-theme="dark"] body.dashboard,
        html[data-theme="dark"] .main-content.dashboard-react-root,
        html[data-theme="dark"] body.dashboard .header.employee-header {
            background: #0f172a !important;
        }
        html[data-theme="dark"] body.dashboard .employee-header-page-title { color: #f8fafc !important; }
        html[data-theme="dark"] .ad-chip-btn--ghost {
            background: #1e293b;
            color: #e2e8f0 !important;
            border-color: #334155;
        }
        html[data-theme="dark"] body.dashboard .ed-dashboard-search-slot input.ed-search-input {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        @media (max-width: 1100px) {
            body.dashboard .header.employee-header .header-content {
                grid-template-columns: auto 1fr;
                grid-template-rows: auto auto;
            }
            body.dashboard .employee-header-center-slot {
                grid-column: 1 / -1;
                grid-row: 2;
            }
            body.dashboard .header.employee-header .header-right.header-actions-tray {
                grid-column: 2;
                grid-row: 1;
                justify-self: end;
            }
        }
        @media (max-width: 767.98px) {
            .main-content.dashboard-react-root { padding: 0.875rem 0.75rem 1.5rem !important; }
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content dashboard-react-root">
        <noscript>
            <div class="alert alert-warning">JavaScript is required to use My Vouchers.</div>
        </noscript>
        <div id="root"></div>
    </main>

    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
    <script>
    (function () {
        var header = document.querySelector('.header.employee-header');
        if (!header) return;
        function syncHeaderHeight() {
            var h = Math.round(header.getBoundingClientRect().height);
            if (h > 0) {
                document.documentElement.style.setProperty('--ad-header-h', h + 'px');
            }
        }
        syncHeaderHeight();
        window.addEventListener('resize', syncHeaderHeight);
        if (window.ResizeObserver) {
            new ResizeObserver(syncHeaderHeight).observe(header);
        }
    })();
    </script>
</body>
</html>
