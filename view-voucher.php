<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user-avatar.php';
require_once __DIR__ . '/modules/balances/functions.php';
requireLogin();

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();

if (!isset($_GET['id'])) {
    header('Location: dashboard.php');
    exit();
}

$voucher_id = (int) $_GET['id'];
$returnParams = '';
$isFinanceMode = false;
if (isset($_GET['return']) && $_GET['return'] === 'finance') {
    $returnParams = '&return=finance';
    $isFinanceMode = true;
}

// Handle Mark Posted action (Finance finalization) before fetching details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_posted']) && (int) $_POST['mark_posted'] === 1) {
    $result = markVoucherPosted($voucher_id, $_SESSION['user_id']);
    $params = [];
    if (!empty($result['ok'])) {
        $params['posted'] = '1';
    } else {
        $params['post_error'] = isset($result['error']) ? $result['error'] : 'Unable to post voucher';
    }
    $redir = 'view-voucher.php?id=' . $voucher_id . '&' . http_build_query($params) . $returnParams;
    header('Location: ' . $redir);
    exit();
}

// Handle Admin Approve/Reject Shortcuts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action']) && isAdmin()) {
    $action = $_POST['admin_action'];
    $comments = trim($_POST['comments'] ?? '');

    $stmtCh = $pdo->prepare('SELECT status FROM payment_vouchers WHERE id = ?');
    $stmtCh->execute([$voucher_id]);
    $currStatus = strtolower((string) $stmtCh->fetchColumn());

    if ($action === 'approved' && $currStatus === 'confirming') {
        $_SESSION['error_msg'] = "You cannot 'Final Approve' a voucher while it is in 'Confirming' state.";
        header('Location: view-voucher.php?id=' . $voucher_id . $returnParams);
        exit();
    }

    try {
        $pdo->beginTransaction();
        $gmName = null;
        $actorUserId = function_exists('resolveVoucherSessionUserId')
            ? (int) resolveVoucherSessionUserId($pdo)
            : (int) ($_SESSION['user_id'] ?? 0);
        if ($actorUserId <= 0) {
            throw new Exception('Your user account could not be verified. Please log out and sign in again.');
        }
        if ($action === 'approved') {
            $approverStmt = $pdo->prepare('SELECT username, email, full_name FROM users WHERE id = ? LIMIT 1');
            $approverStmt->execute([$actorUserId]);
            $approver = $approverStmt->fetch();
            $approverEmail = strtolower(trim($approver['email'] ?? ''));
            $approverUsername = trim($approver['username'] ?? '');
            $approverFullName = trim($approver['full_name'] ?? '');

            if ($approverEmail === 'rajabmwanyika@gmail.com') {
                $gmName = 'RAJABU MWANYIKA';
            } elseif ($approverEmail === 'rajabmsomali@gmail.com') {
                $gmName = $approverFullName !== '' ? $approverFullName : ($approverUsername !== '' ? $approverUsername : null);
            } else {
                $gmName = $approverFullName !== '' ? $approverFullName : $approverUsername;
            }

            $up = $pdo->prepare('UPDATE payment_vouchers SET status=?, approved_by=?, approved_at=NOW(), general_manager=? WHERE id=?');
            $up->execute([$action, $actorUserId, $gmName, $voucher_id]);

            $upApp = $pdo->prepare("UPDATE approvals SET status = 'approved', approved_at = NOW(), approver_name = IF(approver_name IS NULL OR approver_name='', ?, approver_name) WHERE voucher_id = ? AND status = 'pending'");
            $upApp->execute([$gmName ?: $_SESSION['full_name'], $voucher_id]);
            if ($gmName && function_exists('erp_upsert_general_manager_approval')) {
                erp_upsert_general_manager_approval($pdo, $voucher_id, $gmName, $actorUserId);
            }
        } else {
            $up = $pdo->prepare('UPDATE payment_vouchers SET status=?, approved_by=?, approved_at=NOW() WHERE id=?');
            $up->execute([$action, $actorUserId, $voucher_id]);
        }

        $pdo->commit();

        try {
            logVoucherAction($voucher_id, $actorUserId, $action, $comments);
        } catch (Throwable $eLog) {
            error_log('view-voucher admin_action log failed: ' . $eLog->getMessage());
        }

        try {
            notifyUserVoucherStatus($voucher_id, $action);
        } catch (Exception $eN) { /* ignore */ }

        $_SESSION['success_msg'] = 'Voucher has been ' . $action . ' successfully.';
        header('Location: view-voucher.php?id=' . $voucher_id . $returnParams);
        exit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error processing voucher: ' . $ex->getMessage();
    }
}

// Handle Restricted Toggle action (Finance/Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_restricted'])) {
    $isAuth = isAdmin();
    if (!$isAuth && isFinance()) {
        $stmtCheck = $pdo->prepare('SELECT u.department AS creator_department FROM payment_vouchers pv LEFT JOIN users u ON pv.created_by = u.id WHERE pv.id = ?');
        $stmtCheck->execute([$voucher_id]);
        $rowCheck = $stmtCheck->fetch();
        $cDept = $rowCheck ? strtolower(trim((string) $rowCheck['creator_department'])) : '';
        if ($cDept === 'finance') {
            $isAuth = true;
        }
    }

    if ($isAuth) {
        $newState = (int) $_POST['toggle_restricted'];
        $stmt = $pdo->prepare('UPDATE payment_vouchers SET is_restricted=? WHERE id=?');
        $stmt->execute([$newState, $voucher_id]);
        $_SESSION['success_msg'] = $newState ? 'Voucher locked (restricted) successfully.' : 'Voucher unlocked (unrestricted) successfully.';
    }
    header('Location: view-voucher.php?id=' . $voucher_id . $returnParams);
    exit();
}

require_once __DIR__ . '/view-voucher-ui/load-data.php';
require_once __DIR__ . '/view-voucher-ui/lib.php';

$vvModuleQs = '';
if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
    $vvModuleQs = '?module=' . rawurlencode((string) $_GET['module']);
}

$result = vv_load_view_payload($pdo, $voucher_id, [
    'returnFinance' => $isFinanceMode,
    'moduleQs' => $vvModuleQs,
]);

if (!$result['ok']) {
    $code = (int) ($result['code'] ?? 500);
    http_response_code($code);
    $back = app_url(isAdmin() ? 'admin/dashboard.php' : 'employee/dashboard.php') . $vvModuleQs;
    echo '<!DOCTYPE html><html><head><title>Voucher</title></head><body style="font-family:sans-serif;text-align:center;margin-top:50px;color:#b91c1c;">';
    echo '<h1>' . ($code === 403 ? 'Access Denied' : 'Not Found') . '</h1>';
    echo '<p>' . htmlspecialchars($result['error'] ?? 'Error') . '</p>';
    echo '<a href="' . htmlspecialchars($back) . '" style="display:inline-block;margin-top:20px;background:#333;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">Return</a>';
    echo '</body></html>';
    exit;
}

$vvData = $result['payload'];
$assets = viewVoucherUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><title>Payment Voucher</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Payment Voucher</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>view-voucher-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$flash = [
    'created' => isset($_GET['created']),
    'updated' => isset($_GET['updated']),
    'posted' => isset($_GET['posted']),
    'postError' => isset($_GET['post_error']) ? (string) $_GET['post_error'] : '',
    'paid' => isset($_GET['paid']),
    'payError' => isset($_GET['pay_error']) ? (string) $_GET['pay_error'] : '',
];

$vvConfig = [
    'apiUrl' => $assets['apiUrl'],
    'voucherId' => $voucher_id,
    'data' => $vvData,
    'flash' => $flash,
];

$employeeHeaderTitle = 'Voucher Preview';
$hideHeaderCompanyBranding = true;
$GLOBALS['_erp_header_style_linked'] = true;
$vvBreadcrumbHome = $vvData['breadcrumbs']['home'];
$vvBreadcrumbAll = $vvData['breadcrumbs']['all'];
$employeeHeaderSubtitle = '<nav class="vv-breadcrumb" aria-label="Breadcrumb">'
    . '<a class="vv-breadcrumb-link" href="' . htmlspecialchars($vvBreadcrumbHome) . '">Home</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<a class="vv-breadcrumb-link vv-breadcrumb-link--active" href="' . htmlspecialchars($vvBreadcrumbAll) . '">Vouchers</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<span class="vv-breadcrumb-current">' . htmlspecialchars($vvData['voucher']['voucher_no'] ?? '') . '</span>'
    . '</nav>';

$statusClass = htmlspecialchars($vvData['status']['className'] ?? 'vv-status-pending');
$statusLabel = htmlspecialchars($vvData['status']['label'] ?? 'Pending');
$employeeHeaderRightHtml = '<div class="vv-header-status-actions no-print">'
    . '<span class="vv-status-badge ' . $statusClass . '">' . $statusLabel . '</span>'
    . '<div id="vv-actions-header-mount" class="vv-toolbar-desktop"></div>'
    . '</div>';
$vvActionsInHeader = true;

$vvStyleCss = app_url('/assets/css/style.css');
$vvViewCss = app_url('/assets/css/voucher-view-page.css');
$vvDlCss = app_url('/assets/css/download-button.css');
$vvApprovalCss = app_url('/assets/css/approval-flow.css');
$vvCssV = (string) time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Voucher - <?= htmlspecialchars($vvData['voucher']['voucher_no'] ?? '') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($vvStyleCss) ?>?v=<?= $vvCssV ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($vvDlCss) ?>?v=<?= $vvCssV ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($vvViewCss) ?>?v=<?= $vvCssV ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($vvApprovalCss) ?>?v=<?= $vvCssV ?>">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <?php require __DIR__ . '/includes/approval-flow-styles.php'; ?>
    <?php require __DIR__ . '/includes/voucher-view-actions-styles.php'; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        window.__VV_CFG__ = <?= json_encode($vvConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <style>
        :root { --vv-page-bg: #ffffff; }
        body.dashboard.vv-view-voucher-page,
        body.dashboard.vv-view-voucher-page .layout-main-wrapper,
        body.dashboard.vv-view-voucher-page .layout-main-wrapper > .d-flex.flex-column {
            background: var(--vv-page-bg) !important;
            font-family: 'Inter', 'Poppins', system-ui, sans-serif;
        }
        /* Flat top header — same as employee/dashboard.php */
        body.dashboard.vv-view-voucher-page .header,
        body.dashboard.vv-view-voucher-page .employee-header,
        body.dashboard.vv-view-voucher-page .admin-header {
            background: var(--vv-page-bg) !important;
            border: none !important;
            box-shadow: none !important;
        }
        body.dashboard.vv-view-voucher-page .employee-header .header-content,
        body.dashboard.vv-view-voucher-page .admin-header .header-content {
            align-items: flex-start !important;
            position: relative !important;
            gap: 12px;
            max-width: 1240px !important;
            margin: 0 auto !important;
            width: 100%;
            padding: 14px 20px 12px 12px !important;
            min-height: 0 !important;
        }
        body.dashboard.vv-view-voucher-page .employee-header .employee-header-page-heading {
            align-self: flex-start !important;
            min-width: 0;
            flex: 1 1 auto;
            padding-right: 96px;
        }
        /* Utility icons (theme + bell) pinned top-right; status/actions on row below */
        body.dashboard.vv-view-voucher-page .employee-header .header-actions-tray {
            position: absolute !important;
            top: 12px !important;
            right: 20px !important;
            margin: 0 !important;
            padding: 0 !important;
            display: grid !important;
            grid-template-columns: auto auto;
            grid-template-rows: auto auto;
            gap: 6px 10px;
            align-items: center !important;
            justify-items: center !important;
            width: auto !important;
            flex-wrap: nowrap !important;
        }
        body.dashboard.vv-view-voucher-page .header-actions-tray .theme-toggle-btn {
            grid-column: 1;
            grid-row: 1;
            width: 36px !important;
            height: 36px !important;
            min-width: 36px !important;
            margin: 0 !important;
        }
        body.dashboard.vv-view-voucher-page .header-actions-tray .notif {
            grid-column: 2;
            grid-row: 1;
        }
        body.dashboard.vv-view-voucher-page .header-actions-tray .header-notif-bell-btn {
            width: 36px !important;
            height: 36px !important;
            min-width: 36px !important;
            min-height: 36px !important;
        }
        body.dashboard.vv-view-voucher-page .header-actions-tray .vv-header-status-actions {
            grid-column: 1 / -1;
            grid-row: 2;
            justify-content: flex-end !important;
            width: 100%;
        }
        body.dashboard.vv-view-voucher-page .vv-header-status-actions {
            display: inline-flex !important;
            align-items: center !important;
            gap: 10px !important;
        }
        body.dashboard.vv-view-voucher-page .vv-status-badge {
            display: inline-flex !important;
            align-items: center !important;
            height: 36px;
            padding: 0 14px !important;
            line-height: 1;
        }
        body.dashboard.vv-view-voucher-page .vv-actions-btn {
            height: 36px !important;
            min-height: 36px !important;
            padding: 0 16px !important;
            display: inline-flex !important;
            align-items: center !important;
        }
        @media (max-width: 767.98px) {
            body.dashboard.vv-view-voucher-page .employee-header .header-content {
                padding: 8px 12px 6px !important;
                align-items: center !important;
                min-height: 0 !important;
            }
            body.dashboard.vv-view-voucher-page .employee-header .employee-header-page-heading {
                padding-right: 84px !important;
            }
            body.dashboard.vv-view-voucher-page .employee-header .header-actions-tray {
                top: 8px !important;
                right: 12px !important;
                display: flex !important;
                grid-template-columns: none !important;
                grid-template-rows: none !important;
                flex-direction: row !important;
                gap: 8px !important;
            }
            body.dashboard.vv-view-voucher-page .header-actions-tray .vv-header-status-actions {
                display: none !important;
            }
            body.dashboard.vv-view-voucher-page .employee-header-page-title {
                font-size: 1.05rem !important;
                color: #111827 !important;
            }
            .main-content.vv-react-shell {
                padding: 0.5rem 0.75rem 1.5rem !important;
            }
            body.dashboard.vv-view-voucher-page #root .vv-voucher-toolbar.voucher-actions-bar {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-bottom: 10px !important;
            }
            body.dashboard.vv-view-voucher-page #root .vv-voucher-toolbar .vv-actions-btn {
                width: auto !important;
            }
        }
        .main-content.vv-react-shell {
            width: 100% !important;
            max-width: none !important;
            padding: 0.25rem 1.25rem 2rem !important;
            box-sizing: border-box;
            background: var(--vv-page-bg) !important;
            border: none !important;
            box-shadow: none !important;
        }
        .main-content.vv-react-shell #root { width: 100%; max-width: none; margin: 0; }
        @media (max-width: 1024px) {
            .main-content.vv-react-shell { padding: 1rem 0.875rem 1.5rem !important; }
        }
        @media (max-width: 767.98px) {
            .main-content.vv-react-shell { padding: 0.5rem 0.75rem 1.5rem !important; }
        }
        body.dashboard.vv-view-voucher-page #root .vv-voucher-toolbar.voucher-actions-bar {
            display: flex;
            margin-bottom: 16px;
        }
        @media (min-width: 768px) {
            body.dashboard.vv-view-voucher-page.vv-actions-in-header #root .vv-voucher-toolbar.voucher-actions-bar {
                display: none !important;
            }
        }
        body.dashboard.vv-view-voucher-page .vv-actions-btn,
        body.dashboard.vv-view-voucher-page .vv-header-actions .vv-actions-btn {
            border-radius: 9999px !important;
        }
    </style>
</head>
<body class="dashboard vv-view-voucher-page vv-actions-in-header">
    <?php
    if (isAdmin()) {
        require_once __DIR__ . '/includes/header_admin.php';
    } else {
        require_once __DIR__ . '/includes/header_employee.php';
    }
    ?>

    <main class="main-content vv-react-shell">
        <noscript><div class="alert alert-warning">JavaScript is required to view this voucher.</div></noscript>
        <div id="root"></div>
    </main>

    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
    <style id="vv-flat-overrides">
        html body.dashboard.vv-view-voucher-page .vv-content-card,
        html body.dashboard.vv-view-voucher-page .vv-preview-section,
        html body.dashboard.vv-view-voucher-page .vv-card-approval,
        html body.dashboard.vv-view-voucher-page .vv-card-docs,
        html body.dashboard.vv-view-voucher-page .approval-card,
        html body.dashboard.vv-view-voucher-page .documents-card {
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
        }
        html body.dashboard.vv-view-voucher-page .voucher-container,
        html body.dashboard.vv-view-voucher-page .voucher-paper,
        html body.dashboard.vv-view-voucher-page #voucherFull {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }
    </style>
</body>
</html>
