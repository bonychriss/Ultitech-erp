<?php
require_once '../includes/functions.php';
requireLogin();
ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();
ensureRestrictedColumn();

// --- Action Handling (preserved from the legacy page) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['voucher_id'])) {
    $voucherId = (int) $_POST['voucher_id'];
    $action = $_POST['action'];
    try {
        if ($action === 'approved') {
            if (!isAdmin()) throw new Exception('Not authorized');
            approveVoucherByAdmin($voucherId, (int)$_SESSION['user_id']);
            header('Location: ' . company_url('employee/dashboard') . '?msg=approved'); exit;
        } elseif ($action === 'rejected') {
            if (!isAdmin()) throw new Exception('Not authorized');
            rejectVoucherByAdmin($voucherId, (int)$_SESSION['user_id']);
            header('Location: ' . company_url('employee/dashboard') . '?msg=rejected'); exit;
        } elseif ($action === 'delete') {
            if (canDeleteVoucher($voucherId, (int)$_SESSION['user_id'])) {
                deleteVoucherHard($voucherId, (int)$_SESSION['user_id']);
                deleteVoucherHard($voucherId, (int)$_SESSION['user_id']);
                header('Location: ' . company_url('employee/dashboard') . '?msg=deleted'); exit;
            }
        }
    } catch (Exception $e) {}
}

// -------------------------------------------------------------------------
// Render the React front-end shell.
// -------------------------------------------------------------------------
require_once __DIR__ . '/dashboard-ui/lib.php';

$assets = dashboardUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Dashboard</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Dashboard</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>employee/dashboard-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

// Flash message from action redirects.
$flashMsg = '';
if (isset($_GET['msg'])) {
    $msgMap = [
        'approved' => 'Voucher approved successfully.',
        'rejected' => 'Voucher rejected.',
        'deleted' => 'Voucher deleted permanently.',
        'created' => 'Voucher created successfully.',
        'bulk_success' => 'Bulk upload successful.',
    ];
    $flashMsg = $msgMap[(string) $_GET['msg']] ?? '';
}

$dashboardConfig = [
    'myVouchersUrl' => company_url('employee/my-vouchers.php'),
    'createUrl' => company_url('employee/create-voucher.php'),
    'viewUrl' => '../view-voucher.php',
    'editUrl' => 'edit-voucher.php',
    'deleteUrl' => 'delete-voucher.php',
    'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'voucher',
    'share' => true,
    'referenceToggleUrl' => company_url('toggle-voucher-reference.php'),
    'flash' => $flashMsg,
];

$page_title = 'Dashboard';
$employeeHeaderTitle = 'Dashboard';
$hideHeaderCompanyBranding = true;
$GLOBALS['_erp_header_style_linked'] = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Ultimate General Trading</title>
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
    <style>
        :root { --bg-body: #f1f5f9; }
        body.dashboard,
        body.dashboard .layout-main-wrapper {
            background-color: #f1f5f9 !important;
            font-family: 'Inter', sans-serif;
        }
        /* Hide the vertical scrollbar (scrolling still works) */
        html, body.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
        html::-webkit-scrollbar, body.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
        .main-content.dashboard-react-root {
            width: 100% !important;
            max-width: none !important;
            padding: 0.25rem 1.25rem 2rem !important;
            box-sizing: border-box;
            background: #f1f5f9 !important;
        }
        .main-content.dashboard-react-root #root { width: 100%; max-width: none; margin: 0; }
        @media (max-width: 1024px) { .main-content.dashboard-react-root { padding: 1rem 0.875rem 1.5rem !important; } }
        @media (max-width: 767.98px) { .main-content.dashboard-react-root { padding: 0.875rem 0.75rem 1.5rem !important; } }
        /* Flatten the top header card on this page only + match the page bg */
        body.dashboard .header,
        body.dashboard .employee-header {
            position: static !important;
            top: auto !important;
            background: #f1f5f9 !important;
            border: none !important;
            box-shadow: none !important;
        }
        /* Pin the dark-theme + notification icons to the very top of the header bar */
        body.dashboard .employee-header .header-content {
            align-items: flex-start !important;
            position: relative;
        }
        body.dashboard .employee-header .employee-header-page-heading {
            align-self: flex-start !important;
        }
        body.dashboard .employee-header .header-actions-tray {
            position: absolute !important;
            top: -10px !important;
            right: 20px;
            margin: 0 !important;
            padding: 0 !important;
            align-self: flex-start !important;
        }

        html[data-theme="dark"] body.dashboard,
        html[data-theme="dark"] body.dashboard .layout-main-wrapper,
        html[data-theme="dark"] .main-content.dashboard-react-root,
        html[data-theme="dark"] body.dashboard .header,
        html[data-theme="dark"] body.dashboard .employee-header {
            background: #0f172a !important;
        }
        html[data-theme="dark"] body.dashboard .employee-header-page-title {
            color: #f8fafc !important;
        }
        html[data-theme="dark"] body.dashboard .ed-sticky-top,
        html[data-theme="dark"] body.dashboard .ed-sticky-top::before {
            background: #0f172a !important;
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content dashboard-react-root">
        <noscript>
            <div class="alert alert-warning">JavaScript is required to use the Dashboard.</div>
        </noscript>
        <div id="root"></div>
    </main>

    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
