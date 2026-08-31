<?php
// Sidebar Component
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
if (stripos($path_to_check, 'stock') !== false || stripos($path_to_check, 'procurement') !== false) {
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
if (strpos($script_name, '/modules/finance/') !== false) {
    $active_module = 'finance';
}
if (strpos($script_name, '/modules/payroll/') !== false) {
    $active_module = 'payroll';
}
if (strpos($script_name, '/accounting/') !== false) {
    $active_module = 'finance';
}
if (strpos($script_name, '/modules/balances/') !== false) {
    $active_module = 'balances';
}
if (basename($script_name) === 'supplier-payments.php' && (($_GET['module'] ?? '') === 'balances')) {
    $active_module = 'balances';
}
if (strpos($script_name, '/accounting/') !== false && (isset($_GET['module']) && $_GET['module'] === 'balances')) {
    $active_module = 'balances';
}
if (strpos($script_name, '/modules/email/') !== false) {
    $active_module = 'email';
}
if (strpos($script_name, '/dispatch/') !== false) {
    $active_module = 'dispatch';
}

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Tenant-aware prefix (e.g. /public_html/ultimate/) — same pattern as sidebar.php
$__sidebarSlug = trim((string) ($_SESSION['company_slug'] ?? ''));
if ($__sidebarSlug !== '' && function_exists('app_url')) {
    $prefix = rtrim(app_url('/' . $__sidebarSlug), '/') . '/';
} else {
    $prefix = rtrim((string) APP_BASE_PATH, '/') . '/';
    if ($prefix === '/') {
        $prefix = '';
    }
    if ($prefix !== '' && substr($prefix, -1) !== '/') {
        $prefix .= '/';
    }
}

// Determine Select Module URL
$selectModuleUrl = app_url('select-module.php');

// --- GENERATE SIDEBAR DATA ---
$menuItems = [];
$__expensesModuleUpdateBadge = null;
$__expensesUpdateBadgePath = __DIR__ . '/../../modules/expenses/includes/update-badge.php';
if (is_file($__expensesUpdateBadgePath)) {
    require_once $__expensesUpdateBadgePath;
    $__expensesModuleUpdateBadge = expenses_module_update_badge();
}
// Expose application base path for consumers
$APP_BASE_PATH = APP_BASE_PATH;

// Helper to add item (optional $children: list of ['id','label','icon','path'])
function addItem(&$arr, $id, $label, $icon, $path, $badge = null, $children = null) {
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
    } elseif (strpos($pathStr, '/deliveries/') !== false || strpos($pathStr, '/dispatch/') !== false) {
        $moduleKey = 'logistics';
    } elseif (strpos($pathStr, 'voucher') !== false) {
        $moduleKey = 'payment_voucher';
    } elseif (strpos($pathStr, 'revenue') !== false) {
        $moduleKey = 'revenue';
    }
    $isDispatchOrDeliveryPath = (strpos($pathStr, '/dispatch/') !== false || strpos($pathStr, '/deliveries/') !== false);
    if ($moduleKey !== null && function_exists('isCompanyModuleEnabled') && !isCompanyModuleEnabled($moduleKey) && !$isDispatchOrDeliveryPath) {
        return;
    }
    $row = [
        'id' => $id,
        'label' => $label,
        'icon' => $icon,
        'path' => $path,
        'badge' => $badge,
    ];
    if (is_array($children) && $children !== []) {
        $row['children'] = $children;
    }
    $arr[] = $row;
}

// Module Header (All Modules)
addItem($menuItems, 'all-modules', 'All Modules', 'home', $selectModuleUrl);
if (stripos($path_to_check, '/deliveries/') !== false) {
    addItem($menuItems, 'quick-delivery-note', 'Delivery Note', 'document-text', $prefix . 'deliveries/create_delivery_note.php?module=deliveries');
}
if (stripos($path_to_check, '/dispatch/') !== false) {
    addItem($menuItems, 'quick-dispatch-dashboard', 'Dashboard', 'chart-bar', $prefix . 'dispatch/index.php?module=dispatch');
}

switch ($active_module) {
    case 'stocks':
        addItem($menuItems, 'dashboard', 'Dashboard', 'chart-bar', $prefix . 'stock/dashboard.php');
        addItem($menuItems, 'catalogue', 'Catalogue', 'clipboard', $prefix . 'stock/catalogue.php');
        addItem($menuItems, 'products', 'Products', 'archive', $prefix . 'stock/modules/products/index.php', null, [
            [
                'id' => 'product-files',
                'label' => 'Files',
                'icon' => 'folder-open',
                'path' => $prefix . 'stock/modules/uploads/index.php?folder=products&images=1',
            ],
        ]);
        addItem($menuItems, 'categories', 'Categories', 'ticket', $prefix . 'stock/modules/products/categories.php');
        addItem($menuItems, 'image-library', 'Image library', 'image', $prefix . 'stock/modules/uploads/index.php');
        addItem($menuItems, 'suppliers', 'Suppliers', 'users', $prefix . 'stock/modules/suppliers/index.php');
        addItem($menuItems, 'statements', 'Statement', 'file-invoice', $prefix . 'stock/modules/statements/supplier.php');
        addItem($menuItems, 'analytics', 'Analytics', 'presentation', $prefix . 'stock/modules/analytics/index.php');
        addItem($menuItems, 'shipments', 'Shipments', 'truck', $prefix . 'stock/modules/shipments/index.php');
        addItem($menuItems, 'purchases', 'Purchases', 'shopping-bag', $prefix . 'stock/modules/purchases/index.php');
        addItem($menuItems, 'replenishment', 'Replenishment', 'clipboard', $prefix . 'stock/modules/reports/replenishment.php');
        addItem($menuItems, 'stock-control', 'Stock Control', 'cog', $prefix . 'stock/modules/stock/movements.php');
        addItem($menuItems, 'reports', 'Reports', 'document-text', $prefix . 'stock/modules/reports/stock.php');
        addItem($menuItems, 'settings', 'Settings', 'cog', $prefix . 'stock/settings.php');
        break;

    case 'sales':
        addItem($menuItems, 'dashboard', 'Dashboard', 'chart-bar', $prefix . 'modules/sales/dashboard/index.php?module=sales');
        addItem($menuItems, 'sales-catalogue', 'Sales Catalogue', 'clipboard', $prefix . 'modules/sales/catalogue.php?module=sales');
        addItem($menuItems, 'pricelist', 'Pricelist', 'tags', $prefix . 'modules/sales/pricelist.php?module=sales');
        addItem($menuItems, 'customers', 'Customers', 'users', $prefix . 'modules/sales/customers/index.php?module=sales');

        if (isRoadmaster()) {
            addItem($menuItems, 'create-truck', 'New Truck Quote', 'truck', $prefix . 'modules/sales/orders/create.php?module=sales&mode=new&type=truck');
            addItem($menuItems, 'create-spare', 'New Spare Quote', 'cogs', $prefix . 'modules/sales/orders/create.php?module=sales&mode=new&type=spare');
            addItem($menuItems, 'orders', 'All Orders', 'shopping-bag', $prefix . 'modules/sales/orders/index.php?module=sales');
        } else {
            addItem($menuItems, 'create', 'Create Quote', 'document-text', $prefix . 'modules/sales/orders/create.php?module=sales');
            addItem($menuItems, 'orders', 'Sales Orders', 'shopping-bag', $prefix . 'modules/sales/orders/index.php?module=sales');
        }

        addItem($menuItems, 'invoices', 'Invoices', 'currency', $prefix . 'modules/sales/invoices/index.php?module=sales');
        addItem($menuItems, 'supplier-statement', 'Supplier statement', 'file-invoice', $prefix . 'stock/modules/statements/supplier.php');

        addItem($menuItems, 'create-invoice', 'Create Invoice', 'currency', $prefix . 'modules/sales/invoices/create.php?module=sales');
        addItem($menuItems, 'record-payment', 'Record Payment', 'currency', $prefix . 'revenue_entries.php?module=revenue');
        addItem($menuItems, 'settings', 'Settings', 'cog', $prefix . 'modules/sales/settings/index.php?module=sales');
        if ($is_admin) {
             addItem($menuItems, 'targets', 'Set Targets', 'presentation', $prefix . 'modules/sales/admin/targets.php?module=sales');
        }
        break;

    case 'finance':
        addItem($menuItems, 'dashboard', 'Overview', 'chart-pie', $prefix . 'modules/finance/index.php');
        addItem($menuItems, 'transactions', 'Transactions', 'list', $prefix . 'modules/finance/transactions.php');
        addItem($menuItems, 'accounts', 'Payment Accounts', 'credit-card', $prefix . 'modules/finance/payment_methods.php');
        addItem($menuItems, 'budgets', 'Budgets', 'bullseye', $prefix . 'modules/finance/budgets.php');
        addItem($menuItems, 'reports', 'Reports', 'chart-bar', $prefix . 'modules/finance/reports.php');
        addItem($menuItems, 'pl', 'Profit & Loss', 'graph-up-arrow', $prefix . 'accounting/profit-loss.php');
        addItem($menuItems, 'tb', 'Trial Balance', 'table', $prefix . 'accounting/trial-balance.php?module=balances');
        addItem($menuItems, 'bs', 'Balance Sheet', 'journal-check', $prefix . 'accounting/balance-sheet.php');
        addItem($menuItems, 'journal-config', 'Journal Configuration', 'book', $prefix . 'accounting/journal-configuration.php?module=balances');
        addItem($menuItems, 'reconciliation', 'Reconciliation', 'balance-scale', $prefix . 'accounting/reconciliation.php?module=balances');
        break;

    case 'payroll':
        addItem($menuItems, 'dashboard', 'Dashboard', 'chart-bar', $prefix . 'modules/payroll/index.php?module=payroll');
        addItem($menuItems, 'salaries', 'Employees', 'users', $prefix . 'modules/payroll/salaries.php?module=payroll');
        addItem($menuItems, 'run', 'Run Payroll', 'play', $prefix . 'modules/payroll/run_payroll.php?module=payroll');
        addItem($menuItems, 'settings', 'Settings', 'cog', $prefix . 'modules/payroll/settings.php?module=payroll');
        addItem($menuItems, 'help', 'User Manual', 'question-circle', $prefix . 'modules/payroll/help.php?module=payroll');
        break;

    case 'deliveries':
        addItem($menuItems, 'dashboard', 'Dashboard', 'truck', $prefix . 'deliveries/index.php?module=deliveries');
        addItem($menuItems, 'trips', 'All Trips', 'map', $prefix . 'deliveries/trips.php?module=deliveries'); // Note: map icon not mapped, fallback to home
        addItem($menuItems, 'new-request', 'New Delivery', 'document-text', $prefix . 'deliveries/create_delivery.php?module=deliveries');
        addItem($menuItems, 'new-note', 'Create Delivery Note', 'document-text', $prefix . 'deliveries/create_delivery_note.php?module=deliveries');
        addItem($menuItems, 'driver', 'Driver Portal', 'users', $prefix . 'deliveries/Driver.php?module=deliveries');
        addItem($menuItems, 'notes', 'Delivery Notes', 'clipboard', $prefix . 'deliveries/delivery_notes.php?module=deliveries');
        addItem($menuItems, 'reviews', 'Reviews', 'star', $prefix . 'deliveries/customer_reviews.php?module=deliveries');
        break;

    case 'dispatch':
        addItem($menuItems, 'dispatch-dashboard', 'Dashboard', 'chart-bar', $prefix . 'dispatch/index.php?module=dispatch');
        addItem($menuItems, 'dispatch-notes', 'Dispatch Notes', 'truck', $prefix . 'dispatch/index.php?module=dispatch');
        addItem($menuItems, 'routes', 'Routes', 'map', $prefix . 'dispatch/routes.php?module=dispatch');
        addItem($menuItems, 'saved-routes', 'Saved Routes', 'table', $prefix . 'dispatch/saved_routes.php?module=dispatch');
        addItem($menuItems, 'office-trips', 'Office Trips', 'car', $prefix . 'dispatch/office_trips.php?module=dispatch');
        addItem($menuItems, 'records', 'Records & Report', 'document-text', $prefix . 'dispatch/records.php?module=dispatch');
        break;

    case 'voucher':
        $dashUrl = $is_admin ? 'admin/dashboard.php' : 'employee/dashboard.php';
        addItem($menuItems, 'dashboard', 'Dashboard', 'ticket', $prefix . $dashUrl . '?module=voucher');
        addItem($menuItems, 'user-manual', 'User Manual', 'book-open', $prefix . 'employee/user-manual.php?module=voucher');
        
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'robot', $prefix . 'admin/ai_assistant.php?module=voucher');
            addItem($menuItems, 'create', 'Create Voucher', 'document-text', $prefix . 'employee/create-voucher.php?module=voucher');
            addItem($menuItems, 'all', 'View All', 'clipboard', $prefix . 'admin/all-vouchers.php?module=voucher');
            addItem($menuItems, 'users', 'Manage Users', 'users', $prefix . 'admin/manage-users.php?module=voucher');
            addItem($menuItems, 'reports', 'Reports', 'presentation', $prefix . 'admin/reports.php?module=voucher');
            addItem($menuItems, 'payees', 'Manage Payees', 'users', $prefix . 'admin/manage-payees.php?module=voucher');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'robot', $prefix . 'employee/ai_assistant.php?module=voucher');
            addItem($menuItems, 'create', 'Create Voucher', 'document-text', $prefix . 'employee/create-voucher.php?module=voucher');
            addItem($menuItems, 'create-payee', 'Create Payee', 'users', $prefix . 'employee/create-voucher.php?module=voucher&action=create_payee');
            addItem($menuItems, 'view', 'View Vouchers', 'ticket', $prefix . 'employee/my-vouchers.php?module=voucher');
            addItem($menuItems, 'export', 'Export Excel', 'archive', $prefix . 'export_vouchers_list.php');
        }
        break;

    case 'attendance':
        if ($is_admin) {
            addItem($menuItems, 'view', 'View Records', 'clipboard', $prefix . 'admin/view-attendance.php?module=attendance');
        } else {
            addItem($menuItems, 'sign', 'Sign In/Out', 'clipboard', $prefix . 'employee/sign-attendance.php?module=attendance');
            addItem($menuItems, 'stats', 'My Stats', 'chart-bar', $prefix . 'employee/attendance-analytics.php?module=attendance');
        }
        break;

    case 'meetings':
        $mtgUrl = $is_admin ? 'admin/meetings.php' : 'employee/meetings.php';
        addItem($menuItems, 'meetings', 'Meeting Room', 'users', $prefix . $mtgUrl . '?module=meetings');
        break;

    case 'tasks':
        if ($is_admin) {
            addItem($menuItems, 'all', 'All Tasks', 'clipboard', $prefix . 'admin/manage_tasks.php?module=tasks');
            addItem($menuItems, 'daily', 'Daily Review', 'calendar', $prefix . 'admin/daily-tasks.php?module=tasks');
        } else {
            addItem($menuItems, 'my', 'My Tasks', 'clipboard', $prefix . 'employee/tasks.php?module=tasks');
            addItem($menuItems, 'daily', 'Daily Plan', 'calendar', $prefix . 'employee/daily-task.php?module=tasks');
        }
        break;

    case 'tracking':
        addItem($menuItems, 'dashboard', 'Dashboard', 'search', $prefix . 'order-tracking/index.php?module=tracking');
        break;

    case 'outstanding':
        addItem($menuItems, 'receivables', 'Receivables', 'currency', $prefix . 'erp/outstanding-invoices/index.php?module=outstanding&tab=receivables');
        addItem($menuItems, 'payables', 'Payables', 'currency', $prefix . 'erp/outstanding-invoices/index.php?module=outstanding&tab=payables');
        break;
        
     case 'petty_cash':
        addItem($menuItems, 'dashboard', 'Dashboard', 'currency', $prefix . 'erp/petty-cash/index.php?module=petty_cash');
        addItem($menuItems, 'new', 'New Request', 'document-text', $prefix . 'erp/petty-cash/replenishment.php?module=petty_cash');
        addItem($menuItems, 'voucher', 'New Voucher', 'ticket', $prefix . 'erp/petty-cash/create-voucher.php?module=petty_cash');
        addItem($menuItems, 'categories', 'Categories', 'folder', $prefix . 'erp/petty-cash/categories/index.php?module=petty_cash');
        addItem($menuItems, 'category-new', 'New Category', 'folder-plus', $prefix . 'erp/petty-cash/categories/create.php?module=petty_cash');
        addItem($menuItems, 'reports', 'Reports', 'presentation', $prefix . 'erp/petty-cash/reports.php?module=petty_cash');
        break;
        
     case 'revenue':
        addParentItem($menuItems, 'revenue-parent', 'Revenue', 'currency', [
            [
                'id' => 'revenue-list',
                'label' => 'Revenues',
                'icon' => 'list-ul',
                'path' => $prefix . 'revenue_entries.php?module=revenue',
            ],
            [
                'id' => 'revenue-invoices',
                'label' => 'Invoices to Pay',
                'icon' => 'document-text',
                'path' => $prefix . 'revenue_invoices.php?module=revenue',
            ],
            [
                'id' => 'revenue-categories',
                'label' => 'Revenue Sub Accounts',
                'icon' => 'folder-open',
                'path' => $prefix . 'revenue_categories.php?module=revenue',
            ],
        ]);
        addItem($menuItems, 'customers', 'Customers', 'users', $prefix . 'revenue_customers.php?module=revenue');
        addItem($menuItems, 'new-cust', 'New Customer', 'users', $prefix . 'revenue_customer_create.php?module=revenue');
        addItem($menuItems, 'journal-entries', 'Journal Entries', 'journal-text', $prefix . 'accounting/journal-entries.php?module=revenue');
        addItem($menuItems, 'journal-config', 'Journal Configuration', 'book', $prefix . 'accounting/journal-configuration.php?module=revenue');
        break;

     case 'accounting':
        addItem($menuItems, 'dashboard', 'Dashboard', 'calculator', $prefix . 'modules/accounting/index.php?module=accounting');
        addItem($menuItems, 'balances', 'Balances', 'balance-scale', $prefix . 'modules/balances/index.php?module=balances');
        addItem($menuItems, 'accounts', 'Accounts', 'credit-card', $prefix . 'modules/balances/accounts.php');
        addItem($menuItems, 'new-account', 'New Account', 'plus-circle', $prefix . 'modules/balances/coa_create.php?module=balances');
        addItem($menuItems, 'account-category', 'Account Category', 'folder-plus', $prefix . 'modules/balances/category_create.php?module=balances');
        addItem($menuItems, 'revenue', 'Revenue', 'currency', $prefix . 'revenue_entries.php?module=revenue');
        addItem($menuItems, 'expenses', 'Expenses', 'receipt', $prefix . 'modules/expenses/index.php?module=expenses', $__expensesModuleUpdateBadge);
        addItem($menuItems, 'journal-entries', 'Journal Entries', 'journal-text', $prefix . 'accounting/journal-entries.php?module=accounting');
        addItem($menuItems, 'journal-config', 'Journal Configuration', 'book', $prefix . 'accounting/journal-configuration.php?module=accounting');
        addItem($menuItems, 'reconciliation', 'Reconciliation', 'balance-scale', $prefix . 'accounting/reconciliation.php?module=accounting');
        addItem($menuItems, 'tb', 'Trial Balance', 'table', $prefix . 'accounting/trial-balance.php?module=accounting');
        addItem($menuItems, 'pl', 'Profit & Loss', 'graph-up-arrow', $prefix . 'accounting/profit-loss.php?module=accounting');
        addItem($menuItems, 'bs', 'Balance Sheet', 'journal-check', $prefix . 'accounting/balance-sheet.php?module=accounting');
        break;

     case 'balances':
        addItem($menuItems, 'dashboard', 'Dashboard', 'chart-pie', $prefix . 'modules/balances/index.php');
        addItem($menuItems, 'account-parent', 'Account', 'credit-card', $prefix . 'modules/balances/accounts.php');
        addItem($menuItems, 'new-account', 'New Account', 'plus-circle', $prefix . 'modules/balances/coa_create.php?module=balances');
        addItem($menuItems, 'account-category', 'Account Category', 'folder-plus', $prefix . 'modules/balances/category_create.php?module=balances', null, [
            [
                'id' => 'account-categories',
                'label' => 'All Categories',
                'icon' => 'folder-open',
                'path' => $prefix . 'modules/balances/account_categories.php?module=balances',
            ],
            [
                'id' => 'account-types',
                'label' => 'All Account Types',
                'icon' => 'tags',
                'path' => $prefix . 'modules/balances/account_types.php?module=balances',
            ],
        ]);
        addItem($menuItems, 'transfer', 'Transfer', 'arrow-right', $prefix . 'modules/balances/transfer.php');
        addItem($menuItems, 'reconciliation', 'Reconciliation', 'balance-scale', $prefix . 'accounting/reconciliation.php?module=balances');
        addItem($menuItems, 'transactions', 'History', 'list', $prefix . 'modules/balances/transactions.php');
        addItem($menuItems, 'pl', 'Profit & Loss', 'graph-up-arrow', $prefix . 'accounting/profit-loss.php');
        addItem($menuItems, 'tb', 'Trial Balance', 'table', $prefix . 'accounting/trial-balance.php?module=balances');
        addItem($menuItems, 'bs', 'Balance Sheet', 'journal-check', $prefix . 'accounting/balance-sheet.php');
        break;

     case 'expenses':
        addItem($menuItems, 'dashboard', 'Expenses', 'chart-bar', $prefix . 'modules/expenses/index.php?module=expenses', $__expensesModuleUpdateBadge);
        addItem($menuItems, 'import', 'Import Expenses', 'upload', $prefix . 'modules/expenses/import.php?module=expenses');
        addItem($menuItems, 'new', 'New Expense', 'document-text', $prefix . 'modules/expenses/create.php?module=expenses');
        addItem($menuItems, 'view', 'View Expenses', 'clipboard', $prefix . 'modules/expenses/view.php?module=expenses');
        break;

    case 'email':
        addItem($menuItems, 'inbox', 'Inbox', 'envelope', $prefix . 'modules/email/index.php?module=email');
        addItem($menuItems, 'compose', 'Compose', 'pencil-square', $prefix . 'modules/email/compose.php?module=email');
        break;

    default:
        $dashUrl = $is_admin ? 'admin/dashboard.php' : 'employee/dashboard.php';
        addItem($menuItems, 'dashboard', 'Dashboard', 'home', $prefix . $dashUrl);
        if ($is_admin) {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'robot', $prefix . 'admin/ai_assistant.php');
        } else {
            addItem($menuItems, 'ai_assistant', 'AI Assistant', 'robot', $prefix . 'employee/ai_assistant.php');
            addItem($menuItems, 'create-voucher', 'Create Voucher', 'ticket', $prefix . 'employee/create-voucher.php');
        }
        break;
}

// Global Links
$accountUrl = function_exists('user_profile_settings_url') ? user_profile_settings_url($prefix, $active_module ?? null) : app_url('/employee/account.php');
addItem($menuItems, 'email-global', 'Customer Email', 'envelope', $prefix . 'modules/email/index.php?module=email');
addItem($menuItems, 'account', 'Profile Settings', 'person', $accountUrl);

if ($is_admin) {
    addItem($menuItems, 'system-settings', 'System Settings', 'cog', $prefix . 'admin/settings.php');
}

addItem($menuItems, 'logout', 'Logout', 'logout', app_url('logout.php'));

if (!function_exists('sidebar_link_is_current')) {
    /**
     * True when current request matches link path and required query params (for submenu items).
     *
     * @param string $currentUri
     * @param string $href
     */
    function sidebar_link_is_current($currentUri, $href) {
        $cur = parse_url($currentUri);
        $tgt = parse_url($href);
        if (empty($tgt['path'])) {
            return false;
        }
        $norm = static function ($path) {
            $path = rtrim((string) $path, '/');
            return (string) preg_replace('#/(index)?\.php$#', '', $path);
        };
        $curPath = $norm($cur['path'] ?? '');
        $tgtPath = $norm($tgt['path']);
        if ($curPath !== $tgtPath) {
            return false;
        }
        if (empty($tgt['query'])) {
            return true;
        }
        parse_str($tgt['query'], $want);
        parse_str($cur['query'] ?? '', $have);
        foreach ($want as $k => $v) {
            if (!isset($have[$k]) || (string) $have[$k] !== (string) $v) {
                return false;
            }
        }
        return true;
    }
}

?>

<div class="d-flex flex-column flex-shrink-0 p-3 text-dark bg-white sidebar-container shadow-sm" style="width: 250px; height: 100vh; position: fixed; top: 0; left: 0; z-index: 1000; border-right: 1px solid #e2e8f0;">
    <div class="d-flex align-items-center justify-content-between mb-3 mb-md-0 me-md-auto w-100 sidebar-header-content">
        <a href="<?= app_url('select-module.php') ?>" class="d-flex align-items-center text-dark text-decoration-none sidebar-logo gap-2">
            <?php 
            $sidebarLogo = function_exists('getCompanySetting') ? trim((string)getCompanySetting('company_logo', '')) : '';
            if ($sidebarLogo !== ''): 
            ?>
                <img src="<?= htmlspecialchars(app_url('/' . ltrim($sidebarLogo, '/'))) ?>" alt="Logo" style="max-height: 40px; max-width: 140px; object-fit: contain;">
            <?php else: ?>
                <span class="fs-4 fw-bold" style="color: #0f172a;"><?= htmlspecialchars($_SESSION['company_name'] ?? 'Ultimate') ?></span>
            <?php endif; ?>
        </a>
        <i class="fas fa-bars fs-5 text-dark cursor-pointer sidebar-toggle-icon" onclick="toggleHeaderMenu()" style="cursor: pointer; display: none;"></i>
    </div>
    <hr>
    
    <!-- User Profile Summary -->
    <div class="dropdown mb-3">
        <a href="<?php echo htmlspecialchars($accountUrl, ENT_QUOTES, 'UTF-8'); ?>" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false">
            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold me-2" style="width: 32px; height: 32px;">
                <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
            </div>
            <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></strong>
        </a>
        <ul class="dropdown-menu text-small shadow" aria-labelledby="dropdownUser1">
            <li><a class="dropdown-item" href="<?php echo $accountUrl; ?>">Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?php echo app_url('logout.php'); ?>">Sign out</a></li>
        </ul>
    </div>

    <!-- Navigation Items -->
    <ul class="nav nav-pills flex-column mb-auto sidebar-nav-scroll">
        <?php foreach ($menuItems as $item): 
            // Active State Logic
            // Check if current URL starts with the item path (handled relative/absolute)
            // Fix: Ensure we compare comparable paths
            $currentUri = $_SERVER['REQUEST_URI'] ?? '';
            $itemPath = parse_url($item['path'], PHP_URL_PATH);
            
            // Simple logic: if item is 'index.php', exact match. Otherwise, prefix match.
            // Exception: 'dashboard' vs 'products'
            
            // Better logic: Match if current URI contains the item Key Path
            // e.g. /stock/modules/products/ matches /stock/modules/products/index.php dirname
            
            // Fallback to simple strpos for now, mimicking the JS 'includes' logic
            $isActive = false;
            if ($itemPath && $currentUri) {
                // If this is a dashboard link, require tighter match to avoid highlighting it for every sub-page
                if (strpos($itemPath, 'dashboard') !== false) {
                     $isActive = ($currentUri === $itemPath);
                } else {
                     // For modules (products, sales, etc), allow prefix match
                     // Remove .php for directory-style matching if needed
                     $baseCheck = str_replace(['index.php', '.php'], '', $itemPath);
                     if ($baseCheck !== '' && strpos($currentUri, $baseCheck) !== false) {
                         $isActive = true;
                     }
                }
            }
            // Explicit active_module override if passed
            if (isset($_GET['page']) && $_GET['page'] === $item['id']) {
                $isActive = true;
            }
            
            $activeClass = $isActive ? 'active' : 'text-muted';
            $iconClass = isset($item['icon']) ? "fas fa-{$item['icon']}" : "fas fa-circle";
            if ($item['id'] === 'logout') {
                 $activeClass = 'text-danger fw-bold';
            }
        ?>
        <?php
            $isCategoryHub = ($item['id'] ?? '') === 'account-category' && !empty($item['children']);
            // Account Category stays collapsed until the user toggles it.
            $categoryChildrenOpen = false;
        ?>
        <li class="nav-item">
            <a href="<?php echo $isCategoryHub ? '#' : $item['path']; ?>"
               class="nav-link <?php echo $activeClass; ?><?= $isCategoryHub ? ' sidebar-parent-toggle d-flex align-items-center' : '' ?><?= $isCategoryHub && $categoryChildrenOpen ? ' is-open' : '' ?>"
               aria-current="<?php echo $isActive ? 'page' : 'false'; ?>"
               <?= $isCategoryHub ? 'onclick="toggleSidebarSubmenu(this); return false;"' : '' ?>>
                <i class="<?php echo $iconClass; ?> me-2" style="width: 20px; text-align: center;"></i>
                <span class="<?= $isCategoryHub ? 'flex-grow-1' : '' ?>"><?php echo htmlspecialchars($item['label']); ?></span>
                <?php if ($isCategoryHub): ?>
                <i class="fas fa-chevron-right ms-auto" style="font-size: 0.75rem;" aria-hidden="true"></i>
                <?php endif; ?>
                <?php if (!empty($item['badge'])): ?>
                    <?php
                    if (function_exists('expenses_module_update_badge_html')) {
                        echo expenses_module_update_badge_html($item['badge']);
                    } else {
                        echo '<span class="badge bg-danger ms-2">' . htmlspecialchars((string) $item['badge']) . '</span>';
                    }
                    ?>
                <?php endif; ?>
            </a>
            <?php if (!empty($item['children'])): ?>
            <ul class="nav flex-column ms-3 mb-1 border-start ps-2 sidebar-submenu <?= ($item['id'] ?? '') === 'account-category' ? 'sidebar-account-category-children' : 'border-secondary' ?><?= $isCategoryHub && !$categoryChildrenOpen ? ' is-collapsed' : '' ?>" style="<?= ($item['id'] ?? '') === 'account-category' ? '' : '--bs-border-opacity: .25;' ?>">
                <?php foreach ($item['children'] as $child):
                    $childActive = sidebar_link_is_current($currentUri, $child['path']);
                    $childClass = $childActive ? 'active' : 'text-muted';
                    $childIcon = isset($child['icon']) ? "fas fa-{$child['icon']}" : 'fas fa-angle-right';
                ?>
                <li class="nav-item">
                    <?php $isAccountCategoryChild = (($item['id'] ?? '') === 'account-category'); ?>
                    <a href="<?php echo htmlspecialchars($child['path']); ?>" class="nav-link py-1 px-2 small<?= $isAccountCategoryChild ? ' sidebar-nested-under-category d-flex align-items-center gap-2' : '' ?> <?php echo $childClass; ?>" aria-current="<?php echo $childActive ? 'page' : 'false'; ?>">
                        <?php if ($isAccountCategoryChild): ?>
                        <span class="sidebar-nested-dot" aria-hidden="true"></span>
                        <?php else: ?>
                        <i class="<?php echo $childIcon; ?> me-2" style="width: 16px; text-align: center; font-size: 0.85em;"></i>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($child['label']); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

<!-- Styles to integrate Bootstrap Sidebar with existing layout -->
<style>
    /* Reset Sidebar width variable used in style.css */
    :root {
        --sidebar-width: 250px !important;
    }

    .exp-module-update-badge {
        display: inline-flex;
        align-items: center;
        margin-left: 0.35rem;
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
    
    body {
        /* Ensure visual compatibility */
        background-color: #f8f9fa;
    }

    .sidebar-container {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .sidebar-container::-webkit-scrollbar {
        width: 0;
        height: 0;
        display: none;
    }
    .sidebar-nav-scroll {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .sidebar-nav-scroll::-webkit-scrollbar {
        width: 0;
        height: 0;
        display: none;
    }
    
    /* Ensure FontAwesome is available if not already loaded */
    @import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css');

    ul.nav .sidebar-nested-under-category {
        color: #64748b !important;
        font-weight: 500;
    }
    ul.nav .sidebar-nested-under-category:hover,
    ul.nav .sidebar-nested-under-category.active {
        color: #7c3aed !important;
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
    ul.nav .sidebar-nested-under-category:hover .sidebar-nested-dot,
    ul.nav .sidebar-nested-under-category.active .sidebar-nested-dot {
        opacity: 1;
    }
    .sidebar-account-category-children {
        border-left-color: rgba(124, 58, 237, 0.35) !important;
        border-left-width: 2px !important;
        margin-left: 1.35rem !important;
        padding-left: 0.65rem !important;
    }
    .sidebar-submenu.is-collapsed {
        display: none !important;
    }
    .sidebar-parent-toggle.is-open .fa-chevron-right {
        transform: rotate(90deg);
    }
    .sidebar-parent-toggle .fa-chevron-right {
        transition: transform 0.2s ease;
    }

    /* Adjust main content to sit right of fixed sidebar */
    @media (min-width: 768px) {
        .main-content, .header, header, .admin-header {
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
            max-width: none !important;
            border-left: 1px solid #dee2e6;
        }
    }
    
    /* Mobile Sidebar Handling */
    @media (max-width: 767px) {
        .sidebar-container {
            width: 100% !important;
            height: auto !important;
            position: relative !important;
            display: none !important; /* Hide by default on mobile, toggle needed */
        }
        
        .sidebar-mobile-show {
            display: flex !important;
        }
        
        .main-content, .header {
            margin-left: 0 !important;
            width: 100% !important;
        }
    }
    
    /* Nav Pill Styling */
    .nav-pills .nav-link.active:not(.text-danger) {
        background-color: transparent;
        color: #7c3aed !important;
        font-weight: 600;
    }
    
    .nav-pills .nav-link:not(.text-danger):hover {
        background-color: transparent;
        color: #7c3aed !important;
    }

    /* Nested items under Products (not inside .nav-pills) */
    .sidebar-container .nav.flex-column .nav-link.active:not(.text-danger),
    .sidebar-container .nav.flex-column .nav-link.text-muted.active {
        color: #7c3aed !important;
        background-color: transparent;
        font-weight: 600;
    }
    .sidebar-container .nav.flex-column .nav-link:not(.text-danger):hover {
        color: #7c3aed !important;
        background-color: transparent;
    }
    body.sidebar-collapsed .sidebar-logo {
        display: none !important;
    }
    
    body.sidebar-collapsed .sidebar-toggle-icon {
        display: block !important;
        margin: 0 auto;
    }
    
    body.sidebar-collapsed .sidebar-header-content {
        justify-content: center !important;
        padding-bottom: 0 !important;
    }
    
    body.sidebar-collapsed .nav-link {
        justify-content: center;
        padding-left: 0;
        padding-right: 0;
        padding-top: 12px;
        padding-bottom: 12px;
    }
    
    body.sidebar-collapsed .nav-link span, 
    body.sidebar-collapsed .dropdown strong,
    body.sidebar-collapsed .badge {
        display: none !important;
    }
    
    body.sidebar-collapsed .nav-link i {
        margin-right: 0 !important;
        font-size: 1.2rem;
    }
    
    body.sidebar-collapsed .sidebar-container {
        width: 80px !important;
        align-items: center;
    }
    
    body.sidebar-collapsed .dropdown-toggle {
        justify-content: center;
    }
    
    body.sidebar-collapsed .dropdown-toggle .rounded-circle {
        margin-right: 0 !important;
    }
    
    /* Adjust main content when collapsed */
    @media (min-width: 768px) {
        body.sidebar-collapsed .main-content, 
        body.sidebar-collapsed .header, 
        body.sidebar-collapsed header, 
        body.sidebar-collapsed .admin-header {
            margin-left: 80px !important;
            width: calc(100% - 80px) !important;
        }
    }
</style>

<!-- Toggle Button for Mobile (Injected into Header usually, but added here for safety) -->
<script>
    // Ensure Bootstrap JS is loaded for Dropdowns
    if (typeof bootstrap === 'undefined') {
        let script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js';
        document.head.appendChild(script);
    }

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
</script>

<!-- Global Notifications (SweetAlert2) - Kept for compatibility -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Global Toast Configuration (use window.Toast to avoid redeclaration with footer)
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

    <?php if (isset($_SESSION['flash'])) { 
        $f = $_SESSION['flash'];
        echo "showToast('{$f['type']}', '" . addslashes($f['message']) . "');";
        unset($_SESSION['flash']);
    } ?>
</script>
