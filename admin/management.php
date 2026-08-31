<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_helpers.php';
requireLogin();
if (isset($control_pdo)) {
    $pdo = $control_pdo;
}

$showAiSettings = (($_GET['module'] ?? '') === 'settings');

if ($showAiSettings) {
    if (!isAdmin()) {
        http_response_code(403);
        die('Admin access required.');
    }
} else {
    if (!isSuperAdmin()) {
        http_response_code(403);
        die('Only super admin can manage companies.');
    }

    if (!isset($_SESSION['management_unlocked']) || $_SESSION['management_unlocked'] !== true) {
        $secretHash = '$2y$10$0LDRbl5yTGabQK7ZzDmvKOCEEXZWiogWpMwMklz4R3xA2GTHXepca';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['management_secret'])) {
            if (password_verify($_POST['management_secret'], $secretHash)) {
                $_SESSION['management_unlocked'] = true;
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            } else {
                $secretError = "Invalid access code.";
            }
        }

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Restricted Access</title>
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600&display=swap" rel="stylesheet">
            <style>
                body { font-family: 'Outfit', sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                .auth-box { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); text-align: center; max-width: 400px; width: 100%; box-sizing: border-box; }
                h2 { margin-top: 0; color: #0f172a; }
                input[type="password"] { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 20px; font-size: 14px; box-sizing: border-box; outline: none; }
                input[type="password"]:focus { border-color: #5c59f0; }
                button { width: 100%; padding: 12px; background: #5c59f0; color: white; border: none; border-radius: 8px; margin-top: 16px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
                button:hover { background: #4b49d1; }
                .error { color: #ef4444; font-size: 13px; margin-top: 12px; font-weight: 500; }
            </style>
        </head>
        <body>
            <div class="auth-box">
                <h2>Access Restricted</h2>
                <p style="color: #64748b; font-size: 14px; margin-bottom: 0;">Please enter the management access code.</p>
                <form method="post">
                    <input type="password" name="management_secret" placeholder="Enter secret code" required autofocus>
                    <?php if (isset($secretError)): ?><div class="error"><?= htmlspecialchars($secretError) ?></div><?php endif; ?>
                    <button type="submit">Unlock</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

if ($showAiSettings) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'settings';
    ensureAiSchema(ai_pdo());
}

$msg = '';
$ctxCompanySlug = strtolower(trim((string) ($_GET['company_slug'] ?? '')));
$qsPreserve = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
$listUsersUrl = app_url('admin/list-company-users.php' . ($ctxCompanySlug !== '' ? '?company=' . rawurlencode($ctxCompanySlug) : ''));
$syncIndexUrl = app_url('admin/sync-user-company-index.php');
$aiManagementParams = array_merge($_GET ?: [], ['module' => 'settings']);
$aiManagementUrl = app_url('admin/management.php') . '?' . http_build_query($aiManagementParams);
$mgmtHomeParams = $_GET ?: [];
unset($mgmtHomeParams['module']);
$managementHomeUrl = app_url('admin/management.php') . ($mgmtHomeParams ? '?' . http_build_query($mgmtHomeParams) : '');
$settingsHubUrl = app_url('admin/settings.php' . $qsPreserve);
$modulesUrl = app_url('select-module.php' . $qsPreserve);
$logoutUrl = app_url('logout.php');
$arimaDef = function_exists('getSystemFontDefinition') ? getSystemFontDefinition('arima') : null;
$arimaFontStack = $arimaDef['stack'] ?? "'Arima', Arial, 'Helvetica Neue', Helvetica, sans-serif";
$arimaGoogleUrl = $arimaDef['google'] ?? 'https://fonts.googleapis.com/css2?family=Arima:wght@400;500;600;700&display=swap';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_company'])) {
    $companyName = trim((string) ($_POST['company_name'] ?? ''));
    $subdomain = trim((string) ($_POST['subdomain'] ?? ''));
    $timezone = trim((string) ($_POST['timezone'] ?? 'Africa/Dar_es_Salaam'));
    $baseCurrency = trim((string) ($_POST['base_currency'] ?? 'TZS'));
    $industryType = trim((string) ($_POST['industry_type'] ?? 'trading'));
    $dbName = trim((string) ($_POST['db_name'] ?? ''));
    
    if ($companyName === '') {
        $msg = 'Company name is required.';
    } else {
        try {
            $pdo->beginTransaction();
            
            $companySlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', ($subdomain !== '' ? $subdomain : $companyName))));
            
            $stmt = $pdo->prepare("INSERT INTO companies (company_name, subdomain, company_slug, status, timezone, base_currency, industry_type, db_name, created_at) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, NOW())");
            $stmt->execute([$companyName, ($subdomain !== '' ? $subdomain : null), $companySlug, $timezone, $baseCurrency, $industryType, ($dbName !== '' ? $dbName : null)]);
            $newCompanyId = (int) $pdo->lastInsertId();

            $defaultModules = [
                ['payment_voucher', 'Payment Voucher'],
                ['sales', 'Sales'],
                ['stock', 'Stock'],
                ['finance', 'Finance'],
                ['accounting', 'Accounting'],
                ['payroll', 'Payroll'],
                ['attendance', 'Attendance'],
                ['revenue', 'Revenue'],
                ['logistics', 'Logistics'],
            ];
            $insModule = $pdo->prepare("INSERT INTO company_modules (company_id, module_key, module_name, enabled) VALUES (?, ?, ?, 1)");
            foreach ($defaultModules as $m) {
                $insModule->execute([$newCompanyId, $m[0], $m[1]]);
            }

            $balancesFunctions = dirname(__DIR__) . '/modules/balances/functions.php';
            if (is_file($balancesFunctions)) {
                require_once $balancesFunctions;
                if (function_exists('balances_ensure_default_accounts_for_company')) {
                    balances_ensure_default_accounts_for_company($pdo, $newCompanyId);
                }
            }
            
            $pdo->commit();
            $msg = 'Company "' . $companyName . '" created successfully.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Create failed: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_company'])) {
    $delId = (int) ($_POST['company_id'] ?? 0);
    $slugConfirm = trim((string) ($_POST['confirm_slug'] ?? ''));

    if ($delId <= 0) {
        $msg = 'Invalid company selected.';
    } elseif ($slugConfirm === '') {
        $msg = 'Enter the company slug exactly to confirm deletion.';
    } else {
        try {
            $rowStmt = $pdo->prepare('SELECT id, company_slug, company_name FROM companies WHERE id = ? LIMIT 1');
            $rowStmt->execute([$delId]);
            $delRow = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$delRow) {
                $msg = 'Company not found.';
            } elseif (strcasecmp($slugConfirm, (string) ($delRow['company_slug'] ?? '')) !== 0) {
                $msg = 'Slug does not match this company. Check the slug under Subdomain / URL and try again.';
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE users SET company_id = NULL WHERE company_id = ?')->execute([$delId]);
                    $pdo->prepare('DELETE FROM companies WHERE id = ? LIMIT 1')->execute([$delId]);
                    $pdo->commit();
                    $sessCid = (int) ($_SESSION['company_id'] ?? 0);
                    if ($sessCid === $delId) {
                        unset($_SESSION['company_id']);
                    }
                    $msg = 'Company "' . htmlspecialchars((string) $delRow['company_name']) . '" was permanently removed.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            $msg = 'Delete failed: ' . $e->getMessage();
        }
    }
}

// Fetch Stats
$totalCompanies = 0;
$activeCount = 0;
$totalStaff = 0;
$modulesEnabledCount = 9; // System default for new companies

try {
    $totalCompanies = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE status = 'active'")->fetchColumn();
    $totalStaff = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    
    $companies = $pdo->query("
        SELECT c.*, 
        (SELECT COUNT(*) FROM users WHERE company_id = c.id) as user_count 
        FROM companies c 
        ORDER BY c.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $companies = [];
}

$activeCid = (int) (currentCompanyId() ?? 0);

$aiSettings = $showAiSettings ? ai_settings_for_api() : null;
$aiEncryptionOk = defined('AI_ENCRYPTION_KEY') && AI_ENCRYPTION_KEY !== '';
$aiApiUrl = function_exists('app_url') ? app_url('/api/ai/settings.php') : '../api/ai/settings.php';
$aiCsrf = function_exists('csrf_token') ? csrf_token() : '';

$msgTone = 'success';
if ($msg !== '' && preg_match('/failed|Invalid|not found|does not match|required\\.|Slug does not|Delete failed|Create failed|Enter the company slug/i', $msg)) {
    $msgTone = 'danger';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $showAiSettings ? 'AI Integration | Platform Control' : 'Platform Control Center' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php if (!$showAiSettings): ?>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php else: ?>
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=arima:400,500,600,700">
    <link href="<?= htmlspecialchars($arimaGoogleUrl) ?>" rel="stylesheet">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #5c59f0;
            --primary-hover: #4b49d1;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg-page: #f8fafc;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * { box-sizing: border-box; }
        body:not(.ai-settings-view) {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            margin: 0;
            min-height: 100vh;
        }
        body.ai-settings-view {
            background-color: var(--bg-page);
            color: var(--text-main);
            margin: 0;
            min-height: 100vh;
        }

        .platform-shell {
            display: flex;
            min-height: 100vh;
        }

        .platform-sidebar {
            width: 240px;
            flex-shrink: 0;
            background: #ffffff;
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 1000;
        }

        .platform-sidebar-brand {
            padding: 20px 18px 16px;
            border-bottom: 1px solid var(--border);
        }

        .platform-sidebar-brand strong {
            display: block;
            font-size: 15px;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .platform-sidebar-brand span {
            font-size: 11px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .platform-nav {
            flex: 1;
            padding: 12px 10px;
            overflow-y: auto;
        }

        .platform-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            margin-bottom: 4px;
            border-radius: 10px;
            color: #475569;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.15s, color 0.15s;
        }

        .platform-nav a i {
            width: 18px;
            text-align: center;
            font-size: 15px;
            opacity: 0.85;
        }

        .platform-nav a:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .platform-nav a.active {
            background: #ede9fe;
            color: #5c59f0;
            font-weight: 600;
        }

        .platform-nav a.active-ai {
            background: #d1fae5;
            color: #059669;
            font-weight: 600;
        }

        .platform-nav a.nav-logout {
            color: #ef4444;
        }

        .platform-nav a.nav-logout:hover {
            background: #fef2f2;
            color: #dc2626;
        }

        .platform-sidebar-foot {
            padding: 12px 10px 16px;
            border-top: 1px solid var(--border);
        }

        .platform-sidebar-foot a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            color: #ef4444;
            transition: background 0.15s, color 0.15s;
        }

        .platform-sidebar-foot a:hover {
            background: #fef2f2;
            color: #dc2626;
        }

        .platform-sidebar-foot a i {
            width: 18px;
            text-align: center;
        }

        .platform-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .platform-topbar {
            position: sticky;
            top: 0;
            z-index: 900;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 28px;
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }

        .platform-topbar-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }

        .platform-topbar-badge {
            font-size: 12px;
            color: var(--text-muted);
            padding: 4px 10px;
            background: #f1f5f9;
            border-radius: 999px;
        }

        .main-content {
            flex: 1;
            max-width: 1280px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 28px 56px;
            overflow-y: visible;
        }

        @media (max-width: 900px) {
            .platform-shell { flex-direction: column; }
            .platform-sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .platform-nav {
                display: flex;
                flex-wrap: wrap;
                gap: 4px;
                padding: 8px;
            }
            .platform-nav a {
                margin-bottom: 0;
                font-size: 13px;
                padding: 8px 10px;
            }
            .platform-sidebar-foot { display: none; }
            .platform-nav a.nav-logout-mobile { display: flex; }
        }

        @media (min-width: 901px) {
            .platform-nav a.nav-logout-mobile { display: none; }
        }

        .page-header {
            margin-bottom: 32px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px 0;
            color: #0f172a;
        }

        .page-header p {
            color: var(--text-muted);
            font-size: 15px;
            margin: 0;
        }

        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
            position: relative;
        }

        .stat-main { display: flex; align-items: center; gap: 16px; }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .icon-blue { background: #e0e7ff; color: #5c59f0; }
        .icon-green { background: #dcfce7; color: #10b981; }
        .icon-sky { background: #e0f2fe; color: #0ea5e9; }
        .icon-orange { background: #ffedd5; color: #f97316; }

        .stat-content h3 {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .stat-content .value {
            font-size: 24px;
            font-weight: 700;
            margin-top: 2px;
            color: #0f172a;
        }

        .stat-footer {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .chevron-right {
            color: #cbd5e1;
            font-size: 14px;
        }

        /* Registration Form Section */
        .section-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 32px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }

        .section-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
        }

        .section-icon-box {
            width: 44px;
            height: 44px;
            background: #5c59f0;
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .section-title h2 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            color: #0f172a;
        }

        .section-title p {
            font-size: 13px;
            color: var(--text-muted);
            margin: 4px 0 0 0;
        }

        .horizontal-form {
            display: grid;
            grid-template-columns: repeat(5, 1fr) auto;
            gap: 16px;
            align-items: flex-end;
        }

        .form-field {
            display: flex;
            flex-column;
            gap: 8px;
        }

        .form-field label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .form-input {
            width: 100%;
            padding: 11px 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fcfcfd;
            font-family: inherit;
            font-size: 14px;
            color: #334155;
            transition: all 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(92, 89, 240, 0.08);
        }

        .form-field small {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }

        .btn-create {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            height: 44px;
        }

        .btn-create:hover { background: var(--primary-hover); transform: translateY(-1px); }

        /* Table Section */
        .table-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-title h2 { font-size: 18px; font-weight: 700; margin: 0; color: #0f172a; }
        .table-title p { font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0; }

        .table-filters { display: flex; gap: 12px; }
        .search-box {
            position: relative;
            width: 260px;
        }
        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }
        .search-input {
            width: 100%;
            padding: 10px 16px 10px 40px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            font-size: 13px;
        }
        .status-filter {
            padding: 10px 16px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            font-size: 13px;
            color: #334155;
            cursor: pointer;
        }

        .table-wrap {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }

        table { width: 100%; border-collapse: collapse; }
        th {
            background: #fcfcfd;
            padding: 14px 24px;
            font-size: 11px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 16px 24px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .company-cell { display: flex; align-items: center; gap: 16px; }
        .company-icon {
            width: 48px;
            height: 48px;
            background: #f1f5f9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--primary);
            font-size: 18px;
            flex-shrink: 0;
        }

        .company-meta { display: flex; flex-direction: column; }
        .company-name { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 2px; }
        .company-reg { font-size: 12px; color: var(--text-muted); }

        .currency-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #475569;
            font-size: 10px;
            font-weight: 700;
            margin-top: 4px;
            width: fit-content;
        }
        
        .active-badge {
            background: #eff6ff;
            color: #3b82f6;
            border: 1px solid #dbeafe;
        }

        .subdomain-link {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #5c59f0;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
        .subdomain-url { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

        .staff-info { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #334155; }
        .staff-info i { color: #94a3b8; }
        .staff-count { font-weight: 700; }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #ecfdf5;
            color: #10b981;
        }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        .btn-switch {
            background: #5c59f0;
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-switch:hover { background: #4b49d1; }
        
        .btn-gear {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid var(--border);
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: 8px;
            transition: all 0.2s;
        }
        .btn-gear:hover { background: #f1f5f9; color: var(--primary); }

        /* Pagination */
        .table-footer {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            border-top: 1px solid var(--border);
        }
        .pagination-info { font-size: 13px; color: var(--text-muted); }
        .pagination-nav { display: flex; gap: 8px; }
        .page-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            color: #64748b;
            text-decoration: none;
            background: #fff;
        }
        .page-btn.active { background: #5c59f0; color: #fff; border-color: #5c59f0; }
        
        .btn-danger-tenant {
            background: var(--danger);
            color: #fff;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            width: 100%;
            font-family: inherit;
        }
        .btn-danger-tenant:hover { filter: brightness(1.05); }
        .danger-delete-form input.form-input {
            margin-bottom: 8px;
            font-size: 12px;
            padding: 8px 10px;
        }
        .danger-delete-hint {
            font-size: 10px;
            color: var(--text-muted);
            margin: 0 0 6px 0;
            line-height: 1.3;
        }

        @media (max-width: 1200px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .horizontal-form { grid-template-columns: repeat(2, 1fr); }
        }

        /* AI Integration — matches sales/customers/add.php editor layout */
        body.ai-settings-view {
            --ai-font: <?= $arimaFontStack ?>;
            background: #f8fafc;
            color: #1e293b;
        }
        html body.ai-settings-view,
        html body.ai-settings-view *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not([class^="bi-"]):not([class*=" bi-"]):not(svg):not(path):not(circle):not(rect):not(line):not(polyline):not(polygon) {
            font-family: var(--ai-font) !important;
        }
        html body.ai-settings-view input,
        html body.ai-settings-view select,
        html body.ai-settings-view textarea,
        html body.ai-settings-view button,
        html body.ai-settings-view label,
        html body.ai-settings-view .platform-sidebar,
        html body.ai-settings-view .platform-main,
        html body.ai-settings-view .ai-editor-shell,
        html body.ai-settings-view .ai-form-input,
        html body.ai-settings-view .ai-form-input-mono {
            font-family: var(--ai-font) !important;
        }
        html body.ai-settings-view .ai-form-input-mono {
            font-family: ui-monospace, monospace !important;
        }
        html body.ai-settings-view .fa,
        html body.ai-settings-view .fas,
        html body.ai-settings-view .far,
        html body.ai-settings-view .fab,
        html body.ai-settings-view .fal,
        html body.ai-settings-view .fad,
        html body.ai-settings-view i[class*="fa-"] {
            font-family: "Font Awesome 6 Free", "Font Awesome 5 Free", FontAwesome !important;
            font-style: normal !important;
        }
        html body.ai-settings-view .fab,
        html body.ai-settings-view .fa-brands,
        html body.ai-settings-view i.fab {
            font-family: "Font Awesome 6 Brands", "Font Awesome 5 Brands" !important;
        }
        html body.ai-settings-view .bi,
        html body.ai-settings-view [class^="bi-"],
        html body.ai-settings-view [class*=" bi-"] {
            font-family: bootstrap-icons !important;
        }
        body.ai-settings-view .main-content.ai-main-content {
            max-width: none;
            padding: 2rem;
        }
        .ai-editor-shell {
            max-width: 1140px;
            margin: 0 auto;
        }
        .ai-editor-shell .editor-topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .ai-editor-shell .editor-eyebrow {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            margin: 0 0 6px;
        }
        .ai-editor-shell .editor-topbar h1 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }
        .ai-editor-shell .editor-lead {
            font-size: 13px;
            color: #64748b;
            margin: 6px 0 0;
            max-width: 560px;
            line-height: 1.5;
        }
        .ai-editor-shell .editor-back {
            color: #94a3b8;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            margin-top: 4px;
        }
        .ai-editor-shell .editor-back:hover { color: #475569; }
        .ai-editor-shell .editor-layout {
            display: grid;
            grid-template-columns: 180px minmax(0, 1fr);
            gap: 2rem;
            align-items: start;
        }
        .ai-editor-shell .section-nav {
            position: sticky;
            top: 24px;
            align-self: start;
        }
        .ai-editor-shell .section-nav ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .ai-editor-shell .section-nav li + li { margin-top: 0.5rem; }
        .ai-editor-shell .section-nav a {
            display: block;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .ai-editor-shell .section-nav a:hover {
            background: #eff6ff;
            color: #2563eb;
        }
        .ai-editor-shell .section-nav a.is-active {
            background: #f3e8ff;
            color: #7c3aed;
            font-weight: 600;
        }
        .ai-editor-shell .editor-main { min-width: 0; }
        .ai-editor-shell .editor-section {
            padding-bottom: 2rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .ai-editor-shell .editor-section:last-of-type {
            margin-bottom: 1.5rem;
            border-bottom: none;
        }
        .ai-editor-shell .ai-section-header { margin-bottom: 1.25rem; }
        .ai-editor-shell .ai-section-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px;
        }
        .ai-editor-shell .ai-section-subtitle {
            font-size: 12px;
            color: #94a3b8;
            margin: 0;
            line-height: 1.5;
        }
        .ai-editor-shell .ai-form-row {
            display: grid;
            grid-template-columns: 210px 1fr;
            align-items: start;
            margin-bottom: 24px;
        }
        .ai-editor-shell .ai-form-row:last-child { margin-bottom: 0; }
        .ai-editor-shell .ai-form-label {
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            padding-top: 12px;
        }
        .ai-editor-shell .ai-form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: #1e293b;
            outline: none;
            transition: all 0.2s;
            background: #fff;
            font-family: inherit;
        }
        .ai-editor-shell .ai-form-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        .ai-editor-shell .ai-form-input-mono {
            font-family: ui-monospace, monospace;
            background: #f8fafc;
            color: #2563eb;
            border-style: dashed;
            font-weight: 600;
        }
        .ai-editor-shell .ai-help-text {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
            line-height: 1.5;
        }
        .ai-editor-shell .ai-alert {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13px;
            margin-bottom: 1.25rem;
            line-height: 1.5;
        }
        .ai-editor-shell .ai-alert-warn {
            border: 1px solid #fcd34d;
            background: #fffbeb;
            color: #92400e;
        }
        .ai-editor-shell .ai-alert-warn code {
            font-family: ui-monospace, monospace;
            font-size: 12px;
        }
        .ai-editor-shell .ai-toggle-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-top: 10px;
            font-size: 14px;
            color: #1e293b;
            cursor: pointer;
        }
        .ai-editor-shell .ai-toggle-row input {
            width: 16px;
            height: 16px;
            accent-color: #7c3aed;
        }
        .ai-editor-shell .ai-select {
            appearance: none;
            padding-right: 2.5rem;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E");
            background-size: 1.25rem;
            background-repeat: no-repeat;
            background-position: right 12px center;
        }
        .ai-editor-shell .ai-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 5rem;
        }
        .ai-editor-shell .btn-ai-save {
            background: #7c3aed;
            color: #fff;
            padding: 14px 48px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            font-family: inherit;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
            transition: all 0.2s;
        }
        .ai-editor-shell .btn-ai-save:hover { background: #6d28d9; }
        .ai-editor-shell .btn-ai-test {
            border: 1px solid #d8b4fe;
            color: #7c3aed;
            background: #faf5ff;
            padding: 14px 32px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }
        .ai-editor-shell .btn-ai-test:hover {
            background: #f3e8ff;
            color: #6d28d9;
        }
        .ai-editor-shell .ai-msg {
            font-size: 13px;
            margin-top: 12px;
            font-weight: 500;
        }
        .ai-editor-shell .ai-msg.ok { color: #16a34a; }
        .ai-editor-shell .ai-msg.err { color: #dc2626; }
        .ai-editor-shell .ai-usage-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .ai-editor-shell .ai-usage-table th {
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #94a3b8;
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .ai-editor-shell .ai-usage-table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .ai-editor-shell .ai-usage-table tbody tr:last-child td { border-bottom: none; }
        .ai-editor-shell .ai-usage-empty {
            color: #94a3b8;
            font-size: 13px;
        }
        @media (max-width: 992px) {
            body.ai-settings-view .main-content.ai-main-content { padding: 1rem; }
            .ai-editor-shell .editor-topbar {
                flex-direction: column;
                align-items: flex-start;
            }
            .ai-editor-shell .editor-layout {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .ai-editor-shell .section-nav { position: static; }
            .ai-editor-shell .section-nav ul {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            .ai-editor-shell .section-nav li + li { margin-top: 0; }
            .ai-editor-shell .ai-form-row {
                grid-template-columns: 1fr;
                gap: 8px;
                margin-bottom: 20px;
            }
            .ai-editor-shell .ai-form-label { padding-top: 0; font-size: 13px; }
            .ai-editor-shell .btn-ai-save,
            .ai-editor-shell .btn-ai-test { width: 100%; }
        }
    </style>
</head>
<body<?= $showAiSettings ? ' class="ai-settings-view"' : '' ?>>

<div class="platform-shell">
    <aside class="platform-sidebar">
        <div class="platform-sidebar-brand">
            <strong>Boss console</strong>
            <span>Platform settings &amp; tenant control</span>
        </div>
        <nav class="platform-nav" aria-label="Platform navigation">
            <a href="<?= htmlspecialchars($settingsHubUrl) ?>">
                <i class="bi bi-sliders"></i> Settings hub
            </a>
            <a href="<?= htmlspecialchars($aiManagementUrl) ?>" class="<?= $showAiSettings ? 'active-ai' : '' ?>">
                <i class="bi bi-robot"></i> AI Integration
            </a>
            <a href="<?= htmlspecialchars($listUsersUrl) ?>">
                <i class="bi bi-people"></i> Company users
            </a>
            <a href="<?= htmlspecialchars($syncIndexUrl) ?>">
                <i class="bi bi-arrow-repeat"></i> Sync login index
            </a>
            <a href="<?= htmlspecialchars($modulesUrl) ?>">
                <i class="bi bi-grid"></i> Modules
            </a>
            <a href="<?= htmlspecialchars($logoutUrl) ?>" class="nav-logout nav-logout-mobile">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
        <div class="platform-sidebar-foot">
            <a href="<?= htmlspecialchars($logoutUrl) ?>">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </aside>

    <div class="platform-main">
        <?php if (!$showAiSettings): ?>
        <header class="platform-topbar">
            <span class="platform-topbar-title">Platform Control Center</span>
            <span class="platform-topbar-badge">Super Admin</span>
        </header>
        <?php endif; ?>

<main class="main-content<?= $showAiSettings ? ' ai-main-content' : '' ?>">
    <?php if ($showAiSettings): ?>
    <div class="ai-editor-shell">
        <div class="editor-topbar">
            <div>
                <p class="editor-eyebrow">Super Admin</p>
                <h1>AI Integration</h1>
                <p class="editor-lead">System-wide OpenAI configuration for all companies. Keys are encrypted and never shown in full.</p>
            </div>
            <a href="<?= htmlspecialchars($settingsHubUrl) ?>" class="editor-back">
                <i class="fas fa-arrow-left"></i> Settings hub
            </a>
        </div>

        <form id="aiSettingsForm">
            <div class="editor-layout">
                <aside class="section-nav" aria-label="AI settings sections">
                    <ul>
                        <li><a href="#ai-api-settings" class="is-active js-ai-nav">API settings</a></li>
                        <li><a href="#ai-usage-report" class="js-ai-nav">Usage report</a></li>
                    </ul>
                </aside>

                <div class="editor-main">
                    <section class="editor-section" id="ai-api-settings">
                        <div class="ai-section-header">
                            <h2 class="ai-section-title">OpenAI API settings</h2>
                            <p class="ai-section-subtitle">One key for the entire ERP. Usage is tracked per company with daily limits.</p>
                        </div>

                        <?php if (!$aiEncryptionOk): ?>
                        <div class="ai-alert ai-alert-warn">
                            Set <code>AI_ENCRYPTION_KEY</code> in <code>env.php</code> before saving an API key.
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($aiSettings['configured']) && !empty($aiSettings['api_key_masked'])): ?>
                        <div class="ai-form-row">
                            <span class="ai-form-label">Current key</span>
                            <div>
                                <input type="text" readonly class="ai-form-input ai-form-input-mono" value="<?= htmlspecialchars($aiSettings['api_key_masked']) ?>">
                                <p class="ai-help-text">Masked for security. Enter a new key below only if you want to replace it.</p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="ai-form-row">
                            <label class="ai-form-label" for="ai_api_key">New API key</label>
                            <div>
                                <input type="password" class="ai-form-input" id="ai_api_key" name="api_key" autocomplete="off"
                                       placeholder="<?= !empty($aiSettings['configured']) ? 'Leave blank to keep existing key' : 'sk-...' ?>">
                                <p class="ai-help-text">Stored encrypted. Never displayed in full after saving.</p>
                            </div>
                        </div>

                        <div class="ai-form-row">
                            <label class="ai-form-label" for="ai_model_name">Model</label>
                            <div>
                                <select class="ai-form-input ai-select" id="ai_model_name" name="model_name">
                                    <?php foreach (['gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo'] as $m): ?>
                                        <option value="<?= htmlspecialchars($m) ?>"<?= ($aiSettings['model_name'] ?? '') === $m ? ' selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="ai-form-row">
                            <label class="ai-form-label" for="ai_daily_limit">Daily limit per company</label>
                            <div>
                                <input type="number" class="ai-form-input" id="ai_daily_limit" name="daily_limit" min="1" max="10000"
                                       value="<?= (int) ($aiSettings['daily_limit'] ?? 500) ?>">
                                <p class="ai-help-text">Maximum AI requests per company per day.</p>
                            </div>
                        </div>

                        <div class="ai-form-row">
                            <span class="ai-form-label">Availability</span>
                            <div>
                                <label class="ai-toggle-row" for="ai_is_enabled">
                                    <input type="checkbox" id="ai_is_enabled" name="is_enabled" value="1"<?= !empty($aiSettings['is_enabled']) ? ' checked' : '' ?>>
                                    Enable AI assistant for all companies
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="ai-usage-report">
                        <div class="ai-section-header">
                            <h2 class="ai-section-title">Usage by company</h2>
                            <p class="ai-section-subtitle">Request volume, tokens, and estimated cost over the last 30 days.</p>
                        </div>
                        <table class="ai-usage-table">
                            <thead>
                                <tr>
                                    <th>Company ID</th>
                                    <th>Requests</th>
                                    <th>Tokens</th>
                                    <th>Est. cost ($)</th>
                                </tr>
                            </thead>
                            <tbody id="aiUsageBody">
                                <tr><td colspan="4" class="ai-usage-empty">Loading…</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <div class="ai-actions">
                        <button type="submit" class="btn-ai-save">Save settings</button>
                        <button type="button" class="btn-ai-test" id="aiBtnTest">Test connection</button>
                    </div>
                    <div id="aiFormMsg" class="ai-msg"></div>
                </div>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="page-header">
        <h1>Platform Control Center</h1>
        <p>Manage multi-tenant company instances and global configurations.</p>
    </div>
    <?php endif; ?>

    <?php if (!$showAiSettings): ?>
    <div class="section-card" style="margin-bottom: 24px; border-left: 4px solid #6366f1;">
        <div class="section-header" style="margin-bottom: 12px;">
            <div class="section-icon-box" style="background: #6366f1;"><i class="bi bi-envelope-at"></i></div>
            <div class="section-title">
                <h2>Login index &amp; users</h2>
                <p>View every company user email (from <code>user_company_index</code>) or rebuild the index after imports.</p>
            </div>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 12px;">
            <a href="<?= htmlspecialchars($listUsersUrl) ?>" class="btn-gear" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-list-ul"></i> Company users &amp; emails
            </a>
            <a href="<?= htmlspecialchars($syncIndexUrl) ?>" class="btn-gear" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px; background: #0f766e; border-color: #0f766e; color: #fff;">
                <i class="bi bi-arrow-repeat"></i> Sync user company index
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($msg !== ''): ?>
        <?php
        $alertBg = $msgTone === 'danger' ? '#fef2f2' : '#ecfdf5';
        $alertBorder = $msgTone === 'danger' ? '#fecaca' : '#d1fae5';
        $alertColor = $msgTone === 'danger' ? '#b91c1c' : '#10b981';
        $alertIcon = $msgTone === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill';
        ?>
        <div style="background: <?= $alertBg ?>; color: <?= $alertColor ?>; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; display: flex; align-items: center; gap: 10px; border: 1px solid <?= $alertBorder ?>;">
            <i class="bi <?= htmlspecialchars($alertIcon) ?>"></i> <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <?php if (!$showAiSettings): ?>
    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-main">
                <div class="stat-icon icon-blue"><i class="bi bi-building"></i></div>
                <div class="stat-content">
                    <h3>Total Companies</h3>
                    <div class="value"><?= $totalCompanies ?></div>
                    <div class="stat-footer">All registered companies</div>
                </div>
            </div>
            <i class="bi bi-chevron-right chevron-right"></i>
        </div>
        <div class="stat-card">
            <div class="stat-main">
                <div class="stat-icon icon-green"><i class="bi bi-check-circle"></i></div>
                <div class="stat-content">
                    <h3>Active Tenants</h3>
                    <div class="value"><?= $activeCount ?></div>
                    <div class="stat-footer">Currently active companies</div>
                </div>
            </div>
            <i class="bi bi-chevron-right chevron-right"></i>
        </div>
        <div class="stat-card">
            <div class="stat-main">
                <div class="stat-icon icon-sky"><i class="bi bi-people"></i></div>
                <div class="stat-content">
                    <h3>Total Staff</h3>
                    <div class="value"><?= $totalStaff ?></div>
                    <div class="stat-footer">Across all companies</div>
                </div>
            </div>
            <i class="bi bi-chevron-right chevron-right"></i>
        </div>
        <div class="stat-card">
            <div class="stat-main">
                <div class="stat-icon icon-orange"><i class="bi bi-box"></i></div>
                <div class="stat-content">
                    <h3>Modules Enabled</h3>
                    <div class="value"><?= $modulesEnabledCount ?></div>
                    <div class="stat-footer">System modules</div>
                </div>
            </div>
            <i class="bi bi-chevron-right chevron-right"></i>
        </div>
    </div>

    <!-- Register Company Form -->
    <div class="section-card">
        <div class="section-header">
            <div class="section-icon-box" style="background: #5c59f0;"><i class="bi bi-plus-lg"></i></div>
            <div class="section-title">
                <h2>Register New Company</h2>
                <p>Create a new company instance and configure its basic information.</p>
            </div>
        </div>
        <form method="post" class="horizontal-form" style="grid-template-columns: repeat(6, 1fr) auto;">
            <input type="hidden" name="create_company" value="1">
            <div class="form-field">
                <label>Company Name</label>
                <input name="company_name" class="form-input" placeholder="e.g. Acme Corp" required>
            </div>
            <div class="form-field">
                <label>Subdomain</label>
                <input name="subdomain" class="form-input" placeholder="e.g. acme">
            </div>
            <div class="form-field">
                <label>Database</label>
                <input name="db_name" class="form-input" placeholder="e.g. acme_db">
            </div>
            <div class="form-field">
                <label>Currency</label>
                <select name="base_currency" class="form-input">
                    <option value="TZS">TZS</option>
                    <option value="USD">USD</option>
                </select>
            </div>
            <div class="form-field">
                <label>Timezone</label>
                <select name="timezone" class="form-input">
                    <option value="Africa/Dar_es_Salaam">Tanzania</option>
                    <option value="UTC">UTC</option>
                </select>
            </div>
            <div class="form-field">
                <label>Industry</label>
                <select name="industry_type" class="form-input">
                    <option value="trading">Trading</option>
                    <option value="logistics">Logistics</option>
                </select>
            </div>
            <button type="submit" class="btn-create">
                <i class="bi bi-plus-lg"></i> Create Instance
            </button>
        </form>
    </div>

    <!-- Company Table -->
    <div class="table-section-header">
        <div class="table-title">
            <h2>Registered Companies</h2>
            <p>Manage and monitor all company instances.</p>
        </div>
        <div class="table-filters">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" class="search-input" placeholder="Search company...">
            </div>
            <select class="status-filter">
                <option>All Status</option>
                <option>Active</option>
                <option>Inactive</option>
            </select>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Subdomain / URL</th>
                    <th>Database</th>
                    <th>Access Link</th>
                    <th>Staff</th>
                    <th>Status</th>
                    <th>Actions</th>
                    <th style="min-width: 200px;">Remove tenant</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($companies as $c): ?>
                <tr>
                    <td>
                        <div class="company-cell">
                            <div class="company-icon"><?= strtoupper(substr($c['company_name'], 0, 1)) ?></div>
                            <div class="company-meta">
                                <div class="company-name"><?= htmlspecialchars((string) $c['company_name']) ?></div>
                                <div class="company-reg">Registered on <?= date('d M Y', strtotime($c['created_at'])) ?></div>
                                <div class="currency-badge <?= $activeCid === (int) $c['id'] ? 'active-badge' : '' ?>">
                                    <?= htmlspecialchars((string) ($c['base_currency'] ?? 'TZS')) ?>
                                    <?php if ($activeCid === (int) $c['id']): ?> • Active Context<?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <a href="#" class="subdomain-link">
                            <i class="fas fa-globe"></i>
                            <?= htmlspecialchars((string) ($c['subdomain'] ?? '')) ?>
                        </a>
                        <div class="subdomain-url">ommyerp.com/<?= htmlspecialchars((string) ($c['company_slug'] ?? '')) ?></div>
                    </td>
                    <td>
                        <div style="font-weight: 700; font-size: 13px; color: var(--primary);"><?= htmlspecialchars((string) ($c['db_name'] ?? 'System Default')) ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);">Dedicated DB Instance</div>
                    </td>
                    <td>
                        <?php 
                            $slug = (string) ($c['company_slug'] ?? '');
                            $accessUrl = company_url('select-module', $slug);
                            $fullUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . $accessUrl;
                        ?>
                        <div class="d-flex align-items-center gap-2">
                            <a href="<?= htmlspecialchars($accessUrl) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 11px;">
                                <i class="bi bi-box-arrow-up-right"></i> Open
                            </a>
                            <button type="button" class="btn btn-sm btn-light py-0 px-2 js-copy-url" data-url="<?= htmlspecialchars($fullUrl) ?>" style="font-size: 11px;">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                        <div style="font-size: 10px; color: var(--text-muted); margin-top: 4px;">Direct Workspace Access</div>
                    </td>
                    <td>
                        <div class="staff-info">
                            <i class="fas fa-users"></i>
                            <span class="staff-count"><?= (int) ($c['user_count'] ?? 0) ?></span> Members
                        </div>
                    </td>
                    <td>
                        <div class="status-pill">
                            <div class="status-dot"></div>
                            <?= ucfirst(htmlspecialchars((string) ($c['status'] ?? 'active'))) ?>
                        </div>
                    </td>
                    <td>
                        <a href="switch-company.php?company_id=<?= (int) $c['id'] ?>" class="btn-switch">
                            Switch <i class="bi bi-arrow-right-short fs-5"></i>
                        </a>
                        <a href="company-settings.php?company_id=<?= (int) $c['id'] ?>" class="btn-gear">
                            <i class="bi bi-gear"></i>
                        </a>
                    </td>
                    <td>
                        <p class="danger-delete-hint">Type the company slug exactly, then remove. Detaches users; deletes modules &amp; company settings for this tenant.</p>
                        <form method="post" class="danger-delete-form" onsubmit="return confirm('Permanently delete this company tenant? Users will lose company association. This cannot be undone.');">
                            <input type="hidden" name="delete_company" value="1">
                            <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">
                            <input type="text" name="confirm_slug" class="form-input" placeholder="<?= htmlspecialchars((string) ($c['company_slug'] ?? 'slug')) ?>" value="" autocomplete="off" aria-label="Confirm company slug">
                            <button type="submit" class="btn-danger-tenant"><i class="bi bi-trash"></i> Remove company</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="table-footer">
            <div class="pagination-info">Showing 1 to <?= count($companies) ?> of <?= $totalCompanies ?> companies</div>
            <div class="pagination-nav">
                <a href="#" class="page-btn"><i class="fas fa-chevron-left"></i></a>
                <a href="#" class="page-btn active">1</a>
                <a href="#" class="page-btn"><i class="fas fa-chevron-right"></i></a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>
    </div><!-- /.platform-main -->
</div><!-- /.platform-shell -->

<script>
<?php if ($showAiSettings): ?>
(function () {
    const API = <?= json_encode($aiApiUrl) ?>;
    const CSRF = <?= json_encode($aiCsrf) ?>;

    async function aiApi(action, body) {
        const opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: {} };
        if (body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({ ...body, action, csrf_token: CSRF });
        }
        const url = body ? API : API + '?action=' + encodeURIComponent(action);
        return (await fetch(url, opts)).json();
    }

    function aiMsg(text, ok) {
        const el = document.getElementById('aiFormMsg');
        if (!el) return;
        el.textContent = text;
        el.className = 'ai-msg ' + (ok ? 'ok' : 'err');
    }

    document.getElementById('aiSettingsForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        const res = await aiApi('save', {
            api_key: document.getElementById('ai_api_key').value,
            model_name: document.getElementById('ai_model_name').value,
            daily_limit: document.getElementById('ai_daily_limit').value,
            is_enabled: document.getElementById('ai_is_enabled').checked,
        });
        if (res.success) {
            aiMsg(res.message || 'Settings saved', true);
            document.getElementById('ai_api_key').value = '';
            loadAiUsage();
        } else {
            aiMsg(res.error || 'Save failed', false);
        }
    });

    document.getElementById('aiBtnTest')?.addEventListener('click', async () => {
        aiMsg('Testing connection…', true);
        const res = await aiApi('test', { action: 'test' });
        aiMsg(res.success ? ('Connection OK: ' + (res.message || '')) : (res.error || res.message || 'Failed'), res.success);
    });

    async function loadAiUsage() {
        const res = await aiApi('get');
        const tbody = document.getElementById('aiUsageBody');
        if (!tbody) return;
        if (!res.success || !res.usage_report?.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="ai-usage-empty">No usage recorded yet.</td></tr>';
            return;
        }
        tbody.innerHTML = res.usage_report.map(r =>
            `<tr><td>${r.company_id}</td><td>${r.requests}</td><td>${r.tokens}</td><td>${parseFloat(r.cost || 0).toFixed(4)}</td></tr>`
        ).join('');
    }

    loadAiUsage();

    document.querySelectorAll('.js-ai-nav').forEach(link => {
        link.addEventListener('click', () => {
            document.querySelectorAll('.js-ai-nav').forEach(a => a.classList.remove('is-active'));
            link.classList.add('is-active');
        });
    });
})();
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    const copyButtons = document.querySelectorAll('.js-copy-url');
    copyButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const url = this.getAttribute('data-url');
            if (!url) return;
            
            navigator.clipboard.writeText(url).then(() => {
                const originalHtml = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check2 text-success"></i>';
                setTimeout(() => {
                    this.innerHTML = originalHtml;
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        });
    });
});
</script>
</body>
</html>
