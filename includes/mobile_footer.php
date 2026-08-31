<?php
// Mobile bottom navigation â€” 5-icon bar (Home â†’ List â†’ Modules â†’ Account â†’ Action) on all modules
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/functions.php';
}
if (!isLoggedIn()) {
    return;
}

require_once __DIR__ . '/user-avatar.php';

$userPhotoUrl = '';
$userInitials = 'U';
if (isset($_SESSION['user_id'])) {
    global $pdo;
    $userId = (int) $_SESSION['user_id'];
    try {
        if ($pdo instanceof PDO) {
            $stmtUser = $pdo->prepare("SELECT profile_photo, full_name FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
            if ($userData) {
                $rawPhoto = trim((string)($userData['profile_photo'] ?? ''));
                if ($rawPhoto !== '' && function_exists('user_avatar_photo_url')) {
                    $userPhotoUrl = user_avatar_photo_url($rawPhoto);
                }
                if (!empty($userData['full_name'])) {
                    $userInitials = function_exists('user_avatar_initials') 
                        ? user_avatar_initials($userData['full_name']) 
                        : strtoupper(substr($userData['full_name'], 0, 1));
                }
            }
        }
    } catch (Throwable $e) {
        // Fallback
    }
}

$isAdmin = isAdmin();
$financeOrAdmin = function_exists('isFinanceOrAdmin') && isFinanceOrAdmin();

$script = $_SERVER['SCRIPT_NAME'] ?? '';
$queryString = $_SERVER['QUERY_STRING'] ?? '';

if (strpos($script, 'select-module.php') !== false) {
    return;
}

// Path prefix to web root (matches sidebar depth logic)
$cleanScript = ltrim((string) $script, '/');
$depth = substr_count($cleanScript, '/');
$prefix = str_repeat('../', $depth);

$mfStockBasePath = '';
$mfAppRootPath = '';
$scriptNorm = str_replace('\\', '/', $script);
if (strpos($scriptNorm, '/stock/') !== false || strpos($scriptNorm, 'stock/') === 0) {
    $stockPathsFile = __DIR__ . '/../stock/config/paths.php';
    if (is_file($stockPathsFile)) {
        require_once $stockPathsFile;
    }
}
if (!empty($stockBasePath)) {
    $mfStockBasePath = rtrim((string) $stockBasePath, '/') . '/';
}
if (!empty($rootPath)) {
    $mfAppRootPath = rtrim((string) $rootPath, '/') . '/';
} elseif (function_exists('app_url')) {
    $mfAppRootPath = rtrim(app_url('/'), '/') . '/';
}

if (isset($_GET['module'])) {
    $_SESSION['active_module'] = $_GET['module'];
}
$active_module = $_SESSION['active_module'] ?? 'dashboard';

// Auto-detect module from path (aligned with sidebar.php)
$path_to_check = $script . ($_SERVER['PHP_SELF'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
if (stripos($path_to_check, 'stock') !== false || stripos($path_to_check, 'procurement') !== false) {
    $active_module = 'stocks';
}
if (stripos($path_to_check, '/sales/') !== false) {
    $active_module = 'sales';
}
if (strpos($script, '/deliveries/') !== false) {
    $active_module = 'deliveries';
}
if (strpos($script, '/modules/expenses/') !== false) {
    $active_module = 'expenses';
}
if (strpos($script, '/modules/finance/') !== false) {
    $active_module = 'finance';
}
if (strpos($script, '/modules/balances/') !== false) {
    $active_module = 'balances';
}
if (strpos($script, '/modules/payroll/') !== false) {
    $active_module = 'payroll';
}
if (strpos($script, '/weekly_tasks/') !== false) {
    $active_module = 'tasks';
}
if (strpos($script, '/dispatch/') !== false) {
    $active_module = 'dispatch';
}
if (strpos($script, '/todo/') !== false) {
    $active_module = 'todo';
}
if (strpos($script, '/stocks/') !== false) {
    $active_module = 'stocks';
}
if (strpos($script, '/erp/') !== false && strpos($script, 'outstanding-invoices') !== false) {
    $active_module = 'outstanding';
}
if (strpos($script, '/order-tracking/') !== false) {
    $active_module = 'tracking';
}
if (strpos($script, '/attendance/') !== false || strpos($script, 'view-attendance') !== false) {
    $active_module = 'attendance';
}
if (strpos($script, 'notifications.php') !== false) {
    $active_module = $_SESSION['active_module'] ?? 'attendance';
    if (!in_array($active_module, ['attendance', 'voucher', 'sales', 'stocks'], true)) {
        $active_module = 'attendance';
    }
}
if (strpos($script, 'meetings.php') !== false) {
    $active_module = 'meetings';
}
if (strpos($script, 'write-letter') !== false || strpos($script, 'letter-records') !== false || strpos($script, 'manage-letters') !== false) {
    $active_module = 'letters';
}
if (strpos($script, '/modules/email/') !== false) {
    $active_module = 'email';
}

$baseName = strtolower(basename(parse_url($script, PHP_URL_PATH) ?: ''));
if ($baseName === 'revenue.php' || strpos($baseName, 'revenue_') === 0) {
    $active_module = 'revenue';
}

$dashUrl = $isAdmin ? 'admin/dashboard.php' : 'employee/dashboard.php';
$accountPath = 'employee/account.php';
$accountUrl = function_exists('user_profile_settings_url')
    ? user_profile_settings_url($prefix)
    : ($prefix . $accountPath);
// Match sidebar.php global account link: admin â†’ person, employee â†’ gear
$mfBiAccount = 'person';

$links = [];
$mf_active_slug = 'home';

$modUrl = static function (string $path) use ($prefix, $mfStockBasePath, $mfAppRootPath): string {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($mfStockBasePath !== '') {
        $rel = preg_replace('#^stock/#', '', $path);
        $useAppRoot = preg_match('#^(employee|admin|messages|select-module|revenue)#', $rel) === 1
            || preg_match('#^modules/(sales|expenses|finance|balances|payroll)(/|$)#', $rel) === 1
            || preg_match('#^(deliveries|dispatch|attendance|weekly_tasks|todo|order-tracking|erp)/#', $rel) === 1;
        if ($useAppRoot) {
            return $mfAppRootPath !== '' ? $mfAppRootPath . $rel : $prefix . $rel;
        }
        return $mfStockBasePath . $rel;
    }
    if (function_exists('app_url')) {
        return app_url('/' . $path);
    }
    return $prefix . $path;
};

switch ($active_module) {
    case 'voucher':
        $voucher_list_url = $isAdmin
            ? $modUrl('admin/all-vouchers.php?module=voucher')
            : $modUrl('employee/my-vouchers.php?module=voucher');
        $voucherListBi = $isAdmin ? 'collection' : 'ticket-detailed';
        $links = [
            ['slug' => 'home', 'url' => $modUrl($dashUrl . '?module=voucher'), 'label' => 'Home', 'bi' => 'ticket-perforated'],
            ['slug' => 'cart', 'url' => $voucher_list_url, 'label' => 'Vouchers', 'bi' => $voucherListBi],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=voucher'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('employee/create-voucher.php?module=voucher'), 'label' => 'Create', 'bi' => 'file-plus'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'account.php') !== false && stripos($queryString, 'module=voucher') !== false) {
            $mf_active_slug = 'account';
        } elseif (strpos($script, 'create-voucher') !== false) {
            $mf_active_slug = 'settings';
        } elseif (strpos($script, 'my-vouchers') !== false || strpos($script, 'all-vouchers') !== false || strpos($script, 'view-voucher') !== false || strpos($script, 'edit-voucher') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, 'dashboard') !== false) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'modules';
        }
        break;

    case 'revenue':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('revenue_entries.php?module=revenue'), 'label' => 'Home', 'bi' => 'graph-up'],
            ['slug' => 'cart', 'url' => $modUrl('revenue_entries.php?filter=all&module=revenue'), 'label' => 'Entries', 'bi' => 'list-ul'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=revenue'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('revenue_create.php?module=revenue'), 'label' => 'Record', 'bi' => 'plus-circle'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'account.php') !== false && stripos($queryString, 'module=revenue') !== false) {
            $mf_active_slug = 'account';
        } elseif ($baseName === 'revenue_create.php' || strpos($script, 'revenue_create') !== false) {
            $mf_active_slug = 'settings';
        } elseif ($baseName === 'revenue_entries.php' || $baseName === 'revenue_list.php' || strpos($script, 'revenue_list') !== false || strpos($script, 'revenue_entries') !== false) {
            $mf_active_slug = 'cart';
        } elseif ($baseName === 'revenue.php') {
            $mf_active_slug = 'home';
        } elseif (strpos($baseName, 'revenue_') === 0) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'modules';
        }
        break;

    case 'stocks':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('dashboard.php?module=stocks'), 'label' => 'Home', 'bi' => 'bar-chart'],
            ['slug' => 'cart', 'url' => $modUrl('modules/products/index.php?module=stocks'), 'label' => 'Products', 'bi' => 'box'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=stocks'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('settings.php?module=stocks'), 'label' => 'Settings', 'bi' => 'gear'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'stock/settings') !== false) {
            $mf_active_slug = 'settings';
        } elseif (strpos($script, '/stock/modules/') !== false || strpos($script, 'stock/modules') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, '/stock/') !== false) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'sales':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('modules/sales/dashboard/index.php?module=sales'), 'label' => 'Home', 'bi' => 'bar-chart'],
            ['slug' => 'cart', 'url' => $modUrl('modules/sales/orders/index.php?module=sales'), 'label' => 'Orders', 'bi' => 'bag'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=sales'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('modules/sales/orders/create.php?module=sales'), 'label' => 'Quote', 'bi' => 'file-earmark-plus'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'account.php') !== false && stripos($queryString, 'module=sales') !== false) {
            $mf_active_slug = 'account';
        } elseif (strpos($script, '/modules/sales/orders/create') !== false) {
            $mf_active_slug = 'settings';
        } elseif (strpos($script, '/modules/sales/') !== false) {
            $mf_active_slug = strpos($script, 'dashboard') !== false ? 'home' : 'cart';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'payroll':
        if ($financeOrAdmin) {
            $links = [
                ['slug' => 'home', 'url' => $modUrl('modules/payroll/index.php?module=payroll'), 'label' => 'Home', 'bi' => 'speedometer2'],
                ['slug' => 'cart', 'url' => $modUrl('modules/payroll/salaries.php?module=payroll'), 'label' => 'Staff', 'bi' => 'person-badge'],
                ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
                ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=payroll'), 'label' => 'Account', 'bi' => $mfBiAccount],
                ['slug' => 'settings', 'url' => $modUrl('modules/payroll/run_payroll.php?module=payroll'), 'label' => 'Run', 'bi' => 'play-circle'],
            ];
        } else {
            $links = [
                ['slug' => 'home', 'url' => $modUrl('modules/payroll/my_payslips.php?module=payroll'), 'label' => 'Payslips', 'bi' => 'file-text'],
                ['slug' => 'cart', 'url' => $modUrl('modules/payroll/index.php?module=payroll'), 'label' => 'Hub', 'bi' => 'speedometer2'],
                ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
                ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=payroll'), 'label' => 'Account', 'bi' => $mfBiAccount],
                ['slug' => 'settings', 'url' => $modUrl('modules/payroll/settings.php?module=payroll'), 'label' => 'Settings', 'bi' => 'gear'],
            ];
        }
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'run_payroll') !== false) {
            $mf_active_slug = 'settings';
        } elseif (strpos($script, 'my_payslips') !== false) {
            $mf_active_slug = 'home';
        } elseif (strpos($script, '/modules/payroll/') !== false) {
            $mf_active_slug = strpos($script, 'salaries') !== false ? 'cart' : 'home';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'deliveries':
        $dlvQs = 'module=deliveries';
        $mfSlug = trim((string) ($_GET['company_slug'] ?? $_SESSION['company_slug'] ?? ''));
        if ($mfSlug !== '') {
            $dlvQs .= '&company_slug=' . rawurlencode($mfSlug);
        }
        $links = [
            ['slug' => 'home', 'url' => $modUrl('deliveries/index?' . $dlvQs), 'label' => 'Home', 'bi' => 'truck'],
            ['slug' => 'cart', 'url' => $modUrl('deliveries/trips.php?module=deliveries'), 'label' => 'Trips', 'bi' => 'map'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=deliveries'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('deliveries/create_delivery.php?module=deliveries'), 'label' => 'New', 'bi' => 'plus-circle'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'create_delivery') !== false) {
            $mf_active_slug = 'settings';
        } elseif (strpos($script, '/deliveries/') !== false) {
            if ($baseName === 'index.php' || preg_match('#/deliveries/index/?$#', $script)) {
                $mf_active_slug = 'home';
            } else {
                $mf_active_slug = 'cart';
            }
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'dispatch':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('dispatch/index.php?module=dispatch'), 'label' => 'Home', 'bi' => 'truck'],
            ['slug' => 'cart', 'url' => $modUrl('dispatch/routes.php?module=dispatch'), 'label' => 'Routes', 'bi' => 'map'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=dispatch'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('dispatch/create_trip.php?module=dispatch'), 'label' => 'Trip', 'bi' => 'plus-circle'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'create_trip') !== false || $baseName === 'create.php') {
            $mf_active_slug = 'settings';
        } elseif (strpos($script, '/dispatch/') !== false) {
            if ($baseName === 'index.php') {
                $mf_active_slug = 'home';
            } else {
                $mf_active_slug = 'cart';
            }
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'attendance':
        if ($isAdmin) {
            $links = [
                ['slug' => 'home', 'url' => $modUrl('admin/view-attendance.php?module=attendance'), 'label' => 'Records', 'bi' => 'calendar-check'],
                ['slug' => 'cart', 'url' => $modUrl('attendance/index.php?module=attendance'), 'label' => 'Clock', 'bi' => 'clock'],
                ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
                ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=attendance'), 'label' => 'Account', 'bi' => $mfBiAccount],
                ['slug' => 'settings', 'url' => $modUrl('attendance/settings.php?module=attendance'), 'label' => 'Config', 'bi' => 'gear'],
            ];
        } else {
            $links = [
                ['slug' => 'home', 'url' => $modUrl('attendance/index.php?module=attendance'), 'label' => 'Clock', 'bi' => 'clock'],
                ['slug' => 'cart', 'url' => $modUrl('employee/attendance-analytics.php?module=attendance'), 'label' => 'Stats', 'bi' => 'graph-up-arrow'],
                ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
                ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=attendance'), 'label' => 'Account', 'bi' => $mfBiAccount],
                ['slug' => 'settings', 'url' => $modUrl('attendance/index.php?module=attendance'), 'label' => 'Sign', 'bi' => 'clock'],
            ];
        }
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'attendance-analytics') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, 'view-attendance') !== false) {
            $mf_active_slug = 'home';
        } elseif (strpos($script, 'notifications.php') !== false) {
            $mf_active_slug = $isAdmin ? 'cart' : 'home';
        } elseif (strpos($script, '/attendance/') !== false) {
            if (strpos($script, 'settings') !== false) {
                $mf_active_slug = 'settings';
            } elseif ($baseName === 'index.php') {
                // Admin: Clock tab = cart; employee: Clock tab = home
                $mf_active_slug = $isAdmin ? 'cart' : 'home';
            } else {
                $mf_active_slug = 'home';
            }
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'meetings':
        $mtgPath = $isAdmin ? 'admin/meetings.php?module=meetings' : 'employee/meetings.php?module=meetings';
        $links = [
            ['slug' => 'home', 'url' => $modUrl($mtgPath), 'label' => 'Meet', 'bi' => 'camera-video'],
            ['slug' => 'cart', 'url' => $modUrl($mtgPath), 'label' => 'Room', 'bi' => 'camera-video'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=meetings'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl($mtgPath), 'label' => 'Join', 'bi' => 'camera-video'],
        ];
        $mf_active_slug = strpos($script, 'meetings') !== false ? 'home' : 'modules';
        break;

    case 'tasks':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('weekly_tasks/ai_assistant.php?module=tasks'), 'label' => 'AI', 'bi' => 'stars'],
            ['slug' => 'cart', 'url' => $modUrl('weekly_tasks/view_plan.php?module=tasks'), 'label' => 'Plan', 'bi' => 'clipboard-check'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=tasks'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('weekly_tasks/review.php?module=tasks'), 'label' => 'Review', 'bi' => 'check-all'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'view_plan') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, 'weekly_tasks') !== false) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'tracking':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('order-tracking/index.php?module=tracking'), 'label' => 'Track', 'bi' => 'search'],
            ['slug' => 'cart', 'url' => $modUrl('order-tracking/index.php?module=tracking'), 'label' => 'Search', 'bi' => 'search'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=tracking'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('order-tracking/index.php?module=tracking'), 'label' => 'Orders', 'bi' => 'search'],
        ];
        $mf_active_slug = strpos($script, 'order-tracking') !== false ? 'home' : 'modules';
        break;

    case 'outstanding':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('erp/outstanding-invoices/index.php?module=outstanding&tab=receivables'), 'label' => 'Due In', 'bi' => 'cash'],
            ['slug' => 'cart', 'url' => $modUrl('erp/outstanding-invoices/index.php?module=outstanding&tab=payables'), 'label' => 'Due Out', 'bi' => 'credit-card'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=outstanding'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('erp/outstanding-invoices/index.php?module=outstanding'), 'label' => 'All', 'bi' => 'receipt'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (stripos($queryString, 'tab=payables') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, 'outstanding-invoices') !== false) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'home';
        }
        break;


    case 'expenses':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('modules/expenses/index.php?module=expenses'), 'label' => 'Home', 'bi' => 'bar-chart'],
            ['slug' => 'cart', 'url' => $modUrl('modules/expenses/view.php?module=expenses'), 'label' => 'List', 'bi' => 'list-ul'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=expenses'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('modules/expenses/create.php?module=expenses'), 'label' => 'New', 'bi' => 'file-earmark-plus'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, '/modules/expenses/create') !== false) {
            $mf_active_slug = 'settings';
        } elseif (strpos($script, '/modules/expenses/view') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, '/modules/expenses/') !== false) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'finance':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('modules/finance/index.php?module=finance'), 'label' => 'Home', 'bi' => 'pie-chart'],
            ['slug' => 'cart', 'url' => $modUrl('modules/finance/transactions.php?module=finance'), 'label' => 'Lines', 'bi' => 'list-check'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=finance'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('modules/finance/budgets.php?module=finance'), 'label' => 'Budgets', 'bi' => 'wallet2'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, '/modules/finance/') !== false) {
            $mf_active_slug = strpos($script, 'index') !== false ? 'home' : 'cart';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'balances':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('modules/balances/index.php?module=balances'), 'label' => 'Home', 'bi' => 'pie-chart'],
            ['slug' => 'cart', 'url' => $modUrl('modules/balances/transactions.php?module=balances'), 'label' => 'Move', 'bi' => 'list-check'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=balances'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('modules/balances/transfer.php?module=balances'), 'label' => 'Transfer', 'bi' => 'arrow-left-right'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, '/modules/balances/') !== false) {
            $mf_active_slug = strpos($script, 'index') !== false ? 'home' : 'cart';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'todo':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('todo/index.php?module=todo'), 'label' => 'Tasks', 'bi' => 'list-check'],
            ['slug' => 'cart', 'url' => $modUrl('todo/weekly_mission.php?module=todo'), 'label' => 'Mission', 'bi' => 'journal-check'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=todo'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('todo/index.php?module=todo'), 'label' => 'List', 'bi' => 'list-check'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'weekly_mission') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, '/todo/') !== false) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'settings':
        $settingsHome = $modUrl('admin/settings.php?module=settings');
        $links = [
            ['slug' => 'home', 'url' => $settingsHome, 'label' => 'Hub', 'bi' => 'grid'],
            ['slug' => 'cart', 'url' => $modUrl('admin/whatsapp-settings.php?module=settings'), 'label' => 'WA', 'bi' => 'whatsapp'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=settings'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('admin/time-settings.php?module=settings'), 'label' => 'Time', 'bi' => 'clock'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, '/admin/settings') !== false && strpos($script, 'whatsapp') === false && strpos($script, 'time-settings') === false) {
            $mf_active_slug = 'home';
        } elseif (strpos($script, 'whatsapp-settings') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, 'time-settings') !== false) {
            $mf_active_slug = 'settings';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'account':
        $links = [
            ['slug' => 'home', 'url' => $modUrl($dashUrl), 'label' => 'Home', 'bi' => 'house'],
            ['slug' => 'cart', 'url' => $modUrl('messages.php'), 'label' => 'Chat', 'bi' => 'chat-dots'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=account'), 'label' => 'Account', 'bi' => 'person-badge'],
            ['slug' => 'settings', 'url' => $modUrl('employee/system-settings.php?module=account'), 'label' => 'Prefs', 'bi' => 'gear'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'system-settings') !== false) {
            $mf_active_slug = 'settings';
        } elseif (strpos($script, 'account') !== false) {
            $mf_active_slug = 'account';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'letters':
        $lettersActionBi = $isAdmin ? 'kanban' : 'pencil-square';
        $links = [
            ['slug' => 'home', 'url' => $modUrl('write-letter.php?module=letters'), 'label' => 'Write', 'bi' => 'pencil-square'],
            ['slug' => 'cart', 'url' => $modUrl('letter-records.php?module=letters'), 'label' => 'Records', 'bi' => 'folder2-open'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=letters'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $isAdmin ? $modUrl('manage-letters.php?module=letters') : $modUrl('write-letter.php?module=letters'), 'label' => $isAdmin ? 'Manage' : 'New', 'bi' => $lettersActionBi],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'letter-records') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, 'write-letter') !== false || strpos($script, 'manage-letters') !== false) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    case 'email':
        $links = [
            ['slug' => 'home', 'url' => $modUrl('modules/email/index.php?folder=inbox&module=email'), 'label' => 'Inbox', 'bi' => 'inbox'],
            ['slug' => 'cart', 'url' => $modUrl('modules/email/index.php?folder=sent&module=email'), 'label' => 'Sent', 'bi' => 'send'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $modUrl($accountPath . '?module=email'), 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $modUrl('modules/email/compose.php?module=email'), 'label' => 'Compose', 'bi' => 'pencil-square'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'account.php') !== false && stripos($queryString, 'module=email') !== false) {
            $mf_active_slug = 'account';
        } elseif (strpos($script, '/modules/email/compose') !== false) {
            $mf_active_slug = 'settings';
        } elseif (stripos($queryString, 'folder=sent') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, '/modules/email/') !== false) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'home';
        }
        break;

    default:
        $links = [
            ['slug' => 'home', 'url' => $modUrl($dashUrl), 'label' => 'Home', 'bi' => 'house'],
            ['slug' => 'cart', 'url' => $modUrl('messages.php'), 'label' => 'Chat', 'bi' => 'chat-dots'],
            ['slug' => 'modules', 'url' => $modUrl('select-module.php'), 'label' => 'Modules', 'bi' => 'grid'],
            ['slug' => 'account', 'url' => $accountUrl, 'label' => 'Account', 'bi' => $mfBiAccount],
            ['slug' => 'settings', 'url' => $isAdmin ? $modUrl('admin/settings.php?module=settings') : $modUrl('employee/system-settings.php?module=account'), 'label' => 'Settings', 'bi' => 'gear'],
        ];
        if (strpos($script, 'select-module') !== false) {
            $mf_active_slug = 'modules';
        } elseif (strpos($script, 'messages') !== false) {
            $mf_active_slug = 'cart';
        } elseif (strpos($script, 'account.php') !== false || strpos($script, 'employee/account') !== false) {
            $mf_active_slug = 'account';
        } elseif (strpos($script, 'system-settings') !== false || strpos($script, 'admin/settings') !== false) {
            $mf_active_slug = 'settings';
        } elseif (strpos($script, 'dashboard') !== false) {
            $mf_active_slug = 'home';
        } else {
            $mf_active_slug = 'home';
        }
        break;
}

$use_modern_five = true;
$unreadMsgs = (int) (getUnreadMessagesCountForCurrentUser() ?? 0);

$nav_class = 'mobile-footer mf-floating-instagram';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" crossorigin="anonymous">
<style>
@media (max-width: 767.98px) {
  .mobile-footer.mf-floating-instagram {
    position: fixed;
    bottom: calc(16px + env(safe-area-inset-bottom, 0));
    left: 50%;
    transform: translateX(-50%);
    z-index: 10120;
    width: max-content;
    min-width: 290px;
    max-width: calc(100% - 32px);
    height: 54px;
    padding: 0 4px;
    background: rgba(255, 255, 255, 0.88); /* White in white theme */
    backdrop-filter: blur(20px) saturate(190%);
    -webkit-backdrop-filter: blur(20px) saturate(190%);
    border-radius: 999px;
    border: 1px solid rgba(0, 0, 0, 0.08); /* Darker border for white theme */
    box-shadow: 0 8px 32px rgba(15, 23, 42, 0.12); /* Softer shadow */
    display: block;
    overflow: visible;
    transition: background 0.3s, border 0.3s, box-shadow 0.3s;
  }
  .mobile-footer.mf-floating-instagram .bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 100%;
    gap: 6px;
    padding: 0 6px;
    margin: 0;
  }
  .mobile-footer.mf-floating-instagram a.mf-item {
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    height: 44px;
    min-width: 48px;
    border-radius: 999px;
    color: rgba(0, 0, 0, 0.55); /* Dark icons in light theme */
    position: relative;
    z-index: 1;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
  }
  .mobile-footer.mf-floating-instagram a.mf-item:active {
    background: rgba(0, 0, 0, 0.04);
  }
  .mobile-footer.mf-floating-instagram a.mf-item.is-active {
    background: rgba(0, 0, 0, 0.08); /* Grey capsule in white theme */
    color: #000000 !important;
    min-width: 56px;
  }
  .mobile-footer.mf-floating-instagram .icon {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.45rem;
    line-height: 1;
  }
  .mobile-footer.mf-floating-instagram .label {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
  }
  .mobile-footer.mf-floating-instagram .mf-avatar {
    width: 26px !important;
    height: 26px !important;
    max-width: 26px !important;
    max-height: 26px !important;
    aspect-ratio: 1 / 1 !important;
    border-radius: 50% !important;
    border: 1.5px solid rgba(0, 0, 0, 0.35) !important; /* Darker border for light theme */
    object-fit: cover !important;
    transition: border-color 0.2s;
  }
  .mobile-footer.mf-floating-instagram .mf-avatar.is-active {
    border-color: #000000 !important;
  }
  .mobile-footer.mf-floating-instagram .mf-avatar-fallback {
    width: 26px !important;
    height: 26px !important;
    max-width: 26px !important;
    max-height: 26px !important;
    aspect-ratio: 1 / 1 !important;
    border-radius: 50% !important;
    border: 1.5px solid rgba(0, 0, 0, 0.35) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 10px !important;
    font-weight: bold !important;
    background: rgba(0, 0, 0, 0.05) !important;
    color: #000000 !important;
  }
  .mobile-footer.mf-floating-instagram .mf-avatar-fallback.is-active {
    border-color: #000000 !important;
    background: rgba(0, 0, 0, 0.12) !important;
  }
  .mobile-footer.mf-floating-instagram .mf-dot {
    position: absolute;
    bottom: 8px;
    right: 12px;
    width: 6px;
    height: 6px;
    background-color: #ef4444;
    border-radius: 50%;
    border: 1px solid #ffffff;
  }
  body.has-mobile-footer {
    padding-bottom: calc(86px + env(safe-area-inset-bottom, 0));
  }

  /* Dark theme overrides */
  html[data-theme="dark"] .mobile-footer.mf-floating-instagram {
    background: rgba(18, 18, 18, 0.85); /* Dark in dark theme */
    border: 1px solid rgba(255, 255, 255, 0.12);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
  }
  html[data-theme="dark"] .mobile-footer.mf-floating-instagram a.mf-item {
    color: rgba(255, 255, 255, 0.55);
  }
  html[data-theme="dark"] .mobile-footer.mf-floating-instagram a.mf-item:active {
    background: rgba(255, 255, 255, 0.08);
  }
  html[data-theme="dark"] .mobile-footer.mf-floating-instagram a.mf-item.is-active {
    background: rgba(255, 255, 255, 0.15); /* Capsule highlight in dark theme */
    color: #ffffff !important;
  }
  html[data-theme="dark"] .mobile-footer.mf-floating-instagram .mf-avatar {
    border: 1.5px solid rgba(255, 255, 255, 0.5) !important;
  }
  html[data-theme="dark"] .mobile-footer.mf-floating-instagram .mf-avatar.is-active {
    border-color: #ffffff !important;
  }
  html[data-theme="dark"] .mobile-footer.mf-floating-instagram .mf-avatar-fallback {
    border: 1.5px solid rgba(255, 255, 255, 0.5) !important;
    background: rgba(255, 255, 255, 0.1) !important;
    color: #ffffff !important;
  }
  html[data-theme="dark"] .mobile-footer.mf-floating-instagram .mf-avatar-fallback.is-active {
    border-color: #ffffff !important;
    background: rgba(255, 255, 255, 0.25) !important;
  }
  html[data-theme="dark"] .mobile-footer.mf-floating-instagram .mf-dot {
    border: 1px solid #121212;
  }
}
@media (min-width: 768px) { .mobile-footer { display: none; } }
@media print { .mobile-footer { display: none !important; } }
</style>
<nav class="<?= htmlspecialchars($nav_class) ?>" role="navigation" aria-label="Mobile navigation">
  <div class="bar">
    <?php foreach ($links as $link): ?>
      <?php
        $is_active = $use_modern_five && isset($link['slug']) && ($link['slug'] === $mf_active_slug);
        $item_class = 'mf-item' . ($is_active ? ' is-active' : '');
        
        $biRaw = (string) ($link['bi'] ?? 'circle');
        $biClass = preg_match('/^[a-z0-9-]{1,64}$/', $biRaw) ? $biRaw : 'circle';
        
        // Map certain active icons to their filled versions
        if ($is_active) {
            $fillable = ['house', 'bag', 'chat-dots', 'person', 'person-badge', 'gear', 'bar-chart', 'box', 'truck', 'map', 'calendar-check', 'clock', 'camera-video', 'clipboard-check', 'wallet', 'wallet2', 'receipt', 'check-all', 'whatsapp', 'file-text', 'file-earmark-plus', 'plus-circle', 'inbox', 'star', 'pencil-square', 'send'];
            if (in_array($biClass, $fillable, true)) {
                $biClass .= '-fill';
            }
        }
      ?>
      <a class="<?= htmlspecialchars($item_class) ?>" href="<?= htmlspecialchars($link['url']) ?>" title="<?= htmlspecialchars($link['label']) ?>" aria-label="<?= htmlspecialchars($link['label']) ?>"<?= $is_active ? ' aria-current="page"' : '' ?>>
        <span class="icon" aria-hidden="true">
          <?php if (($link['slug'] ?? '') === 'account'): ?>
            <span class="mf-avatar-fallback<?= $is_active ? ' is-active' : '' ?>" style="display: flex !important; align-items: center !important; justify-content: center !important; position: relative !important; overflow: hidden !important; width: 26px !important; height: 26px !important; min-width: 26px !important; min-height: 26px !important; max-width: 26px !important; max-height: 26px !important; flex-shrink: 0 !important; aspect-ratio: 1/1 !important; border-radius: 50% !important;">
              <i class="bi bi-<?= htmlspecialchars($biClass, ENT_QUOTES, 'UTF-8') ?>" style="font-size: 14px !important; line-height: 1 !important;"></i>
              <?php if (!empty($userPhotoUrl)): ?>
                <img src="<?= htmlspecialchars($userPhotoUrl) ?>" class="mf-avatar<?= $is_active ? ' is-active' : '' ?>" alt="Profile" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50% !important;" onerror="this.remove();">
              <?php endif; ?>
            </span>
          <?php else: ?>
            <i class="bi bi-<?= htmlspecialchars($biClass, ENT_QUOTES, 'UTF-8') ?>"></i>
          <?php endif; ?>
        </span>
        <?php if (($link['slug'] ?? '') === 'cart' && ($link['bi'] ?? '') === 'chat-dots' && $unreadMsgs > 0): ?>
          <span class="mf-dot"></span>
        <?php endif; ?>
        <span class="label"><?= htmlspecialchars($link['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>
<script>
  (function(){
    function applyFooterPadding(){
      if (window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches) {
        document.body.classList.add('has-mobile-footer');
      } else {
        document.body.classList.remove('has-mobile-footer');
      }
    }
    function mountFooterNav(){
      var nav = document.querySelector('nav.mobile-footer');
      if (nav && nav.parentElement !== document.body) {
        document.body.appendChild(nav);
      }
    }
    applyFooterPadding();
    mountFooterNav();
    window.addEventListener('resize', function(){
      clearTimeout(window.__mfResizeTimer);
      window.__mfResizeTimer = setTimeout(function(){
        applyFooterPadding();
        mountFooterNav();
      }, 120);
    });
  })();
</script>
