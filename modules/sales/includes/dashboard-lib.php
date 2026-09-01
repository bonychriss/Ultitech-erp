<?php

declare(strict_types=1);

function dashboardDeskBootstrap(): void
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 3) . '/includes/config.php';
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
        require_once dirname(__DIR__) . '/functions.php';
        $booted = true;
    }
}

function dashboardDeskRequireAccess(): void
{
    dashboardDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
    $_SESSION['active_module'] = 'sales';
}

function dashboardDeskModuleQuery(): string
{
    $module = strtolower(trim((string) ($_GET['module'] ?? 'sales')));

    return $module !== '' ? $module : 'sales';
}

function dashboardDeskWebBase(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }

    return sales_app_url('modules/sales/dashboard');
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function dashboardDeskLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/dashboard/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;
    $base = dashboardDeskWebBase();
    $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
    $jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();
    $assetVersion = (string) max((int) $cssVersion, (int) $jsVersion, (int) filemtime($distIndex));

    return [
        'distHtml' => $distHtml,
        'assetBase' => $base . '/frontend/dist/assets/',
        'apiUrl' => $base . '/api',
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => $assetVersion,
        'jsVersion' => $assetVersion,
    ];
}

function dashboardDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 3) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    $dashCssPath = dirname(__DIR__) . '/dashboard/dashboard.css';
    $dashCssVer = is_file($dashCssPath) ? (int) filemtime($dashCssPath) : time();
    $dashCssUrl = dashboardDeskWebBase() . '/dashboard.css?v=' . $dashCssVer;
    $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars($dashCssUrl, ENT_QUOTES, 'UTF-8') . '">';

    return implode("\n    ", $parts);
}

/**
 * @param array<string, mixed> $product
 * @return array<string, mixed>
 */
function dashboardDeskNormalizeProductRow(array $product, string $placeholderIcon = 'fa-box'): array
{
    if (function_exists('sales_load_stock_image_helpers')) {
        sales_load_stock_image_helpers();
    }

    $productId = (int) ($product['product_id'] ?? 0);
    $imageFile = trim((string) ($product['main_image'] ?? ''));
    if ($imageFile !== '' && preg_match('/^placeholder\.(jpe?g|png|gif|webp)$/i', $imageFile)) {
        $imageFile = '';
    }
    if ($productId > 0 && function_exists('sales_order_item_image_name')) {
        $salesDb = function_exists('sales_pdo') ? sales_pdo() : null;
        $resolvedFile = sales_order_item_image_name($product, $salesDb instanceof PDO ? $salesDb : null);
        if ($resolvedFile !== '') {
            $imageFile = $resolvedFile;
        }
    }

    $imageUrl = '';
    if ($productId > 0 && function_exists('stock_product_list_image_url')) {
        $imageUrl = stock_product_list_image_url($productId, $imageFile, 'thumbnail', '');
    }

    $rating = rand(35, 50) / 10;

    return [
        'product_id' => $productId,
        'product_name' => (string) ($product['product_name'] ?? 'Product'),
        'outgoing_count' => (int) ($product['outgoing_count'] ?? 0),
        'total_qty' => (float) ($product['total_qty'] ?? 0),
        'top_customer_name' => (string) ($product['top_customer_name'] ?? ''),
        'image_url' => $imageUrl,
        'placeholder_icon' => $placeholderIcon,
        'rating' => $rating,
    ];
}

/**
 * @param list<array<string, mixed>> $products
 * @return list<array<string, mixed>>
 */
function dashboardDeskNormalizeProducts(array $products, string $placeholderIcon = 'fa-box'): array
{
    $out = [];
    foreach ($products as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = dashboardDeskNormalizeProductRow($row, $placeholderIcon);
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function dashboardDeskNormalizeLeaderboard(array $rows, float $targetAmount = 300000000): array
{
    $out = [];
    foreach ($rows as $rep) {
        if (!is_array($rep)) {
            continue;
        }
        $username = (string) ($rep['username'] ?? 'Unknown');
        $currentSales = (float) ($rep['total_sold'] ?? 0);
        $profilePhoto = trim((string) ($rep['profile_photo'] ?? ''));
        $avatarUrl = '';
        if ($profilePhoto !== '' && function_exists('app_url')) {
            $avatarUrl = app_url('/' . ltrim(str_replace('\\', '/', $profilePhoto), '/'));
        }

        $out[] = [
            'username' => $username,
            'total_sold' => $currentSales,
            'initial' => strtoupper(substr($username !== '' ? $username : '?', 0, 1)),
            'avatar_url' => $avatarUrl,
            'progress_percent' => min(100.0, ($currentSales / max(1.0, $targetAmount)) * 100),
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $activities
 * @return list<array<string, mixed>>
 */
function dashboardDeskNormalizeActivities(array $activities, string $module): array
{
    $out = [];
    foreach ($activities as $act) {
        if (!is_array($act)) {
            continue;
        }
        $isOrder = ($act['type'] ?? '') === 'order';
        $id = (int) ($act['id'] ?? 0);
        $link = $isOrder
            ? sales_module_url('orders/view.php', ['id' => $id, 'module' => $module])
            : sales_module_url('invoices/view.php', ['id' => $id, 'module' => $module]);

        $out[] = [
            'type' => (string) ($act['type'] ?? ''),
            'ref_number' => (string) ($act['ref_number'] ?? ''),
            'customer_name' => (string) ($act['customer_name'] ?? ''),
            'total_amount' => (float) ($act['total_amount'] ?? 0),
            'created_at' => (string) ($act['created_at'] ?? ''),
            'url' => $link,
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function dashboardDeskBuildKpiSummaries(
    string $module,
    int $userId,
    bool $isAdmin,
    string $month,
    float $salesTotal,
    float $lastMonthSales,
    int $salesTrend,
    int $pendingOrders,
    int $pendingNewToday,
    int $overdueInvoices,
    float $monthlySalesDisplay,
    float $commissionEarned,
    array $funnelStats
): array {
    $pdo = sales_pdo();
    $currentPeriod = date('F Y', strtotime($month . '-01'));
    $lastPeriod = date('F Y', strtotime($month . '-01 -1 month'));

    $invoiceCountGlobal = 0;
    $invoiceCountUser = 0;
    try {
        $sqlGlobal = "SELECT COUNT(*) FROM invoices WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'";
        $paramsGlobal = [$month];
        salesAppendCompanyScope($sqlGlobal, $paramsGlobal, 'invoices');
        $stmt = $pdo->prepare($sqlGlobal);
        $stmt->execute($paramsGlobal);
        $invoiceCountGlobal = (int) ($stmt->fetchColumn() ?: 0);

        if ($userId > 0) {
            $sqlUser = "SELECT COUNT(*) FROM invoices WHERE created_by = ? AND DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'";
            $paramsUser = [$userId, $month];
            salesAppendCompanyScope($sqlUser, $paramsUser, 'invoices');
            $stmtUser = $pdo->prepare($sqlUser);
            $stmtUser->execute($paramsUser);
            $invoiceCountUser = (int) ($stmtUser->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {
        error_log('dashboard kpi invoice counts: ' . $e->getMessage());
    }

    $avgInvoice = $invoiceCountGlobal > 0 ? $salesTotal / $invoiceCountGlobal : 0.0;
    $salesDelta = $salesTotal - $lastMonthSales;

    $recentInvoices = [];
    try {
        $sqlInv = "
            SELECT i.id, i.invoice_number, i.total_amount, i.created_at, c.company_name
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            WHERE DATE_FORMAT(i.created_at, '%Y-%m') = ?
              AND i.status != 'cancelled'";
        $paramsInv = [$month];
        salesAppendCompanyScope($sqlInv, $paramsInv, 'invoices', 'i');
        $sqlInv .= ' ORDER BY i.created_at DESC LIMIT 5';
        $stmtInv = $pdo->prepare($sqlInv);
        $stmtInv->execute($paramsInv);
        foreach ($stmtInv->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $recentInvoices[] = [
                'ref_number' => (string) ($row['invoice_number'] ?? ''),
                'customer_name' => (string) ($row['company_name'] ?? ''),
                'total_amount' => (float) ($row['total_amount'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'url' => sales_module_url('invoices/view.php', ['id' => (int) ($row['id'] ?? 0), 'module' => $module]),
            ];
        }
    } catch (Throwable $e) {
        error_log('dashboard kpi recent invoices: ' . $e->getMessage());
    }

    $recentPending = [];
    try {
        $sqlPending = "
            SELECT so.id, so.order_number, so.total_amount, so.status, so.created_at, c.company_name
            FROM sales_orders so
            JOIN customers c ON c.id = so.customer_id
            WHERE so.status IN ('draft', 'quotation')";
        $paramsPending = [];
        salesAppendCompanyScope($sqlPending, $paramsPending, 'sales_orders', 'so');
        $sqlPending .= ' ORDER BY so.created_at DESC LIMIT 5';
        $stmtPending = $pdo->prepare($sqlPending);
        $stmtPending->execute($paramsPending);
        foreach ($stmtPending->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $recentPending[] = [
                'ref_number' => (string) ($row['order_number'] ?? ''),
                'customer_name' => (string) ($row['company_name'] ?? ''),
                'total_amount' => (float) ($row['total_amount'] ?? 0),
                'status' => (string) ($row['status'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'url' => sales_module_url('orders/view.php', ['id' => (int) ($row['id'] ?? 0), 'module' => $module]),
            ];
        }
    } catch (Throwable $e) {
        error_log('dashboard kpi pending orders: ' . $e->getMessage());
    }

    $overdueTotal = 0.0;
    $recentOverdue = [];
    try {
        $sqlOverdueSum = "SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status = 'overdue'";
        $paramsSum = [];
        salesAppendCompanyScope($sqlOverdueSum, $paramsSum, 'invoices');
        $stmtSum = $pdo->prepare($sqlOverdueSum);
        $stmtSum->execute($paramsSum);
        $overdueTotal = (float) ($stmtSum->fetchColumn() ?: 0);

        $sqlOverdue = "
            SELECT i.id, i.invoice_number, i.total_amount, i.due_date, c.company_name
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            WHERE i.status = 'overdue'";
        $paramsOverdue = [];
        salesAppendCompanyScope($sqlOverdue, $paramsOverdue, 'invoices', 'i');
        $sqlOverdue .= ' ORDER BY i.due_date ASC LIMIT 5';
        $stmtOverdue = $pdo->prepare($sqlOverdue);
        $stmtOverdue->execute($paramsOverdue);
        foreach ($stmtOverdue->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $recentOverdue[] = [
                'ref_number' => (string) ($row['invoice_number'] ?? ''),
                'customer_name' => (string) ($row['company_name'] ?? ''),
                'total_amount' => (float) ($row['total_amount'] ?? 0),
                'due_date' => (string) ($row['due_date'] ?? ''),
                'url' => sales_module_url('invoices/view.php', ['id' => (int) ($row['id'] ?? 0), 'module' => $module]),
            ];
        }
    } catch (Throwable $e) {
        error_log('dashboard kpi overdue: ' . $e->getMessage());
    }

    $drafts = (int) ($funnelStats['drafts'] ?? 0);
    $quotes = (int) ($funnelStats['quotes'] ?? 0);

    return [
        'monthly_sales' => [
            'title' => 'Monthly Sales',
            'headline' => $salesTotal,
            'headline_format' => 'money',
            'period_label' => $currentPeriod,
            'stats' => [
                ['label' => 'Period', 'value' => $currentPeriod],
                ['label' => 'Total this month', 'value' => $salesTotal, 'format' => 'money'],
                ['label' => 'Last month (' . $lastPeriod . ')', 'value' => $lastMonthSales, 'format' => 'money'],
                ['label' => 'Change vs last month', 'value' => $salesTrend, 'format' => 'percent_signed'],
                ['label' => 'Difference', 'value' => $salesDelta, 'format' => 'money_signed'],
                ['label' => 'Invoices this month', 'value' => $invoiceCountGlobal, 'format' => 'number'],
                ['label' => 'Average invoice', 'value' => $avgInvoice, 'format' => 'money'],
            ],
            'items' => $recentInvoices,
            'items_heading' => 'Recent invoices this month',
            'action_url' => sales_module_url('invoices/index.php', ['module' => $module]),
            'action_label' => 'View all invoices',
        ],
        'pending_orders' => [
            'title' => 'Pending Orders',
            'headline' => $pendingOrders,
            'headline_format' => 'number',
            'period_label' => 'Draft & quotation',
            'stats' => [
                ['label' => 'Total pending', 'value' => $pendingOrders, 'format' => 'number'],
                ['label' => 'Drafts', 'value' => $drafts, 'format' => 'number'],
                ['label' => 'Quotations', 'value' => $quotes, 'format' => 'number'],
                ['label' => 'New today', 'value' => $pendingNewToday, 'format' => 'number'],
            ],
            'items' => $recentPending,
            'items_heading' => 'Latest pending orders',
            'action_url' => sales_module_url('orders/index.php', ['module' => $module]),
            'action_label' => 'View all orders',
        ],
        'overdue_invoices' => [
            'title' => 'Overdue Invoices',
            'headline' => $overdueInvoices,
            'headline_format' => 'number',
            'period_label' => 'Requires follow-up',
            'stats' => [
                ['label' => 'Overdue count', 'value' => $overdueInvoices, 'format' => 'number'],
                ['label' => 'Total overdue amount', 'value' => $overdueTotal, 'format' => 'money'],
            ],
            'items' => $recentOverdue,
            'items_heading' => $overdueInvoices > 0 ? 'Oldest overdue invoices' : 'No overdue invoices',
            'action_url' => sales_module_url('invoices/index.php', ['module' => $module, 'status' => 'overdue']),
            'action_label' => 'View overdue invoices',
        ],
        'monthly_sales_scope' => [
            'title' => $isAdmin ? 'Total Monthly Sales' : 'Monthly Sales',
            'headline' => $monthlySalesDisplay,
            'headline_format' => 'money',
            'period_label' => $currentPeriod,
            'stats' => array_values(array_filter([
                ['label' => 'Scope', 'value' => $isAdmin ? 'All sales this month' : 'Your sales this month', 'format' => 'text'],
                ['label' => $isAdmin ? 'Company total' : 'Your total', 'value' => $monthlySalesDisplay, 'format' => 'money'],
                !$isAdmin ? ['label' => 'Company total', 'value' => $salesTotal, 'format' => 'money'] : null,
                ['label' => $isAdmin ? 'Invoices (company)' : 'Your invoices', 'value' => $isAdmin ? $invoiceCountGlobal : $invoiceCountUser, 'format' => 'number'],
                $commissionEarned > 0 ? ['label' => 'Commission earned', 'value' => $commissionEarned, 'format' => 'money'] : null,
                ['label' => 'Share of company sales', 'value' => $salesTotal > 0 ? round(($monthlySalesDisplay / $salesTotal) * 100, 1) : 0, 'format' => 'percent'],
            ])),
            'items' => $recentInvoices,
            'items_heading' => 'Recent invoices this month',
            'action_url' => sales_module_url('invoices/index.php', ['module' => $module]),
            'action_label' => 'View invoices',
        ],
    ];
}

function dashboardDeskPendingNewToday(): int
{
    try {
        $pdo = sales_pdo();
        $sql = "
            SELECT COUNT(*)
            FROM sales_orders
            WHERE status IN ('draft', 'quotation')
              AND DATE(created_at) = CURDATE()";
        $params = [];
        salesAppendCompanyScope($sql, $params, 'sales_orders');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array<string, mixed>
 */
function dashboardInitData(): array
{
    $module = dashboardDeskModuleQuery();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $userRole = (string) ($_SESSION['role'] ?? '');
    $isAdmin = $userRole === 'admin';
    $month = date('Y-m');
    $year = date('Y');

    $success = '';
    $error = '';
    if (isset($_SESSION['success'])) {
        $success = (string) $_SESSION['success'];
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        $error = (string) $_SESSION['error'];
        unset($_SESSION['error']);
    }

    $companyInfo = function_exists('getCompanyInfo') ? getCompanyInfo() : [];
    $companyName = (string) ($companyInfo['company_name'] ?? 'Ultimate General Trading');
    $companyTheme = (string) ($companyInfo['theme_color'] ?? '#3b82f6');

    $salesTotal = 0.0;
    $pendingOrders = 0;
    $overdueInvoices = 0;
    $companyYearlyTarget = 0.0;
    $companyYearlySales = 0.0;
    $commissionEarned = 0.0;
    $monthlySalesDisplay = 0.0;
    $salesLeaderboard = [];
    $isRoadmasterDashboard = function_exists('isRoadmaster') && isRoadmaster();
    $mostSoldTrucks = [];
    $mostSoldSpares = [];
    $mostOutgoingProducts = [];
    $mostSoldLookbackDays = $isRoadmasterDashboard ? 365 : 30;
    $recentActivities = [];
    $funnelStats = ['drafts' => 0, 'quotes' => 0, 'confirmed' => 0, 'invoiced' => 0];
    $salesTrend = 0;
    $revenueGrowth = ['day' => [], 'weekly' => [], 'monthly' => []];
    $quoteGrowth = ['day' => [], 'weekly' => [], 'monthly' => []];

    try {
        $salesTotal = (float) (getGlobalSalesTotal($month) ?: 0);
        $pendingOrders = (int) (getGlobalPendingOrders() ?: 0);
        $overdueInvoices = (int) (getGlobalOverdueInvoices() ?: 0);
        $companyYearlyTarget = (float) (getGlobalYearlyTarget($year) ?: 0);
        $companyYearlySales = (float) (getGlobalYearlySales($year) ?: 0);
        $commissionEarned = (float) (getCommissionEarned($userId, $month) ?: 0);
        $monthlySalesDisplay = $isAdmin ? $salesTotal : (float) (getUserMonthlySales($userId, $month) ?: 0);
        $salesLeaderboard = getSalesLeaderboard(10) ?: [];
        $recentActivities = getRecentActivities(10) ?: [];
    } catch (Throwable $e) {
        error_log('sales dashboard metrics: ' . $e->getMessage());
        if (isset($_GET['debug']) && $_GET['debug'] === '1') {
            $error = 'Dashboard data error: ' . $e->getMessage();
        }
    }

    try {
        if ($isRoadmasterDashboard) {
            $mostSoldTrucks = getMostOutgoingProducts(6, $mostSoldLookbackDays, 'truck') ?: [];
            $mostSoldSpares = getMostOutgoingProducts(6, $mostSoldLookbackDays, 'spare') ?: [];
        } else {
            $mostOutgoingProducts = getMostOutgoingProducts(20, $mostSoldLookbackDays) ?: [];
        }
    } catch (Throwable $e) {
        error_log('sales dashboard most sold: ' . $e->getMessage());
        if (isset($_GET['debug']) && $_GET['debug'] === '1' && $error === '') {
            $error = 'Most sold products error: ' . $e->getMessage();
        }
    }

    try {
        $funnelStats = getSalesFunnelStats($month);
    } catch (Throwable $e) {
        error_log('sales dashboard charts: ' . $e->getMessage());
        if (isset($_GET['debug']) && $_GET['debug'] === '1' && $error === '') {
            $error = 'Dashboard chart error: ' . $e->getMessage();
        }
    }

    try {
        $revenueGrowth = getRevenueGrowthSeries();
        $quoteGrowth = getQuoteGrowthSeries();
    } catch (Throwable $e) {
        error_log('sales dashboard revenue growth: ' . $e->getMessage());
    }

    $lastMonth = date('Y-m', strtotime('-1 month'));
    $lastMonthSales = 0.0;
    try {
        $lastMonthSales = (float) (getGlobalSalesTotal($lastMonth) ?: 0);
    } catch (Throwable $e) {
        /* ignore */
    }
    $salesTrend = $lastMonthSales > 0
        ? (int) round((($salesTotal - $lastMonthSales) / $lastMonthSales) * 100)
        : 0;

    $pendingNewToday = dashboardDeskPendingNewToday();

    $leaderboardTarget = 300000000.0;
    $companyRemaining = max(0.0, $companyYearlyTarget - $companyYearlySales);
    $targetPct = $companyYearlyTarget > 0
        ? min(100.0, ($companyYearlySales / $companyYearlyTarget) * 100)
        : 0.0;

    $displayName = (string) ($_SESSION['username'] ?? $_SESSION['full_name'] ?? 'Admin');

    $maxCount = max(1, (int) $funnelStats['drafts'], (int) $funnelStats['quotes'], (int) $funnelStats['confirmed'], (int) $funnelStats['invoiced']);
    $pipelinePct = [
        'draft' => (int) $funnelStats['drafts'] > 0 ? min(100, ((int) $funnelStats['drafts'] / $maxCount) * 100) : 15,
        'quote' => (int) $funnelStats['quotes'] > 0 ? min(100, ((int) $funnelStats['quotes'] / $maxCount) * 100) : 35,
        'confirmed' => (int) $funnelStats['confirmed'] > 0 ? min(100, ((int) $funnelStats['confirmed'] / $maxCount) * 100) : 65,
        'invoiced' => (int) $funnelStats['invoiced'] > 0 ? min(100, ((int) $funnelStats['invoiced'] / $maxCount) * 100) : 45,
    ];

    return [
        'module' => $module,
        'user' => [
            'id' => $userId,
            'display_name' => $displayName,
            'is_admin' => $isAdmin,
        ],
        'company' => [
            'name' => $companyName,
            'theme_color' => $companyTheme,
        ],
        'flash' => [
            'success' => $success,
            'error' => $error,
        ],
        'metrics' => [
            'sales_total' => $salesTotal,
            'pending_orders' => $pendingOrders,
            'overdue_invoices' => $overdueInvoices,
            'monthly_sales_display' => $monthlySalesDisplay,
            'sales_trend' => $salesTrend,
            'commission_earned' => $commissionEarned,
            'pending_new_today' => $pendingNewToday,
        ],
        'funnel' => [
            'drafts' => (int) ($funnelStats['drafts'] ?? 0),
            'quotes' => (int) ($funnelStats['quotes'] ?? 0),
            'confirmed' => (int) ($funnelStats['confirmed'] ?? 0),
            'invoiced' => (int) ($funnelStats['invoiced'] ?? 0),
            'progress_percent' => $pipelinePct,
        ],
        'revenue_growth' => $revenueGrowth,
        'quote_growth' => $quoteGrowth,
        'recent_activities' => dashboardDeskNormalizeActivities(array_slice($recentActivities, 0, 6), $module),
        'leaderboard' => dashboardDeskNormalizeLeaderboard(array_slice($salesLeaderboard, 0, 8), $leaderboardTarget),
        'leaderboard_target' => $leaderboardTarget,
        'yearly' => [
            'target' => $companyYearlyTarget,
            'sales' => $companyYearlySales,
            'remaining' => $companyRemaining,
            'percent' => $targetPct,
            'year' => $year,
        ],
        'is_roadmaster' => $isRoadmasterDashboard,
        'most_sold_lookback_days' => $mostSoldLookbackDays,
        'most_sold_trucks' => dashboardDeskNormalizeProducts($mostSoldTrucks, 'fa-truck'),
        'most_sold_spares' => dashboardDeskNormalizeProducts($mostSoldSpares, 'fa-cog'),
        'most_outgoing_products' => dashboardDeskNormalizeProducts(array_slice($mostOutgoingProducts, 0, 6), 'fa-box'),
        'kpi_summaries' => dashboardDeskBuildKpiSummaries(
            $module,
            $userId,
            $isAdmin,
            $month,
            $salesTotal,
            $lastMonthSales,
            $salesTrend,
            $pendingOrders,
            $pendingNewToday,
            $overdueInvoices,
            $monthlySalesDisplay,
            $commissionEarned,
            $funnelStats
        ),
        'urls' => [
            'new_quote' => sales_module_url('orders/create.php', ['module' => $module]),
        ],
    ];
}

function dashboardRenderReactShell(): void
{
    $assets = dashboardDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Sales Dashboard</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Sales Dashboard</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>modules/sales/dashboard/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Sales Dashboard';
    $employeeHeaderTitle = 'Sales Dashboard';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';

    $init = dashboardInitData();
    $themeColor = (string) ($init['company']['theme_color'] ?? '#3b82f6');

    $cfg = [
        'module' => dashboardDeskModuleQuery(),
        'theme_color' => $themeColor,
    ];

    $dashboardHeadMarkup = '<link rel="stylesheet" crossorigin href="'
        . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8')
        . '">'
        . "\n" . '<style>:root { --accent-blue: ' . htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') . ' !important; --brand-blue: '
        . htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') . '; }</style>'
        . "\n" . '<script>window.__SALES_DASHBOARD_API_BASE__ = '
        . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES)
        . ';window.__SALES_DASHBOARD_CFG__ = '
        . json_encode($cfg, JSON_UNESCAPED_SLASHES)
        . ';</script>';

    require dirname(__FILE__) . '/dashboard-react-shell.php';
    exit;
}
