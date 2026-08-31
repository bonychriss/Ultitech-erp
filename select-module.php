<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();
require_once __DIR__ . '/select-module-ui/lib.php';

$userName = $_SESSION['full_name'] ?? 'User';
if (!is_string($userName)) {
    $userName = 'User';
}

$isAdmin = function_exists('isAdmin') ? isAdmin() : (($_SESSION['role'] ?? '') === 'admin');
$isRootAdminUsername = isset($_SESSION['username']) && $_SESSION['username'] === 'admin';
$requestedCompany = function_exists('getRequestedCompany') ? getRequestedCompany() : null;
$currentCompany = getCurrentCompany();
if ($requestedCompany && is_array($currentCompany)) {
    $currentCompany = array_merge($currentCompany, $requestedCompany);
} elseif ($requestedCompany) {
    $currentCompany = $requestedCompany;
}
$currentCompanyName = (string) ($currentCompany['company_name'] ?? ($_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company')));
$currentCompanySlug = (string) ($currentCompany['company_slug'] ?? ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
$companyRoute = static function (string $path) use ($currentCompanySlug): string {
    return company_url($path, $currentCompanySlug !== '' ? $currentCompanySlug : null);
};

$enabledModules = function_exists('getCompanyModules') ? getCompanyModules(true) : [];
$enabledModuleNames = array_map(static function ($m) {
    return (string) ($m['custom_label'] ?: $m['module_name']);
}, $enabledModules);

$logoCompanyId = (int) ($currentCompany['id'] ?? $requestedCompany['id'] ?? currentCompanyId() ?? 0);
$modulePageLogoUrl = '';
if (function_exists('resolveCompanyBrandingLogoUrl')) {
    $modulePageLogoUrl = resolveCompanyBrandingLogoUrl($logoCompanyId > 0 ? $logoCompanyId : null);
}
if ($modulePageLogoUrl === '' && function_exists('getCompanyLogoUrl')) {
    $modulePageLogoUrl = getCompanyLogoUrl($logoCompanyId > 0 ? $logoCompanyId : null);
}

if (isset($_SESSION['active_module'])) {
    $returnedFromEmail = ($_SESSION['active_module'] === 'email');
    unset($_SESSION['active_module']);
} else {
    $returnedFromEmail = false;
}

$expensesModuleUpdateBadge = null;
$expensesUpdateBadgePath = __DIR__ . '/modules/expenses/includes/update-badge.php';
if (is_file($expensesUpdateBadgePath)) {
    require_once $expensesUpdateBadgePath;
    if (function_exists('expenses_module_update_badge')) {
        $expensesModuleUpdateBadge = expenses_module_update_badge();
    }
}

$emailModuleUpdateBadge = null;
$emailModuleUpdateCampaign = null;
$emailUpdateBadgePath = __DIR__ . '/modules/email/includes/update-badge.php';
if (is_file($emailUpdateBadgePath)) {
    require_once $emailUpdateBadgePath;
    if (function_exists('email_module_update_badge')) {
        $emailModuleUpdateBadge = email_module_update_badge();
    }
    if (function_exists('email_module_update_campaign')) {
        $emailModuleUpdateCampaign = email_module_update_campaign((bool) $returnedFromEmail);
    }
}

$voucherModuleUrl = $isAdmin
    ? ($companyRoute('admin/dashboard') . '?module=voucher')
    : ($companyRoute('employee/dashboard') . '?module=voucher');

$deliveriesDashUrl = $companyRoute('deliveries/index') . '?module=deliveries';
if ($currentCompanySlug !== '') {
    $deliveriesDashUrl .= '&company_slug=' . rawurlencode($currentCompanySlug);
}

$modules = [];
$push = static function (array $mod) use (&$modules): void {
    $modules[] = $mod;
};

$push([
    'id' => 'voucher',
    'label' => 'Payment Voucher',
    'desc' => 'Process & Approve Expenses',
    'href' => $voucherModuleUrl,
    'icon' => 'voucher',
    'color' => '#0f766e',
]);
$push([
    'id' => 'attendance',
    'label' => 'Attendance',
    'desc' => 'Monitor Employee Time Logs',
    'href' => $companyRoute('attendance'),
    'icon' => 'attendance',
    'color' => '#c2410c',
]);
$push([
    'id' => 'deliveries',
    'label' => 'Delivery Logistics',
    'desc' => 'Fleet, Manifests & POD Tracking',
    'href' => $deliveriesDashUrl,
    'icon' => 'deliveries',
    'color' => '#0369a1',
]);
$push([
    'id' => 'outstanding',
    'label' => 'Outstanding Invoices',
    'desc' => 'Track Unpaid Bills & Debts',
    'href' => $companyRoute('erp/outstanding-invoices/index') . '?module=outstanding',
    'icon' => 'outstanding',
    'color' => '#be123c',
]);
$push([
    'id' => 'email',
    'label' => 'Mail',
    'desc' => 'Manage Communications',
    'href' => $companyRoute('modules/email/index') . '?module=email',
    'icon' => 'email',
    'color' => '#2563eb',
    'badge' => $emailModuleUpdateBadge ? (string) ($emailModuleUpdateBadge['label'] ?? 'New') : null,
]);
$push([
    'id' => 'expenses',
    'label' => 'Expenses',
    'desc' => 'Record & track business expenses',
    'href' => $companyRoute('modules/expenses/index') . '?module=expenses',
    'icon' => 'expenses',
    'color' => '#7e22ce',
    'badge' => $expensesModuleUpdateBadge ? (string) ($expensesModuleUpdateBadge['label'] ?? 'New') : null,
]);
$push([
    'id' => 'petty_cash',
    'label' => 'Petty Cash',
    'desc' => 'Custodian float, vouchers & Balances',
    'href' => $companyRoute('modules/petty-cash/index') . '?module=petty_cash',
    'icon' => 'petty_cash',
    'color' => '#0d9488',
]);
$push([
    'id' => 'payroll',
    'label' => 'Payroll',
    'desc' => 'Process Salaries & Payslips',
    'href' => $companyRoute('modules/payroll/index') . '?module=payroll',
    'icon' => 'payroll',
    'color' => '#1d4ed8',
]);
$push([
    'id' => 'revenue',
    'label' => 'Revenue & Debt',
    'desc' => 'Income Recording & Collection',
    'href' => $companyRoute('revenue_entries') . '?module=revenue',
    'icon' => 'revenue',
    'color' => '#d97706',
]);
$push([
    'id' => 'accounting',
    'label' => 'Accounting',
    'desc' => 'Balances, Journals & Reports',
    'href' => $companyRoute('accounting'),
    'icon' => 'accounting',
    'color' => '#0f172a',
]);
$push([
    'id' => 'balances',
    'label' => 'Balances',
    'desc' => 'Liquidity & Accounts',
    'href' => $companyRoute('modules/balances/index'),
    'icon' => 'balances',
    'color' => '#0891b2',
]);

if (function_exists('isFinanceOrAdmin') && isFinanceOrAdmin()) {
    $push([
        'id' => 'budgets',
        'label' => 'Budgets',
        'desc' => 'Plans, actuals & variance tracking',
        'href' => $companyRoute('modules/finance/budgets/index') . '?module=finance',
        'icon' => 'budgets',
        'color' => '#0f766e',
    ]);
}

$push([
    'id' => 'stock',
    'label' => 'Stock Management',
    'desc' => 'Inventory Control & Audits',
    'href' => $companyRoute('stock'),
    'icon' => 'stock',
    'color' => '#1e3a8a',
]);
$push([
    'id' => 'warehouses',
    'label' => 'Warehouses / Stores',
    'desc' => 'Manage storage centers & stock transfers',
    'href' => $companyRoute('store-management-system/index.php') . '?module=warehouses',
    'icon' => 'warehouses',
    'color' => '#4f46e5',
]);
$push([
    'id' => 'sales',
    'label' => 'Sales',
    'desc' => 'Manage Quotes, Orders & Billing',
    'href' => $companyRoute('sales'),
    'icon' => 'sales',
    'color' => '#15803d',
]);
$push([
    'id' => 'crm',
    'label' => 'CRM',
    'desc' => 'Manage Leads, Prospects & Customers',
    'href' => $companyRoute('modules/crm/my-clients/index') . '?module=crm',
    'icon' => 'crm',
    'color' => '#6366f1',
]);
$push([
    'id' => 'statement',
    'label' => 'Statement',
    'desc' => 'Customer balances, statements & exports',
    'href' => $companyRoute('customer_statement/index') . '?module=sales',
    'icon' => 'statement',
    'color' => '#111827',
]);
$push([
    'id' => 'dispatch',
    'label' => 'Dispatch',
    'desc' => 'Create Dispatch Notes',
    'href' => $companyRoute('dispatch/index') . '?module=dispatch',
    'icon' => 'dispatch',
    'color' => '#2563eb',
]);
$push([
    'id' => 'todo',
    'label' => 'To-Do List',
    'desc' => 'Manage Personal Tasks',
    'href' => $companyRoute('todo/index') . '?module=todo',
    'icon' => 'todo',
    'color' => '#6366f1',
]);
$push([
    'id' => 'performance',
    'label' => 'Performance',
    'desc' => 'Weekly Plans, Scoring & Leaderboard',
    'href' => $companyRoute('weekly_tasks/index') . '?module=tasks',
    'icon' => 'performance',
    'color' => '#e11d48',
]);

if ($isAdmin || $isRootAdminUsername) {
    $push([
        'id' => 'settings',
        'label' => 'General Settings',
        'desc' => 'Manage Global Preferences',
        'href' => $companyRoute('admin/settings') . '?module=settings',
        'icon' => 'settings_admin',
        'color' => '#4b5563',
    ]);
} else {
    $push([
        'id' => 'settings',
        'label' => 'Settings',
        'desc' => 'Manage Account & System Settings',
        'href' => $companyRoute('employee/system-settings') . '?module=account',
        'icon' => 'settings',
        'color' => '#4b5563',
    ]);
}

$push([
    'id' => 'backup',
    'label' => 'Backup',
    'desc' => 'Download full company database & file backups',
    'href' => $companyRoute('modules/backup/index') . '?module=backup',
    'icon' => 'backup',
    'color' => '#0f766e',
]);

$push([
    'id' => 'suggestions',
    'label' => 'Suggestions',
    'desc' => 'Submit System Improvements',
    'href' => $companyRoute('suggest'),
    'icon' => 'suggestions',
    'color' => '#ca8a04',
]);
$push([
    'id' => 'analytics',
    'label' => 'Data Analysis & Reports',
    'desc' => 'KPIs, charts & business insights',
    'href' => $companyRoute('modules/analytics/index') . '?module=analytics',
    'icon' => 'analytics',
    'color' => '#6366f1',
]);

if ($isAdmin) {
    $push([
        'id' => 'letters',
        'label' => 'Inbox',
        'desc' => 'View Internal Correspondence',
        'href' => $companyRoute('manage-letters'),
        'icon' => 'inbox',
        'color' => '#4338ca',
    ]);
} else {
    $push([
        'id' => 'letters',
        'label' => 'Write Letter',
        'desc' => 'Draft Official Requests',
        'href' => $companyRoute('write-letter'),
        'icon' => 'letter',
        'color' => '#4338ca',
    ]);
}

$push([
    'id' => 'layout',
    'label' => 'Layout',
    'desc' => 'Customize Interface Theme',
    'href' => $companyRoute('layout'),
    'icon' => 'layout',
    'color' => '#a21caf',
]);

$assets = selectModuleUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Select Module</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Select Module</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>select-module-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$logoutUrl = function_exists('app_url') ? app_url('/logout.php') : 'logout.php';
$statusUrl = function_exists('app_url') ? app_url('/system-status.php') : 'system-status.php';
$desktopAppDownloadUrl = function_exists('app_url') ? app_url('/client-apps/download-desktop.php') : '/client-apps/download-desktop.php';
$desktopLatestVersion = selectModuleDesktopLatestVersion();

$selectModuleConfig = [
    'companyName' => $currentCompanyName,
    'logoUrl' => $modulePageLogoUrl,
    'homeUrl' => $companyRoute('select-module'),
    'logoutUrl' => $logoutUrl,
    'statusUrl' => $statusUrl,
    'showStatus' => $isRootAdminUsername,
    'desktopAppDownloadUrl' => $desktopAppDownloadUrl,
    'showDesktopAppDownload' => true,
    'desktopUpdate' => $desktopLatestVersion !== null ? [
        'latestVersion' => $desktopLatestVersion,
        'downloadUrl' => $desktopAppDownloadUrl,
    ] : null,
    'enabledModuleLabels' => $enabledModuleNames,
    'modules' => $modules,
    'mailUpdate' => $emailModuleUpdateCampaign,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Module - <?= htmlspecialchars($currentCompanyName) ?></title>
    <?php if (function_exists('erp_get_theme_init_html')) {
        echo erp_get_theme_init_html();
    } else { ?>
    <script>(function(){try{var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <?php }
    if (function_exists('renderSystemFontHeadMarkup')) {
        renderSystemFontHeadMarkup();
    } ?>
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__SELECT_MODULE_CFG__ = <?= json_encode($selectModuleConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body>
    <noscript><div style="padding:2rem;font-family:sans-serif;">JavaScript is required to select a module.</div></noscript>
    <div id="root"></div>
    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
