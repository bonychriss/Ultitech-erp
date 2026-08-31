<?php
/**
 * Root Sidebar Component (Staff ERP)
 * Updated to use Bootstrap 5 (Native PHP)
 */

if (!function_exists('isAdmin')) {
    // Helper access to safe session check
}

// 1. Capture Module Context
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$current_page = basename($script_name);

if (isset($_GET['module'])) {
    $_SESSION['active_module'] = $_GET['module'];
}
// Default to 'dashboard' (or 'none') if not set
$active_module = $active_module ?? ($_SESSION['active_module'] ?? 'dashboard');

// Auto-detect Stocks module based on path
$path_to_check = $script_name . ($_SERVER['PHP_SELF'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
$industry = getCompanyType();

if (stripos($path_to_check, '/modules/warehouses/') !== false
    || (stripos($path_to_check, 'store-management-system') !== false && (($_GET['module'] ?? '') === 'warehouses'))
) {
    $active_module = 'warehouses';
} elseif (stripos($path_to_check, 'store-management-system') !== false) {
    $active_module = 'store-management';
} elseif (stripos($path_to_check, 'stock') !== false || stripos($path_to_check, 'procurement') !== false) {
    $active_module = 'stocks';
}
if (stripos($path_to_check, '/sales/') !== false) {
    $active_module = 'sales';
}
if (stripos($path_to_check, '/deliveries/') !== false) {
    $active_module = 'deliveries';
}
if (strpos($script_name, '/modules/expenses/') !== false) {
    $active_module = 'expenses';
}
if (strpos($script_name, '/modules/petty-cash/') !== false) {
    $active_module = 'petty_cash';
}
if (strpos($script_name, '/modules/finance/') !== false) {
    $active_module = 'finance';
}
if (strpos($script_name, '/modules/balances/') !== false) {
    $active_module = 'balances';
}
if (basename($script_name) === 'supplier-payments.php' && (($_GET['module'] ?? '') === 'balances')) {
    $active_module = 'balances';
}
if (basename($script_name) === 'stock-purchase-payment-desk.php' && (($_GET['module'] ?? '') === 'balances')) {
    $active_module = 'balances';
}
if (strpos($script_name, '/accounting/') !== false && (isset($_GET['module']) && $_GET['module'] === 'balances')) {
    $active_module = 'balances';
}
if (strpos($script_name, '/modules/payroll/') !== false) {
    $active_module = 'payroll';
}
if (strpos($script_name, '/weekly_tasks/') !== false) {
    $active_module = 'tasks';
}
if (strpos($script_name, '/dispatch/') !== false) {
    $active_module = 'dispatch';
}
if (strpos(str_replace('\\', '/', $script_name), '/attendance/') !== false
    || stripos($script_name . ($_SERVER['REQUEST_URI'] ?? ''), 'view-attendance') !== false
) {
    $attPath = str_replace('\\', '/', $script_name . ($_SERVER['REQUEST_URI'] ?? ''));
    if (strpos($attPath, '/attendance/settings.php') !== false && (($_GET['module'] ?? '') === 'settings')) {
        $active_module = 'settings';
    } else {
        $active_module = 'attendance';
    }
}
if (strpos($script_name, '/todo/') !== false) {
    $active_module = 'todo';
}
if (strpos($script_name, '/modules/analytics/') !== false) {
    $active_module = 'analytics';
}
if (strpos(str_replace('\\', '/', $script_name), '/modules/sales-reports/') !== false) {
    $active_module = 'analytics';
}
if (strpos($script_name, '/modules/company-profile/') !== false) {
    $active_module = 'company-profile';
}
if (strpos(str_replace('\\', '/', $script_name), '/modules/email/') !== false) {
    $active_module = 'email';
}
if (strpos(str_replace('\\', '/', $script_name), '/modules/crm/') !== false) {
    $active_module = 'crm';
}
if (strpos(str_replace('\\', '/', $script_name), '/employee/personalization/') !== false) {
    $active_module = 'personalization';
}
if ($current_page === 'dashboard.php' && strpos($script_name, '/employee/') !== false && (!isset($_GET['module']))) {
    $active_module = 'voucher';
}
$isCompanySettingsPage = ($current_page === 'company-settings.php')
    && (stripos(str_replace('\\', '/', $script_name . ($_SERVER['REQUEST_URI'] ?? '')), 'company-settings.php') !== false);
if ($isCompanySettingsPage) {
    $active_module = 'company-settings';
}

// Customer Statement (Sales): show only statement-related shortcuts, not full Sales menu
$script_name_norm = str_replace('\\', '/', $script_name);
$isCustomerStatementSalesSidebar = (
    strpos($script_name_norm, '/customer_statement/') !== false
    && ($active_module === 'sales')
);

$roleNorm = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = ($roleNorm === 'admin' || $roleNorm === 'administrator' || $roleNorm === 'superadmin' || $roleNorm === 'super_admin' || $roleNorm === 'company_admin' || $roleNorm === 'owner');

// 3. Fetch WhatsApp Group Link if available
$whatsappGroupLink = null;
if (function_exists('getWhatsAppGroupLink')) {
    $whatsappGroupLink = getWhatsAppGroupLink();
}

// Build URLs from application base path including company slug for tenant-aware routing.
$currentSlug = trim((string) ($_SESSION['company_slug'] ?? getRequestedCompanySlug()));
if ($currentSlug !== '') {
    $prefix = rtrim(app_url('/' . $currentSlug), '/') . '/';
} else {
    $prefix = rtrim(app_url('/'), '/') . '/';
}

// Determine Select Module URL (slug-aware when available)
$sessionSlug = trim((string) ($_SESSION['company_slug'] ?? ''));
$selectModuleUrl = company_dashboard_url($sessionSlug !== '' ? $sessionSlug : null);

// Company branding for sidebar header
$sidebarCompanyName = trim((string) ($_SESSION['company_name'] ?? ''));
$sidebarCompanyLogoUrl = '';
try {
    $companyInfo = function_exists('getCompanyInfo') ? getCompanyInfo() : [];
    if ($sidebarCompanyName === '') {
        $sidebarCompanyName = trim((string) ($companyInfo['company_name'] ?? ''));
    }

    if (function_exists('getCompanyLogoUrl')) {
        $sidebarCompanyLogoUrl = getCompanyLogoUrl();
    }

    if ($sidebarCompanyLogoUrl === '') {
        $logoCandidate = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_logo', '')) : '';
        if ($logoCandidate === '' && isset($companyInfo['logo'])) {
            $logoCandidate = trim((string) $companyInfo['logo']);
        }
        if ($logoCandidate !== '') {
            $rel = ltrim(str_replace('\\', '/', $logoCandidate), '/');
            $disk = dirname(__FILE__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($disk)) {
                $sidebarCompanyLogoUrl = app_url('/' . $rel);
            }
        }
    }
} catch (Throwable $e) {
    // Keep sidebar resilient if branding lookup fails.
}

if ($sidebarCompanyName === '') {
    $sidebarCompanyName = 'OMMYERP';
}
$sidebarCompanyInitial = strtoupper(substr($sidebarCompanyName, 0, 1));

// --- GENERATE SIDEBAR DATA ---
$menuItems = [];
$__expensesModuleUpdateBadge = null;
$__expensesUpdateBadgePath = __DIR__ . '/modules/expenses/includes/update-badge.php';
if (is_file($__expensesUpdateBadgePath)) {
    require_once $__expensesUpdateBadgePath;
    $__expensesModuleUpdateBadge = expenses_module_update_badge();
}

// Helper to add item
if (!function_exists('addItem')) {
    function addItem(&$arr, $id, $label, $icon, $path, $badge = null, $linkClass = '') {
        $moduleKey = null;
        $pathStr = strtolower((string) $path);
        if (strpos($pathStr, '/modules/sales/') !== false || strpos($pathStr, 'customer_statement') !== false) {
            $moduleKey = 'sales';
        } elseif (strpos($pathStr, '/stock/') !== false || strpos($pathStr, '/stocks/') !== false) {
            $moduleKey = 'stock';
        } elseif (strpos($pathStr, '/modules/payroll/') !== false) {
            $moduleKey = 'payroll';
        } elseif (strpos($pathStr, '/modules/finance/') !== false || strpos($pathStr, '/modules/expenses/') !== false) {
            $moduleKey = 'finance';
        } elseif (strpos($pathStr, '/modules/balances/') !== false || strpos($pathStr, '/accounting/') !== false) {
            $moduleKey = 'accounting';
        } elseif (strpos($pathStr, '/attendance/') !== false) {
            $moduleKey = 'attendance';
        } elseif (strpos($pathStr, '/deliveries/') !== false || strpos($pathStr, '/dispatch/') !== false || strpos($pathStr, 'logistics') !== false) {
            $moduleKey = 'logistics';
        } elseif (strpos($pathStr, '/modules/crm/') !== false) {
            $moduleKey = 'crm';
        } elseif (strpos($pathStr, 'voucher') !== false) {
            $moduleKey = 'payment_voucher';
        } elseif (strpos($pathStr, 'revenue') !== false) {
            $moduleKey = 'revenue';
        }
        $isDispatchOrDeliveryPath = (strpos($pathStr, '/dispatch/') !== false || strpos($pathStr, '/deliveries/') !== false);
        if ($moduleKey !== null && function_exists('isCompanyModuleEnabled') && !isCompanyModuleEnabled($moduleKey) && !$isDispatchOrDeliveryPath) {
            return;
        }
        
        // Industry Toggles
        if ($moduleKey === 'logistics' && !in_array(getCompanyType(), ['trucks', 'logistics']) && !$isDispatchOrDeliveryPath) {
            return;
        }
        // Map Feather icons to Bootstrap Icons (bi-*) if needed, 
        // or just accept that the old icons (like 'home', 'users') match standard names often.
        // We'll prefix them with 'bi-' in the HTML.
        $arr[] = [
            'id' => $id,
            'label' => $label,
            'icon' => $icon,
            'path' => $path,
            'badge' => $badge,
            'linkClass' => (string) $linkClass,
        ];
    }
}
if (!function_exists('sidebar_normalize_icon')) {
    function sidebar_normalize_icon(string $icon): string
    {
        $icon = trim($icon);
        if ($icon === '' || strpos($icon, 'fa-') !== false || str_contains($icon, ' ')) {
            return $icon;
        }
        if ($icon === 'home') {
            return 'house';
        }
        if ($icon === 'users') {
            return 'people';
        }
        if ($icon === 'user') {
            return 'person';
        }
        if ($icon === 'shopping-bag') {
            return 'bag';
        }
        if ($icon === 'document-text') {
            return 'file-text';
        }
        if ($icon === 'chart-bar') {
            return 'bar-chart';
        }
        if ($icon === 'chart-pie') {
            return 'pie-chart';
        }
        if ($icon === 'cog') {
            return 'gear';
        }
        if ($icon === 'coins') {
            return 'cash-coin';
        }

        return $icon;
    }
}
if (!function_exists('sidebar_render_nav_icon')) {
    function sidebar_render_nav_icon(string $icon): string
    {
        $icon = sidebar_normalize_icon($icon);
        if ($icon === '') {
            return '<i class="bi bi-circle" aria-hidden="true"></i>';
        }
        if (strpos($icon, 'fa-') !== false || str_contains($icon, ' ')) {
            return '<i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>';
        }

        return '<i class="bi bi-' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>';
    }
}
if (!function_exists('addParentItem')) {
    function addParentItem(&$arr, $id, $label, $icon, array $children, $badge = null, $path = '#') {
        $arr[] = [
            'id' => $id,
            'label' => $label,
            'icon' => $icon,
            'path' => $path !== '' ? (string) $path : '#',
            'children' => $children,
            'badge' => $badge,
        ];
    }
}

// Module Header (All Modules)
addItem($menuItems, 'all-modules', 'All Modules', 'grid', $selectModuleUrl);

// Attendance clock shortcut (pretty company URL)
$attClockHref = function_exists('company_url')
    ? company_url('attendance', $currentSlug !== '' ? $currentSlug : null)
    : ($prefix . 'attendance/');
$attClockSep = (strpos($attClockHref, '?') !== false) ? '&' : '?';
$attClockQs = 'module=attendance';
if ($currentSlug !== '') {
    $attClockQs .= '&company_slug=' . rawurlencode($currentSlug);
}
$attClockHref .= $attClockSep . $attClockQs;

if ($active_module === 'deliveries') {
    $dlvDashQsEarly = 'module=deliveries';
    if ($currentSlug !== '') {
        $dlvDashQsEarly .= '&company_slug=' . rawurlencode($currentSlug);
    }
    $deliveriesDashboardUrlEarly = company_url('deliveries/index', $currentSlug !== '' ? $currentSlug : null);
    $dlvDashSepEarly = (strpos($deliveriesDashboardUrlEarly, '?') !== false) ? '&' : '?';
    addItem($menuItems, 'dashboard', 'Dashboard', 'truck', $deliveriesDashboardUrlEarly . $dlvDashSepEarly . $dlvDashQsEarly);
}

if ($active_module === 'analytics') {
    if ($is_admin) {
        addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=analytics');
    } else {
        addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=analytics');
    }
    addItem($menuItems, 'analytics-overview', 'Overview', 'speedometer2', $prefix . 'modules/analytics/index.php?module=analytics');
    addItem($menuItems, 'business_reports', 'All Reports', 'file-earmark-text', $prefix . 'modules/sales-reports/index.php?module=analytics');
    $reportCreateBase = $prefix . 'modules/sales-reports/index.php?module=analytics&create=';
    addParentItem($menuItems, 'business-reports-parent', 'Reports', 'file-earmark-bar-graph', [
        [
            'id' => 'report-sales',
            'label' => 'Sales Report',
            'icon' => 'graph-up-arrow',
            'path' => $reportCreateBase . 'sales',
        ],
        [
            'id' => 'report-procurement',
            'label' => 'Stock Report',
            'icon' => 'box-seam',
            'path' => $reportCreateBase . 'procurement',
        ],
        [
            'id' => 'report-finance',
            'label' => 'Finance Report',
            'icon' => 'cash-stack',
            'path' => $reportCreateBase . 'finance',
        ],
        [
            'id' => 'report-fleet',
            'label' => 'Driver / Fleet Report',
            'icon' => 'truck',
            'path' => $reportCreateBase . 'fleet',
        ],
        [
            'id' => 'report-store-warehouse',
            'label' => 'Store / Warehouse Report',
            'icon' => 'box-seam',
            'path' => $reportCreateBase . 'store_warehouse',
        ],
    ], null, $prefix . 'modules/sales-reports/index.php?module=analytics');
} else {
if (isCompanyAdmin()) {
    addItem($menuItems, 'company-dashboard', 'Company Admin', 'building', $prefix . 'company/dashboard.php');
}

if ($isCustomerStatementSalesSidebar) {
    addItem($menuItems, 'customer-statement', 'Customer Statement', 'file-text', $prefix . 'customer_statement/index.php?module=sales');
    $stmtDateFrom = isset($_GET['date_from']) && (string) $_GET['date_from'] !== '' ? (string) $_GET['date_from'] : date('Y-m-01');
    $stmtDateTo = isset($_GET['date_to']) && (string) $_GET['date_to'] !== '' ? (string) $_GET['date_to'] : date('Y-m-d');
    $statementReturn = '/customer_statement/index.php?module=sales&date_from=' . rawurlencode($stmtDateFrom) . '&date_to=' . rawurlencode($stmtDateTo);
    addItem($menuItems, 'statement-catalogue', 'Customer catalogue', 'people', $prefix . 'modules/sales/customers/catalogue.php?module=sales&doc=statement&return=' . rawurlencode($statementReturn));
} else {
switch ($active_module) {
    case 'stocks':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=stocks');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=stocks');
        }
        addItem($menuItems, 'dashboard', 'Dashboard', 'bar-chart', $prefix . 'stock/dashboard.php');
        addItem($menuItems, 'catalogue', 'Catalogue', 'clipboard', $prefix . 'stock/catalogue.php');
        addParentItem($menuItems, 'products-parent', 'Products', 'box', [
            [
                'id' => 'products-all',
                'label' => 'All Products',
                'icon' => 'grid',
                'path' => $prefix . 'stock/modules/products/index.php',
            ],
            [
                'id' => 'products-add',
                'label' => 'Add Product',
                'icon' => 'plus-circle',
                'path' => $prefix . 'stock/modules/products/add.php',
            ],
            [
                'id' => 'products-stock-level',
                'label' => 'Stock Level',
                'icon' => 'clipboard-data',
                'path' => $prefix . 'stock/modules/reports/stock.php',
                'badge' => 'Soon',
            ],
            [
                'id' => 'products-categories',
                'label' => 'Categories',
                'icon' => 'tags',
                'path' => $prefix . 'stock/modules/products/categories.php',
            ],
            [
                'id' => 'products-brands',
                'label' => 'Brands',
                'icon' => 'award',
                'path' => $prefix . 'stock/modules/brands/index.php',
            ],
            [
                'id' => 'products-bulk-import',
                'label' => 'Bulk Import',
                'icon' => 'cloud-arrow-up',
                'path' => $prefix . 'stock/modules/products/bulk_import.php',
            ],
            [
                'id' => 'products-uploaded-files',
                'label' => 'Uploaded Files',
                'icon' => 'folder2-open',
                'path' => $prefix . 'stock/modules/uploads/index.php?folder=products&images=1',
            ],
        ]);
        addItem($menuItems, 'suppliers', 'Suppliers', 'people', $prefix . 'stock/modules/suppliers/index.php');
        addItem($menuItems, 'shipments', 'Shipments', 'truck', $prefix . 'stock/modules/shipments/index.php');
        addItem($menuItems, 'purchases', 'Purchases', 'bag', $prefix . 'stock/modules/purchases/index.php');
        addItem($menuItems, 'replenishment', 'Replenishment', 'arrow-repeat', $prefix . 'stock/modules/reports/replenishment.php');
        addItem($menuItems, 'stock-control', 'Stock Control', 'sliders', $prefix . 'stock/modules/stock/movements.php');
        addItem($menuItems, 'store-management', 'Store Management', 'shop', app_url('store-management-system/index.php'));
        addItem($menuItems, 'warehouses', 'Warehouses', 'building', app_url('store-management-system/index.php?module=warehouses'));
        addItem($menuItems, 'transfers', 'Stock Transfers', 'arrow-left-right', $prefix . 'stock/modules/transfers/index.php?module=warehouses', 'Soon');
        addItem($menuItems, 'reports', 'Reports', 'file-text', $prefix . 'stock/modules/reports/stock.php', 'Soon');
        break;

    case 'warehouses':
        addItem($menuItems, 'store-inventory', 'Store Inventory', 'shop', app_url('store-management-system/index.php?module=warehouses'));
        addItem($menuItems, 'product-labels', 'Labels', 'tags', app_url('store-management-system/index.php?page=labels&module=warehouses'));
        addItem($menuItems, 'transfers', 'Stock Transfers', 'arrow-left-right', $prefix . 'stock/modules/transfers/index.php?module=warehouses', 'Soon');
        break;

    case 'store-management':
        addItem($menuItems, 'store-inventory', 'Inventory', 'shop', app_url('store-management-system/index.php'));
        addItem($menuItems, 'product-labels', 'Label', 'tags', app_url('store-management-system/index.php?page=labels'));
        addItem($menuItems, 'warehouses', 'Warehouses', 'building', app_url('store-management-system/index.php?module=warehouses'));
        break;

    case 'crm':
        $crmMarketNavFile = __DIR__ . '/modules/crm/includes/crm-market-nav.php';
        if (!is_file($crmMarketNavFile)) {
            $crmMarketNavFile = dirname(__DIR__) . '/modules/crm/includes/crm-market-nav.php';
        }
        if (is_file($crmMarketNavFile)) {
            require_once $crmMarketNavFile;
        }
        $crmBase = function_exists('company_url')
            ? company_url('modules/crm/my-clients/index')
            : ($prefix . 'modules/crm/my-clients/index.php');
        $crmSep = (strpos($crmBase, '?') !== false) ? '&' : '?';
        $crmDashboardHref = $crmBase . $crmSep . 'module=crm&tab=dashboard';
        $crmCustomersHref = $crmBase . $crmSep . 'module=crm&tab=customers';
        $crmMarketHref = function_exists('company_url')
            ? company_url('modules/crm/market/index')
            : ($prefix . 'modules/crm/market/index.php');
        $crmMarketSep = (strpos($crmMarketHref, '?') !== false) ? '&' : '?';
        $crmMarketHref = $crmMarketHref . $crmMarketSep . 'module=crm';
        $crmNewLeadsHref = $crmMarketHref . '&view=new-leads';
        addItem($menuItems, 'dashboard', 'Dashboard', 'bar-chart', $crmDashboardHref);
        addItem($menuItems, 'my-customers', 'My Customers', 'people', $crmCustomersHref);
        addItem($menuItems, 'new-leads', 'New Leads', 'lightning', $crmNewLeadsHref);
        $crmMarketChildren = function_exists('crmMarketSidebarChildren')
            ? crmMarketSidebarChildren($crmMarketHref, 'bs')
            : [];
        addParentItem(
            $menuItems,
            'crm-market',
            'CRM Market',
            'shop',
            $crmMarketChildren,
            null,
            $crmMarketHref . '&view=home'
        );
        break;

    case 'sales':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=sales');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=sales');
        }
        addItem($menuItems, 'dashboard', 'Dashboard', 'bar-chart', $prefix . 'modules/sales/dashboard/index.php?module=sales');
        addItem($menuItems, 'my-sales', 'My Sales', 'person-lines-fill', $prefix . 'modules/sales/my-sales/index.php?module=sales');
        addItem($menuItems, 'pricelist', 'Pricelist', 'tags', $prefix . 'modules/sales/pricelist.php?module=sales');
        addItem($menuItems, 'customers', 'Customers', 'people', $prefix . 'modules/sales/customers/index.php?module=sales');
        addItem($menuItems, 'customer-statement', 'Customer Statement', 'file-text', $prefix . 'customer_statement/index.php?module=sales');
        // Add Customer hidden
        addItem($menuItems, 'create', 'Quotation', 'file-earmark-plus', $prefix . 'modules/sales/orders/create.php?module=sales');
        addItem($menuItems, 'orders', 'Sales Orders', 'bag', $prefix . 'modules/sales/orders/index.php?module=sales');
        addItem($menuItems, 'invoices', 'Invoices', 'receipt', $prefix . 'modules/sales/invoices/index.php?module=sales');
        addItem($menuItems, 'sales-settings', 'Sales Settings', 'gear', $prefix . 'modules/sales/settings/index.php?module=sales');
        // Record Payment hidden
        if ($is_admin) {
             addItem($menuItems, 'targets', 'Set Targets', 'bullseye', $prefix . 'modules/sales/admin/targets.php?module=sales');
             addItem($menuItems, 'reassign-sales', 'Reassign Sales', 'shuffle', $prefix . 'modules/sales/admin/reassign-sales.php?module=sales');
        }
        break;

    case 'finance':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=finance');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=finance');
        }
        addItem($menuItems, 'dashboard', 'Overview', 'pie-chart', $prefix . 'modules/finance/index.php');
        addItem($menuItems, 'transactions', 'Transactions', 'list-check', $prefix . 'modules/finance/transactions.php');
        if (function_exists('isFinanceOrAdmin') && isFinanceOrAdmin()) {
            addItem($menuItems, 'stock-purchase-payments', 'Stock Purchase Payments', 'cart-check', $prefix . 'modules/finance/stock-purchase-payment-desk.php');
        }
        addItem($menuItems, 'accounts', 'Payment Accounts', 'credit-card', $prefix . 'modules/finance/payment_methods.php');
        addItem($menuItems, 'budgets', 'Budgets', 'wallet2', $prefix . 'modules/finance/budgets.php');
        addItem($menuItems, 'reports', 'Reports', 'file-earmark-bar-graph', $prefix . 'modules/finance/reports.php');
        addParentItem($menuItems, 'journal-parent', 'Journal', 'journal-text', [
            [
                'id' => 'journal-entries',
                'label' => 'Journal Entries',
                'icon' => 'journal-text',
                'path' => $prefix . 'accounting/journal-entries.php?module=balances',
            ],
            [
                'id' => 'journal-config',
                'label' => 'Journal Configuration',
                'icon' => 'gear',
                'path' => $prefix . 'accounting/journal-configuration.php?module=balances',
            ],
        ]);
        addItem($menuItems, 'reconciliation', 'Reconciliation', 'link-45deg', $prefix . 'accounting/reconciliation.php?module=balances');
        break;

    case 'accounting':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=accounting');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=accounting');
        }
        addItem($menuItems, 'dashboard', 'Dashboard', 'calculator', $prefix . 'modules/accounting/index.php?module=accounting');
        addParentItem($menuItems, 'balances-parent', 'Balances', 'scale-balanced', [
            [
                'id' => 'balances-dashboard',
                'label' => 'Liquidity',
                'icon' => 'speedometer2',
                'path' => $prefix . 'modules/balances/index.php?module=balances',
            ],
            [
                'id' => 'balances-accounts',
                'label' => 'Accounts',
                'icon' => 'wallet2',
                'path' => $prefix . 'modules/balances/accounts.php',
            ],
            [
                'id' => 'balances-transfer',
                'label' => 'Internal Transfer',
                'icon' => 'arrow-left-right',
                'path' => $prefix . 'modules/balances/transfer.php',
            ],
            [
                'id' => 'balances-transactions',
                'label' => 'Transactions',
                'icon' => 'list-check',
                'path' => $prefix . 'modules/balances/transactions.php',
            ],
        ]);
        addItem($menuItems, 'revenue-list', 'Revenues', 'fas fa-coins', $prefix . 'revenue_entries.php?module=revenue', null, 'sidebar-revenue-link');
        addParentItem($menuItems, 'expenses-parent', 'Expenses', 'receipt', [
            [
                'id' => 'expenses-dashboard',
                'label' => 'Expenses',
                'icon' => 'bar-chart',
                'path' => $prefix . 'modules/expenses/index.php?module=expenses',
            ],
            [
                'id' => 'expenses-new',
                'label' => 'New Expense',
                'icon' => 'file-earmark-plus',
                'path' => $prefix . 'modules/expenses/create.php?module=expenses',
            ],
            [
                'id' => 'expenses-view',
                'label' => 'View Expenses',
                'icon' => 'list-ul',
                'path' => $prefix . 'modules/expenses/view.php?module=expenses',
            ],
        ], $__expensesModuleUpdateBadge);
        addParentItem($menuItems, 'petty-cash-parent', 'Petty Cash', 'wallet2', [
            [
                'id' => 'petty-cash-dashboard',
                'label' => 'Dashboard',
                'icon' => 'speedometer2',
                'path' => $prefix . 'modules/petty-cash/index.php?module=petty_cash',
            ],
            [
                'id' => 'petty-cash-voucher',
                'label' => 'New Voucher',
                'icon' => 'ticket-perforated',
                'path' => $prefix . 'modules/petty-cash/create-voucher.php?module=petty_cash',
            ],
            [
                'id' => 'petty-cash-topup',
                'label' => 'Top-up',
                'icon' => 'plus-circle',
                'path' => $prefix . 'modules/petty-cash/replenishments/index.php?module=petty_cash',
            ],
        ]);
        addParentItem($menuItems, 'journal-parent', 'Journal', 'journal-text', [
            [
                'id' => 'journal-entries',
                'label' => 'Journal Entries',
                'icon' => 'journal-text',
                'path' => $prefix . 'accounting/journal-entries.php?module=accounting',
            ],
            [
                'id' => 'journal-config',
                'label' => 'Journal Configuration',
                'icon' => 'gear',
                'path' => $prefix . 'accounting/journal-configuration.php?module=accounting',
            ],
        ]);
        addItem($menuItems, 'reconciliation', 'Reconciliation', 'link-45deg', $prefix . 'accounting/reconciliation.php?module=accounting');
        break;

    case 'balances':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=balances');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=balances');
        }
        addItem($menuItems, 'dashboard', 'Dashboard', 'speedometer2', $prefix . 'modules/balances/index.php');
        addItem($menuItems, 'accounts', 'Account', 'wallet2', $prefix . 'modules/balances/accounts.php');
        if (function_exists('isFinanceOrAdmin') && isFinanceOrAdmin()) {
            addItem($menuItems, 'stock-purchase-payments', 'Stock Purchase Payments', 'cart-check', $prefix . 'modules/finance/stock-purchase-payment-desk.php?module=balances');
        }
        addItem($menuItems, 'transactions', 'Transactions', 'list-check', $prefix . 'modules/balances/transactions.php');
        addItem($menuItems, 'transfer', 'Internal Transfer', 'arrow-left-right', $prefix . 'modules/balances/transfer.php');
        addItem($menuItems, 'reconciliation', 'Reconciliation', 'link-45deg', $prefix . 'accounting/reconciliation.php?module=balances');
        break;

    case 'payroll':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=payroll');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=payroll');
        }
        if (isFinanceOrAdmin()) {
            addItem($menuItems, 'dashboard', 'Dashboard', 'speedometer2', $prefix . 'modules/payroll/index.php?module=payroll');
            addItem($menuItems, 'salaries', 'Employees', 'person-badge', $prefix . 'modules/payroll/salaries.php?module=payroll');
            addItem($menuItems, 'run', 'Run Payroll', 'play-circle', $prefix . 'modules/payroll/run_payroll.php?module=payroll');
            addItem($menuItems, 'settings', 'Settings', 'gear', $prefix . 'modules/payroll/settings.php?module=payroll');
        } else {
            addItem($menuItems, 'my-payslips', 'My Payslips', 'file-text', $prefix . 'modules/payroll/my_payslips.php?module=payroll');
        }
        break;

    case 'deliveries':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=deliveries');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=deliveries');
        }
        addItem($menuItems, 'notes', 'Delivery Notes', 'clipboard', $prefix . 'deliveries/delivery_notes.php?module=deliveries');
        $dlvDashQs = 'module=deliveries';
        if ($currentSlug !== '') {
            $dlvDashQs .= '&company_slug=' . rawurlencode($currentSlug);
        }
        $myDeliveriesUrl = company_url('deliveries/my_deliveries', $currentSlug !== '' ? $currentSlug : null);
        $myDlvSep = (strpos($myDeliveriesUrl, '?') !== false) ? '&' : '?';
        addItem($menuItems, 'my-deliveries', 'My Deliveries', 'clipboard', $myDeliveriesUrl . $myDlvSep . $dlvDashQs);
        addItem($menuItems, 'reviews', 'Reviews', 'star', $prefix . 'deliveries/customer_reviews.php?module=deliveries');
        break;

    case 'dispatch':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=dispatch');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=dispatch');
        }
        addItem($menuItems, 'dispatch-dashboard', 'Dashboard', 'speedometer2', $prefix . 'dispatch/index.php?module=dispatch');
        addItem($menuItems, 'dispatch', 'Dispatch Notes', 'truck', $prefix . 'dispatch/index.php?module=dispatch');
        addItem($menuItems, 'routes', 'Routes', 'map', $prefix . 'dispatch/routes.php?module=dispatch');
        addItem($menuItems, 'saved-routes', 'Saved Routes', 'table', $prefix . 'dispatch/saved_routes.php?module=dispatch');
        addItem($menuItems, 'office-trips', 'Office Trips', 'car-front', $prefix . 'dispatch/office_trips.php?module=dispatch');
        addItem($menuItems, 'records', 'Records & Report', 'file-earmark-text', $prefix . 'dispatch/records.php?module=dispatch');
        break;

    case 'voucher':
        $dashUrl = $is_admin ? 'admin/dashboard.php' : 'employee/dashboard.php';
        addItem($menuItems, 'dashboard', 'Dashboard', 'ticket-perforated', $prefix . $dashUrl . '?module=voucher');
        
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=voucher');
            addItem($menuItems, 'create', 'Create Voucher', 'file-plus', $prefix . 'employee/create-voucher.php?module=voucher');
            addItem($menuItems, 'all', 'View All', 'collection', $prefix . 'admin/all-vouchers.php?module=voucher');
            addItem($menuItems, 'users', 'Manage Users', 'people-fill', $prefix . 'admin/manage-users.php?module=voucher');
            addItem($menuItems, 'reports', 'Reports', 'bar-chart-line', $prefix . 'admin/reports.php?module=voucher');
            addItem($menuItems, 'payees', 'Manage Payees', 'person-lines-fill', $prefix . 'admin/manage-payees.php?module=voucher');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=voucher');
            addItem($menuItems, 'create', 'Create Voucher', 'file-plus', $prefix . 'employee/create-voucher.php?module=voucher');
            addItem($menuItems, 'create-payee', 'Manage Payees', 'person-lines-fill', $prefix . 'employee/manage-payees.php?module=voucher');
            addItem($menuItems, 'view', 'My Vouchers', 'ticket-detailed', $prefix . 'employee/my-vouchers.php?module=voucher');
            addItem($menuItems, 'bulk-upload', 'Upload', 'upload', $prefix . 'employee/bulk-upload-vouchers.php?module=voucher');
            addItem($menuItems, 'export', 'Download Excel', 'download', $prefix . 'export_confirm.php');
        }
        break;

    case 'attendance':
        addItem($menuItems, 'clock', 'Clock In/Out', 'clock', $attClockHref);
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=attendance');
            addItem($menuItems, 'view', 'View Records', 'calendar-check', $prefix . 'admin/view-attendance.php?module=attendance');
            $attSettingsHref = function_exists('company_url')
                ? company_url('attendance/settings.php', $currentSlug !== '' ? $currentSlug : null)
                : ($prefix . 'attendance/settings.php');
            $attSettingsSep = (strpos($attSettingsHref, '?') !== false) ? '&' : '?';
            addItem($menuItems, 'settings', 'Settings', 'gear', $attSettingsHref . $attSettingsSep . 'module=attendance');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=attendance');
            addItem($menuItems, 'stats', 'My Stats', 'graph-up-arrow', $prefix . 'employee/attendance-analytics.php?module=attendance');
        }
        break;

    case 'meetings':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=meetings');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=meetings');
        }
        $mtgUrl = $is_admin ? 'admin/meetings.php' : 'employee/meetings.php';
        addItem($menuItems, 'meetings', 'Meeting Room', 'camera-video', $prefix . $mtgUrl . '?module=meetings');
        break;

    case 'tasks':
        addItem($menuItems, 'py-performance', 'Performance', 'graph-up-arrow', $prefix . 'weekly_tasks/ai_assistant.php?module=tasks', null, 'py-performance');
        addItem($menuItems, 'dashboard', 'Dashboard', 'speedometer2', $prefix . 'weekly_tasks/dashboard.php?module=tasks');
        addItem($menuItems, 'leaderboard', 'Leaderboard', 'trophy', $prefix . 'weekly_tasks/leaderboard.php?module=tasks');
        addItem($menuItems, 'my-progress', 'My Progress', 'person-lines-fill', $prefix . 'weekly_tasks/my_progress.php?module=tasks');
        addItem($menuItems, 'reports', 'Reports', 'file-earmark-text', $prefix . 'weekly_tasks/reports.php?module=tasks');
        addItem($menuItems, 'settings', 'Settings', 'gear', $prefix . 'weekly_tasks/settings.php?module=tasks');
        break;

    case 'tracking':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=tracking');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=tracking');
        }
        addItem($menuItems, 'dashboard', 'Dashboard', 'search', $prefix . 'order-tracking/index.php?module=tracking');
        break;

    case 'outstanding':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=outstanding');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=outstanding');
        }
        addItem($menuItems, 'receivables', 'Receivables', 'cash', $prefix . 'erp/outstanding-invoices/index.php?module=outstanding&tab=receivables');
        addItem($menuItems, 'payables', 'Payables', 'credit-card', $prefix . 'erp/outstanding-invoices/index.php?module=outstanding&tab=payables');
        break;
        
     case 'revenue':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=revenue');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=revenue');
        }
        addItem($menuItems, 'revenue-list', 'Revenues', 'fas fa-coins', $prefix . 'revenue_entries.php?module=revenue', null, 'sidebar-revenue-link');
        addItem($menuItems, 'import', 'Import', 'upload', $prefix . 'revenue_import.php?module=revenue');
        addItem($menuItems, 'export-revenue', 'Export', 'download', $prefix . 'revenue_entries.php?module=revenue&open_export=1');
        addParentItem($menuItems, 'journal-parent', 'Journal', 'journal-text', [
            [
                'id' => 'journal-entries',
                'label' => 'Journal Entries',
                'icon' => 'journal-text',
                'path' => $prefix . 'accounting/journal-entries.php?module=revenue',
            ],
            [
                'id' => 'journal-config',
                'label' => 'Journal Configuration',
                'icon' => 'gear',
                'path' => $prefix . 'accounting/journal-configuration.php?module=revenue',
            ],
        ]);
        addParentItem($menuItems, 'credit-note-parent', 'Credit Note', 'file-earmark-text', [
            [
                'id' => 'credit-note-create',
                'label' => 'Create Credit Note',
                'icon' => 'plus-circle',
                'path' => $prefix . 'revenue_credit_note_create.php?module=revenue',
            ],
            [
                'id' => 'credit-note-list',
                'label' => 'Credit Note List',
                'icon' => 'list-ul',
                'path' => $prefix . 'revenue_credit_notes.php?module=revenue',
            ],
        ]);
        break;

     case 'expenses':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=expenses');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=expenses');
        }
        addItem($menuItems, 'dashboard', 'Expenses', 'bar-chart', $prefix . 'modules/expenses/index.php?module=expenses', $__expensesModuleUpdateBadge);
        addItem($menuItems, 'import', 'Import Expenses', 'upload', $prefix . 'modules/expenses/import.php?module=expenses');
        break;

    case 'petty_cash':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=petty_cash');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=petty_cash');
        }
        addItem($menuItems, 'dashboard', 'Petty Cash', 'wallet2', $prefix . 'modules/petty-cash/index.php?module=petty_cash');
        addItem($menuItems, 'pc-voucher', 'New Voucher', 'ticket-perforated', $prefix . 'modules/petty-cash/create-voucher.php?module=petty_cash');
        addItem($menuItems, 'pc-topup', 'Request Top-up', 'plus-circle', $prefix . 'modules/petty-cash/replenishments/index.php?module=petty_cash');
        break;

    case 'letters':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=letters');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=letters');
        }
        addItem($menuItems, 'write-letter', 'Write Letter', 'pencil-square', $prefix . 'write-letter.php');
        addItem($menuItems, 'letter-records', 'My Records', 'folder2-open', $prefix . 'letter-records.php');
        if($is_admin) {
            addItem($menuItems, 'manage-letters', 'Manage Letters', 'kanban', $prefix . 'manage-letters.php');
        }
        break;

    case 'todo':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=todo');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=todo');
        }
        addItem($menuItems, 'my-tasks', 'To Do List', 'list-check', $prefix . 'todo/index.php?module=todo');
        addItem($menuItems, 'weekly-mission', 'Weekly Mission', 'journal-check', $prefix . 'todo/weekly_mission.php?module=todo');
        break;

    case 'company-profile':
        addItem($menuItems, 'cp-overview', 'Overview', 'building', $prefix . 'modules/company-profile/index.php?module=company-profile');
        addItem($menuItems, 'cp-edit', 'Edit Profile', 'pencil-square', $prefix . 'modules/company-profile/create.php?module=company-profile');
        addItem($menuItems, 'cp-generate', 'Print Profile', 'file-earmark-text', $prefix . 'modules/company-profile/generate.php?module=company-profile');
        addItem($menuItems, 'cp-book', 'Profile Book PDF', 'book', $prefix . 'modules/company-profile/generate_book.php?module=company-profile');
        break;

    case 'email':
        $emailInboxBadge = null;
        try {
            if (!function_exists('email_module_pdo')) {
                $emailBootstrap = __DIR__ . '/modules/email/includes/email_bootstrap.php';
                if (is_file($emailBootstrap)) {
                    require_once $emailBootstrap;
                }
            }
            $emailDb = function_exists('email_module_pdo') ? email_module_pdo() : null;
            if ($emailDb instanceof PDO) {
                $uid = (int) ($_SESSION['user_id'] ?? 0);
                $st = $emailDb->prepare("SELECT COUNT(*) FROM module_emails WHERE (user_id = ? OR user_id = 0) AND direction = 'inbound' AND status NOT IN ('trash','archived','spam')");
                $st->execute([$uid]);
                $inboxN = (int) $st->fetchColumn();
                if ($inboxN > 0) {
                    $emailInboxBadge = (string) $inboxN;
                }
            }
        } catch (Throwable $e) {
            $emailInboxBadge = null;
        }
        $emailBase = $prefix . 'modules/email/index.php?module=email&folder=';
        addItem($menuItems, 'email-inbox', 'Inbox', 'inbox', $emailBase . 'inbox', $emailInboxBadge);
        addItem($menuItems, 'email-starred', 'Starred', 'star', $emailBase . 'starred');
        addItem($menuItems, 'email-sent', 'Sent', 'send', $emailBase . 'sent');
        addItem($menuItems, 'email-drafts', 'Drafts', 'file-earmark', $emailBase . 'drafts');
        addItem($menuItems, 'email-archive', 'Archive', 'archive', $emailBase . 'archive');
        addItem($menuItems, 'email-spam', 'Spam', 'exclamation-triangle', $emailBase . 'spam');
        addItem($menuItems, 'email-trash', 'Trash', 'trash', $emailBase . 'trash');
        break;

    case 'company-settings':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=company-settings');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=company-settings');
        }
        $csCompanyId = (int) ($_GET['company_id'] ?? ($_SESSION['active_company_id'] ?? (function_exists('currentCompanyId') ? (int) currentCompanyId() : 0)));
        $csSlug = trim((string) ($_GET['company_slug'] ?? $_SESSION['company_slug'] ?? ''));
        if ($csCompanyId <= 0 && $csSlug !== '') {
            global $control_pdo;
            if (isset($control_pdo) && $control_pdo instanceof PDO) {
                try {
                    $csSlugStmt = $control_pdo->prepare('SELECT id FROM companies WHERE company_slug = ? LIMIT 1');
                    $csSlugStmt->execute([$csSlug]);
                    $csCompanyId = (int) $csSlugStmt->fetchColumn();
                } catch (Throwable $e) {
                    // keep sidebar resilient
                }
            }
        }
        $csModule = trim((string) ($_GET['module'] ?? 'settings'));
        $csHubQs = 'module=settings';
        if ($csCompanyId > 0) {
            $csHubQs .= '&company_id=' . $csCompanyId;
        }
        if ($csSlug !== '') {
            $csHubQs .= '&company_slug=' . rawurlencode($csSlug);
        }
        $csBuildUrl = static function (string $tab) use ($prefix, $csCompanyId, $csSlug, $csModule): string {
            $params = [];
            if ($csCompanyId > 0) {
                $params['company_id'] = $csCompanyId;
            }
            if ($csSlug !== '') {
                $params['company_slug'] = $csSlug;
            }
            if ($csModule !== '') {
                $params['module'] = $csModule;
            }
            $params['tab'] = $tab;
            return $prefix . 'admin/company-settings.php?' . http_build_query($params);
        };
        addItem($menuItems, 'cs-hub', 'Settings hub', 'grid', $prefix . 'admin/settings.php?' . $csHubQs);
        addItem($menuItems, 'cs-profile', 'Profile', 'building', $csBuildUrl('profile'));
        addItem($menuItems, 'cs-branding', 'Branding', 'palette', $csBuildUrl('branding'));
        addItem($menuItems, 'cs-finance', 'Tax & Finance', 'cash-coin', $csBuildUrl('finance'));
        addItem($menuItems, 'cs-modules', 'Modules', 'grid-3x3-gap', $csBuildUrl('modules'));
        addItem($menuItems, 'cs-numbering', 'Document Numbering', 'hash', $csBuildUrl('numbering'));
        addItem($menuItems, 'cs-employees', 'Employee Registration', 'person-plus', $csBuildUrl('employees'));
        addItem($menuItems, 'cs-security', 'Security', 'shield-lock', $csBuildUrl('security'));
        addItem($menuItems, 'cs-danger', 'Danger Zone', 'exclamation-octagon', $csBuildUrl('danger'), null, 'text-danger');
        break;

    case 'settings':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=settings');
            addItem($menuItems, 'settings-hub', 'Settings Hub', 'grid', $prefix . 'admin/settings.php?module=settings');
            $csSidebarQs = ['module' => 'settings', 'tab' => 'profile'];
            $csSidebarCid = (int) (function_exists('currentCompanyId') ? currentCompanyId() : ($_SESSION['active_company_id'] ?? 0));
            if ($csSidebarCid > 0) {
                $csSidebarQs['company_id'] = $csSidebarCid;
            }
            $csSidebarSlug = trim((string) ($_SESSION['company_slug'] ?? ''));
            if ($csSidebarSlug !== '') {
                $csSidebarQs['company_slug'] = $csSidebarSlug;
            }
            addItem($menuItems, 'company-settings', 'Company Settings', 'building', $prefix . 'admin/company-settings.php?' . http_build_query($csSidebarQs));
            addItem($menuItems, 'sys-wa', 'WhatsApp Config', 'whatsapp', $prefix . 'admin/whatsapp-settings.php?module=settings');
            addItem($menuItems, 'sys-time', 'Time & Format', 'clock', $prefix . 'admin/time-settings.php?module=settings');
            addItem($menuItems, 'att-settings', 'Attendance Config', 'calendar-check', $prefix . 'attendance/settings.php?module=settings');
        }
        break;

    case 'account':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=account');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=account');
        }
        $accountUrl = function_exists('user_profile_settings_url') ? user_profile_settings_url($prefix, $active_module ?? null) : ($prefix . 'employee/account.php');
        addItem($menuItems, 'sys-settings', 'System Settings', 'gear', $prefix . 'employee/system-settings.php?module=account');
        addItem($menuItems, 'profile', 'Profile Settings', 'person-badge', $accountUrl);
        break;

    case 'personalization':
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php?module=personalization');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php?module=personalization');
        }
        break;

    default:
        $dashUrl = $is_admin ? 'admin/dashboard.php' : 'employee/dashboard.php';
        addItem($menuItems, 'dashboard', 'Dashboard', 'house', $prefix . $dashUrl);
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'admin/ai_assistant.php');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'stars', $prefix . 'employee/ai_assistant.php');
            addItem($menuItems, 'create-voucher', 'Create Voucher', 'ticket-perforated', $prefix . 'employee/create-voucher.php');
        }
        break;
}
}
}

// Global Links — individual profile settings (not company billing my-account.php)
$accountUrl = function_exists('user_profile_settings_url') ? user_profile_settings_url($prefix, $active_module ?? null) : ($prefix . 'employee/account.php');
$personalizationIndexUrl = $prefix . 'employee/personalization/index.php?module=personalization';
if ($active_module !== 'todo' && $active_module !== 'analytics') {
    addItem($menuItems, 'personalization', 'Personalization', 'palette', $personalizationIndexUrl);
    addItem($menuItems, 'account', 'Profile Settings', 'person', $accountUrl);
}

// 4. Global Shortcuts (Optional)
// WhatsApp Group shortcut removed per user request


// Theme Switching Logic
if (isset($_GET['set_theme'])) {
    $theme = $_GET['set_theme'];
    $valid_themes = [
        'default', 
        'theme-minimal',
        'theme-deep-ocean', 
        'theme-clean-white', 
        'theme-night-mode',
        'theme-royal-purple',
        'theme-professional',
        'theme-dark-mode-v2',
        'theme-cool'
    ];
    if (in_array($theme, $valid_themes)) {
        $_SESSION['theme'] = $theme;
    }
}
$current_theme = $_SESSION['theme'] ?? 'theme-minimal';

// Helper for Active State (Robust)
if (!function_exists('isSidebarActive')) {
    function isSidebarActive($path, $current_page) {
        $current_uri = $_SERVER['REQUEST_URI'] ?? '';
        $linkParts = parse_url($path);
        $path_parsed = $linkParts['path'] ?? '';
        $linkQuery = isset($linkParts['query']) ? (string) $linkParts['query'] : '';

        $curParts = parse_url($current_uri);
        $curPath = $curParts['path'] ?? '';

        $normPath = static function ($path) {
            $path = rtrim(str_replace('\\', '/', (string) $path), '/');
            return (string) preg_replace('#/(index)?\.php$#', '', $path);
        };

        // Links with a query string: require same path and matching query keys (e.g. uploads ?folder=&images=)
        if ($linkQuery !== '') {
            if ($path_parsed === '' || ($path_parsed !== $curPath && $normPath($path_parsed) !== $normPath($curPath))) {
                return '';
            }
            parse_str($linkQuery, $want);
            parse_str($curParts['query'] ?? '', $have);
            foreach ($want as $k => $v) {
                if (!isset($have[$k]) || (string) $have[$k] !== (string) $v) {
                    return '';
                }
            }
            return 'active';
        }

        // 1. Exact Match (path only; current URI may include unrelated query params)
        if ($path_parsed === $curPath || $path === $current_uri || $normPath($path_parsed) === $normPath($curPath)) {
            return 'active';
        }

        // 2. Same-directory, exact file only (never treat every /products/*.php as active on index.php)
        $normItemPath = str_replace('\\', '/', (string) $path_parsed);
        $normCurPath = str_replace('\\', '/', (string) $curPath);
        $item_dir = dirname($normItemPath);
        $item_base = basename($normItemPath);
        if ($item_base !== '' && $item_base !== 'index.php' && dirname($normCurPath) === $item_dir && basename($normCurPath) === $item_base) {
            return 'active';
        }

        return '';
    }
}

/**
 * AUTO-CLOSE LAYOUT WRAPPER 
 * Ensures the <div class="d-flex"> and <div class="flex-grow-1"> 
 * started in the headers are closed at the end of the page.
 */
if (!isset($_GET['print'])) {
    register_shutdown_function(function() {
        echo "\n    </div> <!-- /content-wrapper -->\n</div> <!-- /main-flex-wrapper -->\n";
    });
}
?>

<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Theme Styles -->
<link href="<?= app_url('assets/css/sidebar_themes.css') ?>" rel="stylesheet">

<!-- Bootstrap 5 Sidebar CSS -->
<style>
    :root {
        --sidebar-width: 250px;
        --sidebar-collapsed-width: 70px;
         /* Fallbacks if theme CSS fails to load */
        --sidebar-bg: transparent;
        --sidebar-text: #212529;
        --sidebar-nav-purple: #7c3aed;
    }

    /* Global Reset for Flex Layout */
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        height: 100% !important;
        overflow-x: hidden;
    }

    /* Flex Layout Core */
    .layout-main-wrapper {
        display: flex !important;
        justify-content: flex-start !important;
        align-items: stretch !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        gap: 0 !important;
        min-height: 100vh;
    }

    /* Sidebar uses system font from Settings */
    #native-sidebar {
        font-family: var(--erp-font-family, 'Poppins', sans-serif);
    }

    /* 1. MASTER WRAPPERS - Remove legacy offsets & Force Left */
    html body .main-content, 
    html body .content-wrapper, 
    html body main,
    html body .header,
    html body .admin-header,
    html body .employee-header {
        margin-left: 0 !important;
        margin-right: 0px !important;
        padding-left: 0px !important;
        padding-right: 0px !important;
        width: 100% !important;
        max-width: none !important;
        position: relative !important;
        transition: none !important;
        box-sizing: border-box !important;
        display: block; /* Removed !important to allow modals/hidden elements to work */
    }

    /* Ensure modals don't take up space when closed */
    .modal:not(.show) {
        display: none !important;
    }
    
    /* 2. INNER CONTAINERS - Force full width and left alignment */
    html body .container,
    html body .main-container,
    html body .header-content,
    html body .header-inner,
    html body .footer-content,
    html body .container-fluid,
    html body .form-container {
        margin-left: 0 !important;
        margin-right: 0 !important;
        max-width: none !important;
        width: 100% !important;
        padding-left: 20px !important; /* Standard breathing room */
        padding-right: 20px !important;
        box-sizing: border-box !important;
        display: block !important; /* Override flex if it was centering */
    }

    /* Collapsed State Overrides */
    body.sidebar-collapsed .main-content, 
    body.sidebar-collapsed .content-wrapper, 
    body.sidebar-collapsed main,
    body.sidebar-collapsed .header,
    body.sidebar-collapsed .admin-header,
    body.sidebar-collapsed .employee-header,
    body.sidebar-collapsed.dashboard .main-content {
        margin-left: 0 !important;
    }

    @media (max-width: 992px) {
        /* Mobile Reset */
        html body .main-content, 
        html body .content-wrapper, 
        html body main, 
        html body .header, 
        html body .admin-header, 
        html body .employee-header {
            margin-left: 0 !important;
        }
        html body .container, 
        html body .main-container, 
        html body .header-content {
             padding-left: 10px !important;
        }
    }

    /* Overlay for Mobile */
    #sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0,0,0,0.4);
        z-index: 1035;
        display: none;
    }
    
    #sidebar-overlay.show {
        display: block;
    }

    /* The Sidebar Itself */
    #native-sidebar {
        width: var(--sidebar-width);
        height: 100vh; /* Changed from min-height to height to enforce viewport constraints */
        position: sticky;
        top: 0;
        z-index: 1040;
        /* Background and colors controlled by sidebar_themes.css */
        display: flex;
        flex-direction: column;
        padding: 1rem 0.75rem; /* Better breathing room */
        transition: width 0.3s ease;
        overflow-y: auto; /* Scroll internally if menu is long */
        overflow-x: hidden;
        flex-shrink: 0; /* Prevent shrinking in flex container */
        scrollbar-width: none; /* Firefox: hide scrollbar, keep scroll */
        -ms-overflow-style: none; /* IE/legacy Edge */
    }
    #native-sidebar::-webkit-scrollbar {
        width: 0;
        height: 0;
        display: none;
    }
    
    /* Collapsed Sidebar (Driven by Body Class) */
    body.sidebar-collapsed #native-sidebar {
        width: var(--sidebar-collapsed-width);
    }
    
    body.sidebar-collapsed #native-sidebar .sidebar-text,
    body.sidebar-collapsed #native-sidebar .text-muted, 
    body.sidebar-collapsed #native-sidebar .badge {
        display: none !important;
    }
    
    body.sidebar-collapsed #native-sidebar .nav-link {
        justify-content: center;
        padding-left: 0 !important;
    }
    
    body.sidebar-collapsed #native-sidebar .nav-link i {
        margin-right: 0 !important;
        font-size: 1.5rem;
    }
    
    /* Mobile offcanvas behavior */
    @media (max-width: 992px) {
        #native-sidebar {
            position: fixed;
            height: 100vh;
            transform: translateX(-100%);
            width: 260px;
            transition: transform 0.3s ease-in-out;
        }
        #native-sidebar.show {
            transform: translateX(0);
        }
        /* Force sidebar text visibility on mobile even if collapsed on desktop */
        #native-sidebar.show .sidebar-text,
        #native-sidebar.show .text-muted,
        #native-sidebar.show .badge {
            display: inline-block !important;
        }
    }

    /* Links */
    .nav-pills .nav-link {
        color: var(--sidebar-text);
        margin-bottom: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 500;
        white-space: nowrap; /* Prevent wrapping */
        font-size: 0.95rem;
        text-decoration: none !important;
    }
    
    /* Sidebar edge — border from Appearance theme (sidebar_themes.css variables) */
    #native-sidebar {
        border-right: 1px solid var(--sidebar-border, rgba(0, 0, 0, 0.1));
        box-shadow: none;
    }
    
    .nav-pills .nav-link:not(.text-danger):hover {
        background-color: transparent !important;
        color: var(--sidebar-nav-purple, #7c3aed) !important;
        box-shadow: none !important;
    }
    .nav-pills .nav-link:not(.text-danger):hover i {
        color: var(--sidebar-nav-purple, #7c3aed) !important;
    }
    .nav-pills .nav-link.active:not(.text-danger) {
        background-color: transparent !important;
        color: var(--sidebar-nav-purple, #7c3aed) !important;
        box-shadow: none !important;
    }
    .nav-pills .nav-link.active:not(.text-danger) i {
        color: var(--sidebar-nav-purple, #7c3aed) !important;
    }
    .sidebar-parent-toggle.is-open {
        color: var(--sidebar-nav-purple, #7c3aed) !important;
    }
    .sidebar-parent-toggle.is-open i {
        color: var(--sidebar-nav-purple, #7c3aed) !important;
    }
    .nav-pills .nav-link i {
        font-size: 1.1rem;
        min-width: 24px; /* Ensure icon stability */
        text-align: center;
    }
    .exp-module-update-badge {
        display: inline-flex;
        align-items: center;
        margin-left: auto;
        padding: 0.12rem 0.45rem;
        border-radius: 9999px;
        font-size: 0.58rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #fff;
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        box-shadow: 0 1px 2px rgba(124, 58, 237, 0.28);
        line-height: 1.2;
        flex-shrink: 0;
    }
    .sidebar-coming-soon-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-left: auto;
        padding: 0.12rem 0.4rem;
        border-radius: 9999px;
        font-size: 0.55rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #4f46e5;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        line-height: 1;
        flex: 0 0 auto;
        white-space: nowrap;
        max-width: none;
    }
    #native-sidebar .nav-link {
        gap: 0.35rem;
        min-width: 0;
        overflow: visible;
        text-decoration: none !important;
    }
    #native-sidebar a,
    #native-sidebar a:hover,
    #native-sidebar a:focus,
    #native-sidebar a:active,
    #native-sidebar a:visited,
    #native-sidebar .nav-link,
    #native-sidebar .nav-link:hover,
    #native-sidebar .nav-link:focus,
    #native-sidebar .nav-link.active,
    #native-sidebar .user-profile-link,
    #native-sidebar .sidebar-text {
        text-decoration: none !important;
    }
    #native-sidebar .nav-link .sidebar-text {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-decoration: none !important;
    }
    #native-sidebar {
        overflow-x: hidden !important;
    }
    #native-sidebar .nav,
    #native-sidebar .nav-item,
    #native-sidebar .nav-pills {
        max-width: 100%;
        min-width: 0;
    }
    #native-sidebar .nav-link {
        display: flex !important;
        align-items: center;
        gap: 0.5rem;
        max-width: 100%;
        min-width: 0;
        overflow: hidden;
        box-sizing: border-box;
        padding-right: 0.65rem !important;
    }
    #native-sidebar .nav-link > i,
    #native-sidebar .nav-link > svg,
    #native-sidebar .nav-link .bi {
        flex: 0 0 auto;
    }
    #native-sidebar .sidebar-submenu {
        max-width: 100%;
        padding-right: 0.5rem;
        padding-left: 1.15rem;
        box-sizing: border-box;
        overflow: hidden;
    }
    #native-sidebar .sidebar-submenu .nav-link {
        font-size: 0.84rem;
        line-height: 1.25;
        padding-right: 0.45rem !important;
        padding-left: 0.35rem !important;
    }
    #native-sidebar .sidebar-submenu .sidebar-text {
        flex: 1 1 auto;
        min-width: 0;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    /* Keep hover slide from pushing labels past the sidebar edge */
    #native-sidebar .nav-pills .nav-link:hover {
        padding-left: 1rem !important;
    }
    #native-sidebar .sidebar-submenu .nav-link:hover {
        padding-left: 0.35rem !important;
    }
    #native-sidebar .sidebar-submenu .sidebar-text {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    body.sidebar-collapsed .exp-module-update-badge,
    body.sidebar-collapsed .sidebar-coming-soon-badge {
        display: none;
    }
    /* Icon-only mode: hide expand chevrons so parent items stay centered */
    body.sidebar-collapsed #native-sidebar .submenu-chevron,
    body.sidebar-collapsed #native-sidebar .submenu-chevron-btn {
        display: none !important;
    }
    body.sidebar-collapsed #native-sidebar .sidebar-submenu,
    body.sidebar-collapsed #native-sidebar .sidebar-submenu-nested {
        display: none !important;
    }
    body.sidebar-collapsed #native-sidebar .sidebar-parent-toggle,
    body.sidebar-collapsed #native-sidebar .sidebar-child-parent-toggle {
        justify-content: center !important;
        gap: 0 !important;
        padding-left: 0 !important;
        padding-right: 0 !important;
    }
    .sidebar-submenu {
        list-style: none;
        margin: 0.2rem 0 0.45rem;
        padding: 0 0.4rem 0 1.35rem;
    }
    .sidebar-submenu.is-collapsed { display: none; }
    .sidebar-submenu .nav-link {
        font-size: 0.95rem;
        padding-top: 0.4rem;
        padding-bottom: 0.4rem;
        opacity: 0.94;
    }
    .sidebar-submenu .nav-link i {
        font-size: 1rem;
        min-width: 20px;
    }
    .sidebar-nested-group {
        margin-bottom: 0.15rem;
    }
    .sidebar-submenu-nested {
        list-style: none;
        margin: 0.15rem 0 0.4rem 1.65rem;
        padding: 0.2rem 0 0.2rem 0.65rem;
        border-left: 2px solid rgba(124, 58, 237, 0.22);
    }
    .sidebar-submenu-nested.is-collapsed {
        display: none;
    }
    .sidebar-child-parent-toggle {
        cursor: pointer;
    }
    .sidebar-child-parent-toggle .submenu-chevron {
        margin-left: auto;
        font-size: 0.8rem;
        transition: transform 0.2s ease;
    }
    .sidebar-child-parent-toggle.is-open .submenu-chevron {
        transform: rotate(90deg);
    }
    .sidebar-submenu-nested .nav-link.sidebar-nested-under-category {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        font-size: 0.8125rem;
        font-weight: 500;
        padding: 0.38rem 0.35rem 0.38rem 0;
        color: #64748b !important;
        opacity: 1;
        line-height: 1.3;
    }
    .sidebar-submenu-nested .nav-link.sidebar-nested-under-category:hover {
        color: #7c3aed !important;
        background: rgba(124, 58, 237, 0.06) !important;
        border-radius: 6px;
    }
    .sidebar-submenu-nested .nav-link.sidebar-nested-under-category.active {
        color: #7c3aed !important;
        font-weight: 600;
        background: rgba(124, 58, 237, 0.08) !important;
        border-radius: 6px;
    }
    .sidebar-nested-dot {
        display: inline-block;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #7c3aed;
        flex-shrink: 0;
        opacity: 0.75;
    }
    .sidebar-submenu-nested .nav-link.sidebar-nested-under-category:hover .sidebar-nested-dot,
    .sidebar-submenu-nested .nav-link.sidebar-nested-under-category.active .sidebar-nested-dot {
        opacity: 1;
    }
    .sidebar-submenu-nested .nav-link i {
        display: none;
    }
    .sidebar-parent-toggle .submenu-chevron {
        margin-left: auto;
        font-size: 0.8rem;
        transition: transform 0.2s ease;
    }
    .sidebar-parent-toggle.is-open .submenu-chevron {
        transform: rotate(90deg);
    }
    

    /* Sidebar header: logo only (no company name label beside it) */
    .sidebar-header .logo-container > .sidebar-text {
        display: none !important;
    }

    /* Header Collapse Logic */
    body.sidebar-collapsed .sidebar-header .logo-container {
        display: none !important;
    }
    
    body.sidebar-collapsed .sidebar-toggle-icon {
        display: inline-flex !important;
        margin: 0 auto;
        text-align: center;
        width: 1.55rem;
        font-size: inherit !important;
    }
    
    body.sidebar-collapsed .sidebar-collapse-btn {
        display: none !important;
    }
    
    body.sidebar-collapsed .sidebar-header {
        justify-content: center !important;
        padding: 0 !important;
        margin-bottom: 1rem !important;
    }

    /* Hamburger: full / short / full — always visible on desktop sidebar */
    .erp-hamburger {
        display: inline-flex;
        flex-direction: column;
        justify-content: center;
        align-items: flex-start;
        gap: 5px;
        width: 1.45rem;
        height: 1.15rem;
        color: var(--sidebar-text, #212529);
        flex-shrink: 0;
        pointer-events: none;
        box-sizing: border-box;
    }
    .erp-hamburger > span {
        display: block !important;
        width: 100%;
        height: 1.75px;
        border-radius: 9999px;
        background: var(--sidebar-text, #212529) !important;
        opacity: 1 !important;
    }
    .erp-hamburger > span:nth-child(2) {
        width: 55%;
    }
    /* Collapsed-only control — hidden when sidebar is expanded */
    .sidebar-toggle-icon.erp-hamburger {
        display: none !important;
        cursor: pointer;
        pointer-events: auto;
        color: var(--sidebar-text, #212529);
    }
    body.sidebar-collapsed .sidebar-toggle-icon.erp-hamburger {
        display: inline-flex !important;
        width: 1.55rem;
        height: 1.25rem;
        gap: 6px;
        margin: 0 auto;
    }
    .sidebar-collapse-btn {
        display: none !important;
        align-items: center;
        justify-content: center;
        line-height: 1;
        text-decoration: none !important;
        color: var(--sidebar-text, #212529) !important;
        box-shadow: none !important;
        opacity: 1 !important;
        min-width: 2rem;
        min-height: 2rem;
    }
    .sidebar-collapse-btn .erp-hamburger {
        display: inline-flex !important;
        color: var(--sidebar-text, #212529) !important;
    }
    .sidebar-collapse-btn .erp-hamburger > span {
        background: var(--sidebar-text, #212529) !important;
    }
    .sidebar-collapse-btn:hover,
    .sidebar-collapse-btn:focus {
        color: var(--sidebar-text, #212529) !important;
        opacity: 0.75 !important;
    }

    html[data-theme="dark"] .erp-hamburger,
    html[data-theme="dark"] .sidebar-collapse-btn,
    html[data-theme="dark"] .sidebar-toggle-icon.erp-hamburger {
        color: #fde047 !important;
    }

    html[data-theme="dark"] .erp-hamburger > span,
    html[data-theme="dark"] .sidebar-collapse-btn .erp-hamburger > span,
    html[data-theme="dark"] .sidebar-toggle-icon.erp-hamburger > span {
        background: #fde047 !important;
    }

    @media (min-width: 992px) {
        body:not(.sidebar-collapsed) .sidebar-collapse-btn {
            display: inline-flex !important;
        }
    }

    /* Primary Download Button Style */
    .nav-pills .nav-link.btn-download-special {
        margin-top: 10px;
        transition: all 0.3s ease;
    }
    .nav-pills .nav-link.btn-download-special:hover {
        background-color: transparent !important;
        color: var(--sidebar-nav-purple, #7c3aed) !important;
        box-shadow: none !important;
    }
    .nav-pills .nav-link.btn-download-special.active {
        background-color: transparent !important;
        color: var(--sidebar-nav-purple, #7c3aed) !important;
        box-shadow: none !important;
    }

    /* Mobile: move Logout under Appearance */
    .sidebar-logout-mobile { display: none; }
    @media (max-width: 992px) {
        .sidebar-logout-mobile { display: block; }
        .sidebar-logout-footer { display: none; }
    }
    /* Revenue module icon — matches select-module.php */
    #native-sidebar .nav-link.sidebar-revenue-link > i.fa-coins {
        color: #d97706;
    }
    #native-sidebar .nav-link.sidebar-revenue-link.active > i.fa-coins,
    #native-sidebar .nav-link.sidebar-revenue-link:hover > i.fa-coins {
        color: #d97706;
    }

    #native-sidebar .nav-link.py-performance > i {
        color: #6366f1;
    }
    #native-sidebar .nav-link.py-performance.active > i,
    #native-sidebar .nav-link.py-performance:hover > i {
        color: #4f46e5;
    }
</style>

<!-- Load Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

<!-- Sidebar HTML -->
<div id="sidebar-overlay" onclick="toggleNativeSidebar()"></div>

<nav id="native-sidebar" class="<?= htmlspecialchars($current_theme) ?>">
    <!-- Header with Shrink Toggle -->
    <div class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-decoration-none justify-content-between w-100 sidebar-header">
        <div class="d-flex align-items-center logo-container" style="gap: 10px;">
            <?php if ($sidebarCompanyLogoUrl !== ''): ?>
                <div style="max-height: 50px; max-width: 180px; display: flex; align-items: center; justify-content: flex-start; overflow: hidden; flex-shrink: 0;">
                    <img src="<?= htmlspecialchars($sidebarCompanyLogoUrl) ?>" alt="Company Logo" style="max-height: 45px; max-width: 100%; object-fit: contain;">
                </div>
            <?php else: ?>
                <div style="background: #5c59f0; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 13px; font-weight: 700; flex-shrink: 0;">
                    <?= htmlspecialchars($sidebarCompanyInitial) ?>
                </div>
            <?php endif; ?>
        </div>
        <!-- Hamburger Icon for Collapsed State -->
        <span class="erp-hamburger sidebar-toggle-icon cursor-pointer" onclick="toggleSidebarCollapse()" role="button" tabindex="0" aria-label="Expand sidebar" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleSidebarCollapse();}">
            <span></span><span></span><span></span>
        </span>
        
        <!-- Standard Toggle Button (Hidden in collapsed) -->
        <button type="button" class="btn btn-sm btn-link p-0 sidebar-collapse-btn" onclick="toggleSidebarCollapse()" aria-label="Collapse sidebar">
            <span class="erp-hamburger" aria-hidden="true"><span></span><span></span><span></span></span>
        </button>
    </div>
    <hr style="border-color: var(--sidebar-border)">
    
    <!-- User Info (Clickable) -->
    <?php 
    $accountLink = function_exists('user_profile_settings_url') ? user_profile_settings_url($prefix, $active_module ?? null) : ($prefix . 'employee/account.php');
    $sidebarUserPhotoUrl = '';
    if (isset($_SESSION['user_id']) && isset($pdo)) {
        try {
            $stmtUser = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
            $stmtUser->execute([(int)$_SESSION['user_id']]);
            $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
            if ($userData && !empty($userData['profile_photo'])) {
                $avatarHelper = __DIR__ . '/includes/user-avatar.php';
                if (is_file($avatarHelper)) {
                    require_once $avatarHelper;
                }
                if (function_exists('user_avatar_photo_url')) {
                    $sidebarUserPhotoUrl = user_avatar_photo_url($userData['profile_photo']);
                }
                if (empty($sidebarUserPhotoUrl)) {
                    $sidebarUserPhotoUrl = $prefix . ltrim(str_replace('\\', '/', $userData['profile_photo']), '/');
                }
            }
        } catch (Throwable $e) {
            // Fallback
        }
    }
    ?>
    <a href="<?= $accountLink ?>" class="d-flex align-items-center mb-3 px-2 text-decoration-none user-profile-link" style="color: inherit; gap: 12px;">
        <div style="background: #e2e8f0; color: #475569; width: 36px !important; height: 36px !important; min-width: 36px !important; min-height: 36px !important; max-width: 36px !important; max-height: 36px !important; border-radius: 50% !important; aspect-ratio: 1/1 !important; display: flex !important; align-items: center !important; justify-content: center !important; font-size: 18px; border: 1.5px solid var(--sidebar-border, rgba(0, 0, 0, 0.1)) !important; flex-shrink: 0 !important; position: relative !important; overflow: hidden !important;">
            <i class="bi bi-person-fill"></i>
            <?php if (!empty($sidebarUserPhotoUrl)): ?>
                <img src="<?= htmlspecialchars($sidebarUserPhotoUrl) ?>" alt="Profile" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50% !important;" onerror="this.remove();">
            <?php endif; ?>
        </div>
        <div class="sidebar-text">
             <div style="font-weight: 700; font-size: 13px; color: #1e293b; line-height: 1.2;"><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></div>
             <div style="font-size: 11px; font-weight: 500; color: #5c59f0;"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['role'] ?? 'Staff'))) ?></div>
        </div>
    </a>
    <style>
        .user-profile-link:hover {
            background-color: transparent;
            border-radius: 8px;
        }
    </style>
    
    <!-- MAIN Section Label -->
    <?php $sidebarSectionLabel = ($active_module === 'company-settings') ? 'Company Settings' : 'Main'; ?>
    <div class="px-3 mb-2 small fw-bold text-muted sidebar-text" style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.5;">
        <?= htmlspecialchars($sidebarSectionLabel) ?>
    </div>

    <ul class="nav nav-pills flex-column mb-auto">
        <?php foreach ($menuItems as $item):
            if ($item['id'] === 'account') continue; // Move to Account section later
            if ($active_module === 'todo' && $item['id'] === 'dashboard') continue;
            
            $hasChildren = !empty($item['children']) && is_array($item['children']);
            $parentHubPath = trim((string) ($item['path'] ?? '#'));
            $parentHasHub = $hasChildren && $parentHubPath !== '' && $parentHubPath !== '#';
            $isActive = '';
            if ($hasChildren) {
                if ($parentHasHub && isSidebarActive($parentHubPath, $current_page) === 'active') {
                    $isActive = 'active';
                }
                foreach ($item['children'] as $childItem) {
                    if (isSidebarActive($childItem['path'] ?? '', $current_page) === 'active') {
                        $isActive = 'active';
                        break;
                    }
                    if (!empty($childItem['children']) && is_array($childItem['children'])) {
                        foreach ($childItem['children'] as $nestedItem) {
                            if (isSidebarActive($nestedItem['path'] ?? '', $current_page) === 'active') {
                                $isActive = 'active';
                                break 2;
                            }
                        }
                    }
                }
            } else {
                $isActive = isSidebarActive($item['path'], $current_page);
                if ($item['id'] === 'personalization') {
                    $persPath = str_replace('\\', '/', $script_name);
                    if (strpos($persPath, '/employee/personalization/') !== false) {
                        $isActive = 'active';
                    }
                }
            }
            if ($active_module === 'crm' && in_array($item['id'], ['dashboard', 'my-customers', 'new-leads', 'crm-market'], true)) {
                $crmScript = str_replace('\\', '/', (string) $script_name);
                $crmView = strtolower(trim((string) ($_GET['view'] ?? '')));
                if (strpos($crmScript, '/modules/crm/market/') !== false && $crmView === 'new-leads') {
                    $crmActiveId = 'new-leads';
                } elseif (strpos($crmScript, '/modules/crm/market/') !== false) {
                    $crmActiveId = 'crm-market';
                } elseif (strpos($crmScript, '/modules/crm/my-clients/view.php') !== false
                    || ((string) ($_GET['tab'] ?? '')) === 'customers') {
                    $crmActiveId = 'my-customers';
                } else {
                    $crmTab = strtolower(trim((string) ($_GET['tab'] ?? 'dashboard')));
                    $crmActiveId = ($crmTab === 'customers') ? 'my-customers' : 'dashboard';
                }
                $isActive = ($item['id'] === $crmActiveId) ? 'active' : '';
            }
            $icon = $item['icon'];
            $attr = '';
            if ($item['id'] === 'theme' || $item['label'] === 'Theme' || $item['label'] === 'Theme Settings') {
                $item['path'] = '#';
                $attr = 'onclick="openThemeModal(); return false;"';
                $icon = 'palette';
            }
        ?>
            <li class="nav-item">
                <a href="<?= $parentHasHub ? htmlspecialchars($parentHubPath) : ($hasChildren ? '#' : $item['path']) ?>" class="nav-link d-flex align-items-center w-100 <?= $isActive ?> <?= htmlspecialchars((string) ($item['linkClass'] ?? '')) ?> <?= ($item['id'] === 'export' ? 'btn-download-special' : '') ?><?= $hasChildren ? ' sidebar-parent-toggle' : '' ?><?= ($hasChildren && $isActive === 'active') ? ' is-open' : '' ?>" <?= ($hasChildren && !$parentHasHub) ? 'onclick="toggleSidebarSubmenu(this); return false;"' : $attr ?> title="<?= htmlspecialchars($item['label']) ?>">
                    <?= sidebar_render_nav_icon((string) $icon) ?>
                    <span class="sidebar-text flex-grow-1 text-start"><?= htmlspecialchars($item['label']) ?></span>
                    <?php
                    if (!empty($item['badge'])) {
                        if (is_string($item['badge'])) {
                            $badgeLabel = (string) $item['badge'];
                            $badgeTitle = (stripos($badgeLabel, 'soon') !== false) ? 'Coming soon' : $badgeLabel;
                            echo '<span class="sidebar-coming-soon-badge" title="' . htmlspecialchars($badgeTitle) . '">' . htmlspecialchars($badgeLabel) . '</span>';
                        } elseif (is_array($item['badge']) && function_exists('expenses_module_update_badge_html')) {
                            echo expenses_module_update_badge_html($item['badge']);
                        }
                    }
                    ?>
                    <?php if ($hasChildren): ?>
                        <?php if ($parentHasHub): ?>
                            <button type="button" class="submenu-chevron-btn flex-shrink-0 border-0 bg-transparent p-0 ms-1" aria-label="Toggle Personalization menu" onclick="event.preventDefault(); event.stopPropagation(); toggleSidebarSubmenu(this.closest('a.nav-link')); return false;">
                                <i class="bi bi-chevron-right submenu-chevron" aria-hidden="true"></i>
                            </button>
                        <?php else: ?>
                            <i class="bi bi-chevron-right submenu-chevron flex-shrink-0" aria-hidden="true"></i>
                        <?php endif; ?>
                    <?php endif; ?>
                </a>
                <?php if ($hasChildren): ?>
                    <ul class="sidebar-submenu <?= $isActive === 'active' ? '' : 'is-collapsed' ?>" data-submenu-id="<?= htmlspecialchars((string) $item['id']) ?>">
                        <?php foreach ($item['children'] as $child):
                            $childActive = isSidebarActive($child['path'] ?? '', $current_page);
                            $nestedChildren = (!empty($child['children']) && is_array($child['children'])) ? $child['children'] : [];
                            if (($item['id'] ?? '') === 'crm-market') {
                                $childQ = parse_url((string) ($child['path'] ?? ''), PHP_URL_QUERY);
                                $childView = '';
                                if (is_string($childQ)) {
                                    parse_str($childQ, $childQs);
                                    $childView = strtolower(trim((string) ($childQs['view'] ?? '')));
                                }
                                $reqView = strtolower(trim((string) ($_GET['view'] ?? 'home')));
                                $crmOnMarket = strpos(str_replace('\\', '/', (string) $script_name), '/modules/crm/market/') !== false;
                                // New Leads is top-level; do not highlight Saved search for it.
                                if ($reqView === 'new-leads') {
                                    $childActive = '';
                                } elseif ($childActive !== 'active' && $crmOnMarket && $childView !== '' && $childView === $reqView) {
                                    $childActive = 'active';
                                } elseif (
                                    $childActive !== 'active'
                                    && $crmOnMarket
                                    && $childView === 'settings'
                                    && function_exists('crmMarketIsSettingsView')
                                    && crmMarketIsSettingsView($reqView)
                                ) {
                                    $childActive = 'active';
                                }
                            }
                            if ($childActive !== 'active' && $nestedChildren !== []) {
                                foreach ($nestedChildren as $nestedItem) {
                                    if (isSidebarActive($nestedItem['path'] ?? '', $current_page) === 'active') {
                                        $childActive = 'active';
                                        break;
                                    }
                                }
                            }
                        ?>
                            <?php
                            // Account Category stays collapsed until the user toggles it.
                            $nestedMenuOpen = false;
                            if ($nestedChildren !== [] && ($child['id'] ?? '') !== 'account-category') {
                                foreach ($nestedChildren as $nestedItem) {
                                    if (isSidebarActive($nestedItem['path'] ?? '', $current_page) === 'active') {
                                        $nestedMenuOpen = true;
                                        break;
                                    }
                                }
                                if ($childActive === 'active') {
                                    $nestedMenuOpen = true;
                                }
                            }
                            ?>
                            <li class="nav-item<?= $nestedChildren !== [] ? ' sidebar-nested-group' : '' ?>">
                                <?php if ($nestedChildren !== []): ?>
                                <a href="#"
                                   class="nav-link sidebar-child-parent-toggle d-flex align-items-center w-100 <?= $childActive ?><?= $nestedMenuOpen ? ' is-open' : '' ?>"
                                   onclick="toggleSidebarSubmenu(this); return false;"
                                   title="<?= htmlspecialchars((string) ($child['title'] ?? $child['label'] ?? '')) ?>">
                                    <?= sidebar_render_nav_icon((string) ($child['icon'] ?? '')) ?>
                                    <span class="sidebar-text flex-grow-1 text-start"><?= htmlspecialchars((string) $child['label']) ?></span>
                                    <i class="bi bi-chevron-right submenu-chevron flex-shrink-0" aria-hidden="true"></i>
                                </a>
                                <ul class="sidebar-submenu-nested<?= $nestedMenuOpen ? '' : ' is-collapsed' ?>">
                                    <?php foreach ($nestedChildren as $nested): ?>
                                    <?php $nestedActive = isSidebarActive($nested['path'] ?? '', $current_page); ?>
                                    <li class="nav-item">
                                        <a href="<?= htmlspecialchars((string) ($nested['path'] ?? '#')) ?>" class="nav-link sidebar-nested-under-category <?= $nestedActive ?>" title="<?= htmlspecialchars((string) $nested['label']) ?>">
                                            <span class="sidebar-nested-dot" aria-hidden="true"></span>
                                            <span class="sidebar-text"><?= htmlspecialchars((string) $nested['label']) ?></span>
                                        </a>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php else: ?>
                                <a href="<?= htmlspecialchars((string) ($child['path'] ?? '#')) ?>" class="nav-link <?= $childActive ?>" title="<?= htmlspecialchars((string) ($child['title'] ?? $child['label'] ?? '')) ?>">
                                    <?= sidebar_render_nav_icon((string) ($child['icon'] ?? '')) ?>
                                    <span class="sidebar-text flex-grow-1 text-start"><?= htmlspecialchars((string) $child['label']) ?></span>
                                    <?php
                                    if (!empty($child['badge']) && is_string($child['badge'])) {
                                        $childBadgeLabel = (string) $child['badge'];
                                        $childBadgeTitle = (stripos($childBadgeLabel, 'soon') !== false) ? 'Coming soon' : $childBadgeLabel;
                                        echo '<span class="sidebar-coming-soon-badge" title="' . htmlspecialchars($childBadgeTitle) . '">' . htmlspecialchars($childBadgeLabel) . '</span>';
                                    }
                                    ?>
                                </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($active_module === 'expenses'):
        $expSidebarInsightsPartial = __DIR__ . '/modules/expenses/partials/sidebar_smart_insights.php';
        if (is_file($expSidebarInsightsPartial)) {
            include $expSidebarInsightsPartial;
        }
    endif; ?>

    <?php if (in_array($active_module, ['warehouses', 'store-management'], true)): ?>
    <div class="px-3 mb-2 mt-3 small fw-bold text-muted sidebar-text" style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.5;">
        Quick
    </div>
    <ul class="nav nav-pills flex-column sidebar-header-tools">
        <li class="nav-item sidebar-notif-item">
            <?php
            $notifDisplayMode = 'sidebar';
            require __DIR__ . '/includes/partials/header_notifications.php';
            unset($notifDisplayMode);
            ?>
        </li>
        <li class="nav-item">
            <button type="button" id="themeToggleBtn" class="nav-link sidebar-theme-toggle w-100 text-start border-0 bg-transparent" aria-label="Toggle Theme" title="Toggle Dark/Light Mode">
                <i class="fas fa-moon" id="themeToggleIcon"></i>
                <span class="sidebar-text">Dark / Light</span>
            </button>
        </li>
    </ul>
    <?php endif; ?>

    <?php if ($active_module !== 'analytics'): ?>
    <div class="px-3 mb-2 mt-4 small fw-bold text-muted sidebar-text" style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.5;">
        Account
    </div>
    
    <ul class="nav nav-pills flex-column">
        <li class="nav-item">
             <a href="<?= $accountLink ?>" class="nav-link" title="Profile Settings">
                <i class="bi bi-person"></i>
                <span class="sidebar-text">Profile Settings</span>
            </a>
        </li>
        <li class="nav-item">
             <a href="#" class="nav-link" onclick="openThemeModal(); return false;" title="Appearance">
                <i class="bi bi-palette"></i>
                <span class="sidebar-text">Appearance</span>
            </a>
        </li>
        <li class="nav-item sidebar-logout-mobile">
            <a href="<?= $prefix ?>logout.php" class="nav-link text-danger" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
                <span class="sidebar-text">Logout</span>
            </a>
        </li>
    </ul>
    
    <!-- Secondary / Footer items -->
    <div class="mt-auto">
        <ul class="nav nav-pills flex-column">
             <li class="nav-item sidebar-logout-footer">
                <a href="<?= $prefix ?>logout.php" class="nav-link text-danger" style="font-weight: 600;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="sidebar-text">Logout</span>
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</nav>

<!-- Theme Switcher Modal -->
<div class="modal fade" id="themeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Select Theme</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-grid gap-2">
            <?php
            if (!function_exists('getThemeUrl')) {
                function getThemeUrl($themeName) {
                    $params = $_GET;
                    $params['set_theme'] = $themeName;
                    return '?' . http_build_query($params);
                }
            }
            ?>
            <a href="<?= getThemeUrl('default') ?>" class="btn btn-primary d-flex justify-content-between align-items-center">
                <span>Trust Blue</span>
                <?php if($current_theme === 'default') echo '<i class="bi bi-check-circle-fill"></i>'; ?>
            </a>
            <a href="<?= getThemeUrl('theme-minimal') ?>" class="btn border d-flex justify-content-between align-items-center" style="background-color: #ffffff; color: #334155;">
                <span>Minimal (Default)</span>
                 <?php if($current_theme === 'theme-minimal') echo '<i class="bi bi-check-circle-fill"></i>'; ?>
            </a>
            <a href="<?= getThemeUrl('theme-deep-ocean') ?>" class="btn btn-dark d-flex justify-content-between align-items-center" style="background-color: #001f2f; border-color: #00364d;">
                <span>Deep Ocean</span>
                 <?php if($current_theme === 'theme-deep-ocean') echo '<i class="bi bi-check-circle-fill"></i>'; ?>
            </a>
            <a href="<?= getThemeUrl('theme-clean-white') ?>" class="btn btn-light d-flex justify-content-between align-items-center border">
                <span>Clean White</span>
                 <?php if($current_theme === 'theme-clean-white') echo '<i class="bi bi-check-circle-fill"></i>'; ?>
            </a>
            <a href="<?= getThemeUrl('theme-night-mode') ?>" class="btn btn-dark d-flex justify-content-between align-items-center">
                <span>Night Mode</span>
                 <?php if($current_theme === 'theme-night-mode') echo '<i class="bi bi-check-circle-fill"></i>'; ?>
            </a>
            <a href="<?= getThemeUrl('theme-royal-purple') ?>" class="btn text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(180deg, #4a148c, #311b92);">
                <span>Royal Purple</span>
                 <?php if($current_theme === 'theme-royal-purple') echo '<i class="bi bi-check-circle-fill"></i>'; ?>
            </a>
            <a href="<?= getThemeUrl('theme-professional') ?>" class="btn text-white d-flex justify-content-between align-items-center" style="background-color: #1e40af;">
                <span>Professional</span>
                 <?php if($current_theme === 'theme-professional') echo '<i class="bi bi-check-circle-fill"></i>'; ?>
            </a>
            <a href="<?= getThemeUrl('theme-dark-mode-v2') ?>" class="btn text-white d-flex justify-content-between align-items-center" style="background-color: #0f172a;">
                <span>Dark Mode V2</span>
                 <?php if($current_theme === 'theme-dark-mode-v2') echo '<i class="bi bi-check-circle-fill"></i>'; ?>
            </a>
            <a href="<?= getThemeUrl('theme-cool') ?>" class="btn d-flex justify-content-between align-items-center" style="background-color: #ecfeff; color: #155e75;">
                <span>Cool</span>
                 <?php if($current_theme === 'theme-cool') echo '<i class="bi bi-check-circle-fill"></i>'; ?>
            </a>
        </div>
      </div>
    </div>
  </div>
</div>



<script>
    function toggleSidebarSubmenu(trigger) {
        var submenu = trigger.nextElementSibling;
        if (!submenu) {
            return;
        }
        var isSubmenu = submenu.classList.contains('sidebar-submenu')
            || submenu.classList.contains('sidebar-submenu-nested');
        if (!isSubmenu) {
            return;
        }
        var isCollapsed = submenu.classList.contains('is-collapsed');
        submenu.classList.toggle('is-collapsed');
        trigger.classList.toggle('is-open', isCollapsed);
    }

    function toggleNativeSidebar() {
        document.getElementById('native-sidebar').classList.toggle('show');
        document.getElementById('sidebar-overlay').classList.toggle('show');
    }
    
    function openThemeModal() {
        var myModal = new bootstrap.Modal(document.getElementById('themeModal'));
        myModal.show();
    }
    
    function toggleSidebarCollapse() {
        var sidebar = document.getElementById('native-sidebar');
        var body = document.body;
        sidebar.classList.toggle('collapsed');
        body.classList.toggle('sidebar-collapsed');
        
        // Save state
        var isCollapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('sidebar_collapsed_state', isCollapsed ? '1' : '0');
    }
    
    // Initialize Collapse State
    (function() {
        var savedState = localStorage.getItem('sidebar_collapsed_state');
        if (savedState === '1') {
            document.getElementById('native-sidebar').classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        }
    })();
</script>

<!-- Included SweetAlert2 Logic from legacy file (Persisted) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    if (!window.Toast) {
        window.Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });
    }

    function showToast(type, message) {
        window.Toast.fire({
            icon: type,
            title: message
        });
    }

    <?php if (isset($_SESSION['flash_message'])): ?>
        showToast('<?= $_SESSION['flash_type'] ?? 'info' ?>', '<?= addslashes($_SESSION['flash_message']) ?>');
        <?php
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        ?>
    <?php endif; ?>
</script>

<!-- Ensure Bootstrap 5 JS is loaded for Modem (Required if parent page doesn't have it) -->
<script>
    if (typeof bootstrap === 'undefined') {
        let script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';
        script.onload = function() {
            // Re-initialize modal trigger if needed, or simply let onclick work now
        };
        document.head.appendChild(script);
    }
</script>

