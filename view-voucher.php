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

/**
 * Redirect back to this voucher after a POST action without dropping module/return
 * query params (losing them creates a duplicate history entry and breaks Back).
 */
$vvRedirectToSelf = static function (array $extra = []) use ($voucher_id): void {
    $params = $_GET;
    $params['id'] = $voucher_id;
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    $qs = http_build_query($params);
    header('Location: view-voucher.php' . ($qs !== '' ? '?' . $qs : ''), true, 303);
    exit();
};

// Handle Mark Posted action (Finance finalization) before fetching details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_posted']) && (int) $_POST['mark_posted'] === 1) {
    $result = markVoucherPosted($voucher_id, $_SESSION['user_id']);
    if (!empty($result['ok'])) {
        $vvRedirectToSelf(['posted' => '1', 'post_error' => null]);
    }
    $vvRedirectToSelf([
        'posted' => null,
        'post_error' => isset($result['error']) ? $result['error'] : 'Unable to post voucher',
    ]);
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
        $vvRedirectToSelf();
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
            notifyUserVoucherStatus($voucher_id, $action, $comments !== '' ? $comments : null);
        } catch (Exception $eN) { /* ignore */ }

        $_SESSION['success_msg'] = 'Voucher has been ' . $action . ' successfully.';
        $vvRedirectToSelf();
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
    $vvRedirectToSelf();
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
    $back = (function_exists('company_url')
        ? company_url(isAdmin() ? 'admin/dashboard.php' : 'employee/dashboard.php')
        : app_url(isAdmin() ? 'admin/dashboard.php' : 'employee/dashboard.php')) . $vvModuleQs;
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
    'notifyUrl' => $assets['notifyUrl'] ?? (function_exists('viewVoucherUiPublicUrl')
        ? viewVoucherUiPublicUrl('api/whatsapp-notify.php')
        : app_url('/view-voucher-ui/api/whatsapp-notify.php')),
    'voucherId' => $voucher_id,
    'data' => $vvData,
    'flash' => $flash,
];

$vvBreadcrumbHome = $vvData['breadcrumbs']['home'];
$vvBreadcrumbAll = $vvData['breadcrumbs']['all'];
$employeeHeaderSubtitle = '<nav class="vv-breadcrumb" aria-label="Breadcrumb">'
    . '<a class="vv-breadcrumb-link erp-nav-back-ignore" href="' . htmlspecialchars($vvBreadcrumbHome) . '">Home</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<a class="vv-breadcrumb-link vv-breadcrumb-link--active erp-nav-back-ignore" href="' . htmlspecialchars($vvBreadcrumbAll) . '">Vouchers</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<span class="vv-breadcrumb-current">' . htmlspecialchars($vvData['voucher']['voucher_no'] ?? '') . '</span>'
    . '</nav>';

$statusClass = htmlspecialchars($vvData['status']['className'] ?? 'vv-status-pending');
$statusLabel = htmlspecialchars($vvData['status']['label'] ?? 'Pending');
$employeeHeaderRightHtml = '<div class="vv-header-status-actions no-print">'
    . '<span class="vv-status-badge ' . $statusClass . '">' . $statusLabel . '</span>'
    . '<div id="vv-actions-header-mount" class="vv-toolbar-desktop"></div>'
    . '</div>';

// Render via erp-laravel (ViewVoucherShell + React view-voucher-ui).
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'voucher';
}
$_SESSION['active_module'] = 'voucher';

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}
$backUrl = $slug !== ''
    ? company_url('select-module', $slug)
    : (function_exists('app_url') ? app_url('/select-module.php') : '/select-module.php');

$publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . (string) ($_SERVER['REQUEST_URI'] ?? '/view-voucher.php');
$publicUrl = strtok($publicUrl, '?') ?: $publicUrl;

$dbName = '';
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $dbName = '';
}

$GLOBALS['ERP_VOUCHER_CONTEXT'] = [
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'voucher_url' => $publicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
    'is_admin' => function_exists('isAdmin') && isAdmin(),
    'is_finance' => function_exists('isFinance') && isFinance(),
    'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'voucher',
    'page_title' => 'Payment Voucher - ' . (string) ($vvData['voucher']['voucher_no'] ?? ''),
    'header_title' => 'Voucher Preview',
    'header_subtitle' => $employeeHeaderSubtitle,
    'header_right_html' => $employeeHeaderRightHtml,
    'client_cfg' => $vvConfig,
];

$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_VOUCHER_CONTEXT'];
$GLOBALS['ERP_ROUTE'] = '/voucher/view';
$GLOBALS['ERP_VOUCHER_ROUTE'] = '/voucher/view';

$laravelRoot = __DIR__ . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';
$laravelEnv = $laravelRoot . '/.env';
$laravelEnvExample = $laravelRoot . '/.env.example';
if (!is_file($laravelEnv) && is_file($laravelEnvExample)) {
    @copy($laravelEnvExample, $laravelEnv);
}

if (!is_file($laravelAutoload)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>erp-laravel required</h1>';
    echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
    echo '</body></html>';
    exit;
}

require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
