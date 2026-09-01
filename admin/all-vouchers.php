<?php
require_once '../includes/functions.php';
$balancesFns = __DIR__ . '/../modules/balances/functions.php';
if (is_file($balancesFns)) {
    require_once $balancesFns;
}
requireAdmin();
if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
// Ensure posted/swift columns available for queries
ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();
if (function_exists('ensureVoucherAttachmentsSchema')) {
    ensureVoucherAttachmentsSchema();
}
if (function_exists('ensureApprovalsTableSchema')) {
    ensureApprovalsTableSchema();
}
ensureVoucherReferenceColumn();
if (function_exists('repairMissingVoucherApprovalRows')) {
    repairMissingVoucherApprovalRows($pdo, 50);
}

// -------------------------------------------------------------------------
// POST handlers (Mark Paid / Mark Posted) — reused as-is by the React UI.
// The React front-end submits these forms to this same endpoint so that the
// server-side payment + ledger logic is preserved without duplication.
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['voucher_id'])) {
    $voucher_id = (int)$_POST['voucher_id'];
    
    if (isset($_POST['mark_paid']) && $_POST['mark_paid'] == '1') {
        $swift_path = null;

        if (isset($_FILES['swift_file']) && $_FILES['swift_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/uploads/vouchers/' . $voucher_id . '/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = 'swift_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['swift_file']['name']);
            if (move_uploaded_file($_FILES['swift_file']['tmp_name'], $upload_dir . $filename)) {
                $swift_path = 'assets/uploads/vouchers/' . $voucher_id . '/' . $filename;
            }
        }

        try {
            $pdo->beginTransaction();

            $vStmt = $pdo->prepare("SELECT voucher_no, payee_name, total_amount, currency FROM payment_vouchers WHERE id = ? FOR UPDATE");
            $vStmt->execute([$voucher_id]);
            $v = $vStmt->fetch();
            if (!$v) throw new Exception("Voucher not found.");

            $pdo->rollBack(); 
            
            $result = markVoucherPaidStrict($voucher_id, (int)$_SESSION['user_id']);
            if (!$result['ok']) throw new Exception($result['error']);

            if ($swift_path) {
                $upd = $pdo->prepare("UPDATE payment_vouchers SET swift_document = ? WHERE id = ?");
                $upd->execute([$swift_path, $voucher_id]);
            }
            
            $_SESSION['success_msg'] = "Voucher marked as paid successfully.";

            // --- UNIFIED LEDGER: POST TO GENERAL LEDGER ON PAYMENT ---
            try {
                require_once '../includes/accounting_service.php';
                $accSvc = new AccountingService($pdo);
                $creditAccId = $accSvc->getAccountIdByCode('1001'); // Fallback to Petty Cash/Bank
                
                $itemStmt = $pdo->prepare("SELECT budget_type, amount, name FROM voucher_items WHERE voucher_id = ?");
                $itemStmt->execute([$voucher_id]);
                $vItems = $itemStmt->fetchAll();
                
                $journalPostings = [];
                $totalAmt = 0;
                foreach ($vItems as $vi) {
                    $accCode = $accSvc->mapBudgetToAccountCode($vi['budget_type']);
                    $expenseAccId = $accSvc->getAccountIdByCode($accCode);
                    if ($expenseAccId) {
                        $journalPostings[] = ['account_id' => $expenseAccId, 'debit' => $vi['amount'], 'credit' => 0];
                        $totalAmt += $vi['amount'];
                    }
                }
                
                if ($creditAccId && $totalAmt > 0) {
                    $journalPostings[] = ['account_id' => $creditAccId, 'debit' => 0, 'credit' => $totalAmt];
                    $accSvc->postEntry(date('Y-m-d'), $v['voucher_no'], "Voucher Payment: #".$v['voucher_no'], $journalPostings);
                }
            } catch (Exception $eAcc) {
                error_log("Ledger Posting Failed for Voucher Payment $voucher_id: " . $eAcc->getMessage());
            }
            // --- END UNIFIED LEDGER ---

        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error_msg'] = $e->getMessage();
        }
    } elseif (isset($_POST['mark_posted']) && $_POST['mark_posted'] == '1') {
        $result = markVoucherPosted($voucher_id, (int)$_SESSION['user_id']);
        if ($result['ok']) {
            $_SESSION['success_msg'] = 'Voucher marked as posted.';
        } else {
            $_SESSION['error_msg'] = 'Error: ' . ($result['error'] ?? 'Failed to mark voucher as posted.');
        }
    }

    // Redirect to avoid resubmission and reload fresh data in the React UI.
    $redirectParams = $_GET;
    unset($redirectParams['voucher_id'], $redirectParams['mark_paid'], $redirectParams['mark_posted']);
    $redirect_url = 'all-vouchers.php' . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : '');
        header('Location: ' . $redirect_url);
        exit;
}

// -------------------------------------------------------------------------
// Render the React front-end shell.
// -------------------------------------------------------------------------
require_once __DIR__ . '/vouchers-ui/lib.php';

$qsModule = isset($_GET['module']) && (string) $_GET['module'] !== '' ? (string) $_GET['module'] : 'voucher';

$assets = vouchersUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>All Vouchers</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>All Vouchers</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>admin/vouchers-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$flashMsg = $_SESSION['success_msg'] ?? ($_SESSION['error_msg'] ?? '');
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

$referenceToggleUrl = function_exists('paymentVoucherReferenceToggleUrl')
    ? paymentVoucherReferenceToggleUrl()
    : (function_exists('app_url') ? app_url('/toggle-voucher-reference.php') : '/toggle-voucher-reference.php');

// Absolute paths so the React desk works on pretty URLs like /{slug}/payment-vouchers.
$adminBase = vouchersUiWebBasePath();
$rootBase = rtrim(str_replace('\\', '/', dirname($adminBase)), '/');
$employeeBase = ($rootBase === '' || $rootBase === '/') ? '/employee' : ($rootBase . '/employee');
$suggestUrl = $adminBase . '/api-search-suggestions.php';

$vouchersConfig = [
    'viewUrl' => $employeeBase . '/view-voucher.php',
    'editUrl' => $employeeBase . '/edit-voucher.php',
    'createUrl' => $employeeBase . '/create-voucher.php',
    'dashboardUrl' => $adminBase . '/dashboard.php',
    'reportsUrl' => $adminBase . '/reports.php',
    'userManualUrl' => $employeeBase . '/user-manual.php',
    'inlineApproveReject' => true,
    'share' => false,
    'approveUrl' => $adminBase . '/dashboard.php',
    'deleteUrl' => $adminBase . '/dashboard.php',
    'deleteMode' => 'action',
    'markPostedUrl' => $adminBase . '/all-vouchers.php',
    'markPostedMode' => 'query',
    'markPaidUrl' => $adminBase . '/all-vouchers.php',
    'markPaidMode' => 'swift',
    'pageTitle' => 'All Vouchers',
    'pageSubtitle' => 'Manage and track all payment vouchers',
    'mountSearchInHeader' => true,
];

$GLOBALS['_erp_header_style_linked'] = false;
$employeeHeaderTitle = 'All Vouchers';
$reportsHref = htmlspecialchars($adminBase . '/reports.php?module=' . rawurlencode($qsModule), ENT_QUOTES, 'UTF-8');
$userManualHref = htmlspecialchars($employeeBase . '/user-manual.php?module=' . rawurlencode($qsModule), ENT_QUOTES, 'UTF-8');
$createHref = htmlspecialchars($employeeBase . '/create-voucher.php?module=' . rawurlencode($qsModule), ENT_QUOTES, 'UTF-8');

$employeeHeaderCenterHtml = '<div class="ad-header-toolbar">'
    . '<a href="' . $reportsHref . '" class="ad-chip-btn ad-chip-btn--ghost">'
    . '<span class="ad-chip-dollar" aria-hidden="true">$</span> Reports</a>'
    . '<div id="ed-dashboard-search-slot" class="ed-dashboard-search-slot"></div>'
    . '<div class="ad-header-actions">'
    . '<a href="' . $userManualHref . '" class="ad-chip-btn ad-chip-btn--ghost ad-chip-btn--icon" title="User Guide" aria-label="User Guide">'
    . '<i class="fas fa-book-open" aria-hidden="true"></i></a>'
    . '<a href="' . $createHref . '" class="ad-chip-btn ad-chip-btn--primary">'
    . '<i class="fas fa-plus" aria-hidden="true"></i> Create Voucher</a>'
    . '</div>'
    . '</div>';

$employeeHeaderAfterThemeHtml = '<div id="pv-filter-slot" class="pv-filter-slot pv-filter-slot--tray"></div>';
$employeeHeaderRightHtml = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Vouchers - Ultimate General Trading</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__VOUCHERS_API_BASE__ = <?= json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) ?>;
        window.__VOUCHERS_MODULE__ = <?= json_encode($qsModule, JSON_UNESCAPED_SLASHES) ?>;
        window.__VOUCHERS_REFERENCE_TOGGLE_URL__ = <?= json_encode($referenceToggleUrl, JSON_UNESCAPED_SLASHES) ?>;
        window.__VOUCHERS_SUGGEST_URL__ = <?= json_encode($suggestUrl, JSON_UNESCAPED_SLASHES) ?>;
        window.__VOUCHERS_FLASH__ = <?= json_encode($flashMsg, JSON_UNESCAPED_SLASHES) ?>;
        window.__VOUCHERS_CFG__ = <?= json_encode($vouchersConfig, JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <style>
        :root { --bg-body: #f1f5f9; --header-height: 72px; }
        body.dashboard { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
        html, body.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
        html::-webkit-scrollbar, body.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }

        .main-content.vouchers-react-root {
            width: 100% !important;
            max-width: none !important;
            padding: 0.35rem 1.25rem 2rem !important;
            box-sizing: border-box;
            background: #f1f5f9 !important;
        }
        .main-content.vouchers-react-root #root { width: 100%; max-width: none; margin: 0; }

        body.dashboard .header.admin-header {
            background: #f1f5f9 !important;
            border: none !important;
            box-shadow: none !important;
            height: auto !important;
            min-height: 64px;
            overflow: visible !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1020 !important;
        }
        body.dashboard .header.admin-header::after { display: none !important; }

        body.dashboard .header.admin-header .header-content {
            display: grid !important;
            grid-template-columns: auto minmax(280px, 1fr) auto;
            align-items: center !important;
            gap: 14px;
            min-height: 64px;
            padding: 8px 20px !important;
            position: static !important;
        }
        body.dashboard .header.admin-header .header-left {
            display: none !important;
            align-items: center !important;
            justify-content: flex-start !important;
            margin: 0 !important;
            padding: 0 !important;
            grid-column: 1;
            z-index: 6;
        }
        body.dashboard .header.admin-header .employee-header-menu-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-width: 2.5rem;
            min-height: 2.5rem;
            margin: 0 !important;
            padding: 0.2rem !important;
            color: #0f172a !important;
            line-height: 1;
        }
        body.dashboard .header.admin-header .employee-header-menu-btn .erp-hamburger {
            color: #0f172a !important;
        }
        body.dashboard .header.admin-header .employee-header-page-heading {
            grid-column: 1;
            margin: 0 !important;
            padding: 0 !important;
            max-width: none;
        }
        body.dashboard .header.admin-header .employee-header-page-title {
            font-size: 1.35rem !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            white-space: nowrap;
            line-height: 1.2 !important;
        }
        body.dashboard .admin-header-center-slot {
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
            max-width: 480px;
        }
        body.dashboard .pv-filter-slot--tray {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
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

        body.dashboard .ed-dashboard-search-slot .pv-top-search,
        body.dashboard .ed-dashboard-search-slot .pv-search--in-header {
            position: static !important;
            left: auto !important;
            top: auto !important;
            transform: none !important;
            width: 100% !important;
            margin: 0 !important;
            text-align: left;
        }
        body.dashboard .ed-dashboard-search-slot .pv-search-wrap {
            position: relative !important;
            display: flex !important;
            align-items: center !important;
            width: 100%;
        }
        body.dashboard .ed-dashboard-search-slot .pv-search-icon {
            position: absolute !important;
            left: 14px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            color: #9ca3af !important;
            pointer-events: none !important;
            z-index: 1;
        }
        body.dashboard .ed-dashboard-search-slot input.pv-search-input,
        body.dashboard .ed-dashboard-search-slot input.pv-search-input[type="search"] {
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
        body.dashboard .ed-dashboard-search-slot input.pv-search-input:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12) !important;
        }
        body.dashboard .ed-dashboard-search-slot .pv-ai-btn {
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
        body.dashboard .ed-dashboard-search-slot .pv-suggestions { z-index: 50; }
        body.dashboard .pv-filter-slot .pv-filter-btn,
        body.dashboard .pv-filter-slot--tray .pv-filter-btn {
            width: 36px !important;
            height: 36px !important;
            min-width: 36px !important;
            min-height: 36px !important;
            padding: 0 !important;
            border-radius: 50% !important;
            border: 1px solid #e5e7eb !important;
            background: #fff !important;
            color: #374151 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            position: relative;
        }
        body.dashboard .pv-filter-slot .pv-filter-btn.is-active,
        body.dashboard .pv-filter-slot .pv-filter-btn:hover,
        body.dashboard .pv-filter-slot--tray .pv-filter-btn.is-active,
        body.dashboard .pv-filter-slot--tray .pv-filter-btn:hover {
            background: #f8fafc !important;
            color: #111827 !important;
            border-color: #cbd5e1 !important;
        }

        body.dashboard .header.admin-header .header-right.header-actions-tray {
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
        body.dashboard .header.admin-header .theme-toggle-btn {
            width: 36px;
            height: 36px;
            flex-shrink: 0;
        }

        html[data-theme="dark"] body.dashboard,
        html[data-theme="dark"] .main-content.vouchers-react-root,
        html[data-theme="dark"] body.dashboard .header.admin-header {
            background: #0f172a !important;
        }
        html[data-theme="dark"] body.dashboard .employee-header-page-title { color: #f8fafc !important; }
        html[data-theme="dark"] .ad-chip-btn--ghost {
            background: #1e293b;
            color: #e2e8f0 !important;
            border-color: #334155;
        }
        html[data-theme="dark"] body.dashboard .ed-dashboard-search-slot input.pv-search-input {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        html[data-theme="dark"] body.dashboard .pv-filter-slot .pv-filter-btn,
        html[data-theme="dark"] body.dashboard .pv-filter-slot--tray .pv-filter-btn {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        @media (max-width: 1100px) {
            body.dashboard .header.admin-header .header-content {
                grid-template-columns: auto 1fr;
                grid-template-rows: auto auto;
                gap: 10px 12px;
            }
            body.dashboard .header.admin-header .employee-header-page-heading {
                grid-column: 1;
                grid-row: 1;
            }
            body.dashboard .admin-header-center-slot {
                grid-column: 1 / -1;
                grid-row: 2;
            }
            body.dashboard .header.admin-header .header-right.header-actions-tray {
                grid-column: 2;
                grid-row: 1;
                justify-self: end;
            }
        }
        @media (max-width: 991.98px) {
            body.dashboard .header.admin-header .header-content {
                grid-template-columns: auto auto 1fr !important;
                grid-template-rows: auto auto;
            }
            body.dashboard .header.admin-header .header-left {
                display: flex !important;
                grid-column: 1;
                grid-row: 1;
            }
            body.dashboard .header.admin-header .employee-header-menu-btn {
                display: inline-flex !important;
            }
            body.dashboard .header.admin-header .employee-header-page-heading {
                grid-column: 2;
                grid-row: 1;
            }
            body.dashboard .header.admin-header .header-right.header-actions-tray {
                grid-column: 3;
                grid-row: 1;
                justify-self: end;
            }
            body.dashboard .admin-header-center-slot {
                grid-column: 1 / -1;
                grid-row: 2;
            }
            body.dashboard .main-content.vouchers-react-root {
                padding: 0.5rem 0.85rem 5.5rem !important;
            }
            body.dashboard .ad-header-toolbar {
                flex-wrap: wrap;
                gap: 8px;
            }
            body.dashboard .ed-dashboard-search-slot {
                order: 1;
                flex: 1 1 100%;
                max-width: none;
                min-width: 0;
            }
            body.dashboard .ad-header-actions {
                order: 2;
                margin-left: 0;
                width: 100%;
                justify-content: stretch;
                gap: 8px;
            }
            body.dashboard .ad-header-actions .ad-chip-btn {
                flex: 1 1 auto;
                justify-content: center;
                height: 38px;
                font-size: 13px;
                padding: 0 10px;
            }
            body.dashboard .header.admin-header .header-right.header-actions-tray {
                gap: 8px !important;
            }
            body.dashboard .pv-filter-slot--tray .pv-filter-btn {
                width: 36px !important;
                height: 36px !important;
            }
            body.dashboard .ad-header-toolbar > .ad-chip-btn--ghost:first-child {
                display: none;
            }
            body.dashboard .header.admin-header .employee-header-page-title {
                font-size: 1.15rem !important;
            }
        }
        @media (max-width: 768px) {
            body.dashboard .header.admin-header .header-content {
                padding: 8px 12px !important;
                gap: 8px 10px;
            }
        }
        html[data-theme="dark"] body.dashboard .header.admin-header .employee-header-menu-btn,
        html[data-theme="dark"] body.dashboard .header.admin-header .employee-header-menu-btn .erp-hamburger {
            color: #f8fafc !important;
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

    <main class="main-content vouchers-react-root">
        <noscript>
            <div class="alert alert-warning">JavaScript is required to use the All Vouchers page.</div>
        </noscript>
        <div id="root"></div>
    </main>

    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
    <script>
    (function () {
        var header = document.querySelector('.header.admin-header');
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
