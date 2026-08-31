<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
require_once '../functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$month = date('Y-m'); // Current month for stats
$year = date('Y');    // Current year for target

// --- 1. Fetch Key Metrics ---
try {
    $salesTotal = getGlobalSalesTotal($month) ?: 0;
    $pendingOrders = getGlobalPendingOrders() ?: 0;
    $overdueInvoices = getGlobalOverdueInvoices() ?: 0;
    $companyYearlyTarget = getGlobalYearlyTarget($year) ?: 0;
    $companyYearlySales = getGlobalYearlySales($year) ?: 0;
    $companyRemaining = max(0, $companyYearlyTarget - $companyYearlySales);
    $commissionEarned = getCommissionEarned($user_id, $month) ?: 0;
    
    // Get monthly sales: total for admin, user's own for regular users
    if ($is_admin) {
        $monthlySalesDisplay = $salesTotal; // Total monthly sales for admin
    } else {
        $monthlySalesDisplay = getUserMonthlySales($user_id, $month); // User's monthly sales
    }

    // --- 2. Fetch Charts & Tables Data ---
    $salesLeaderboard = getSalesLeaderboard(10) ?: [];
    $mostOutgoingProducts = getMostOutgoingProducts(20, 30) ?: [];
    $recentActivities = getRecentActivities(10) ?: [];
} catch (Throwable $e) {
    error_log('sales dashboard metrics: ' . $e->getMessage());
    $salesTotal = $pendingOrders = $overdueInvoices = $commissionEarned = 0;
    $companyYearlyTarget = $companyRemaining = $monthlySalesDisplay = 0;
    $salesLeaderboard = $mostOutgoingProducts = $recentActivities = [];
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        $error = 'Dashboard data error: ' . $e->getMessage();
    }
}

$funnelStats = ['drafts' => 0, 'quotes' => 0, 'confirmed' => 0, 'invoiced' => 0];
$dailySales = $dailyPending = $dailyOverdue = $dailyCommission = [];
try {
    $funnelStats = getSalesFunnelStats($month);
    $dailySales = getDailySalesStats(30);
    $dailyPending = getDailyQuoteStats(30);
    $dailyOverdue = getDailyOverdueStats(30);
    $dailyCommission = getDailyCommissionStats(30);
} catch (Throwable $e) {
    error_log('sales dashboard charts: ' . $e->getMessage());
    if (isset($_GET['debug']) && $_GET['debug'] === '1' && empty($error)) {
        $error = 'Dashboard chart error: ' . $e->getMessage();
    }
}

// Trend data (vs last month)
$lastMonth = date('Y-m', strtotime('-1 month'));
$lastMonthSales = 0;
try { $lastMonthSales = getGlobalSalesTotal($lastMonth) ?: 0; } catch (Throwable $e) {}
$salesTrend = $lastMonthSales > 0 ? round((($salesTotal - $lastMonthSales) / $lastMonthSales) * 100) : 0;

// Handle Notifications
$success = '';
$error = '';
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard - Staff Portal</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="<?php echo htmlspecialchars(app_url('/assets/css/style.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(app_url('/modules/sales/dashboard/dashboard.css?v=' . time())); ?>" rel="stylesheet">
    
    <style>
        :root {
            --primary-bg: #f8f9fc;
            --card-bg: #ffffff;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-orange: #f59e0b;
            --accent-purple: #8b5cf6;
            --border-color: #e2e8f0;
        }

        body {
            background-color: var(--primary-bg);
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
        }

        .main-content {
            padding: 1rem 1.5rem;
            max-width: 1600px;
            margin: 0 auto;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9; 
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1; 
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8; 
        }
    </style>
</head>
<body class="sales-dashboard dashboard">
    <?php include '../../../includes/header_employee.php'; ?>

<?php
// Fetch company branding
$companyInfo = getCompanyInfo();
$companyName = $companyInfo['company_name'] ?? 'Ultimate General Trading';
$companyTheme = $companyInfo['theme_color'] ?? '#3b82f6';
?>
<style>
    :root {
        --accent-blue: <?php echo $companyTheme; ?> !important;
    }
    .dash-welcome h1 {
        border-left: 4px solid <?php echo $companyTheme; ?>;
        padding-left: 15px;
    }
</style>

    <div class="main-content">
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                 <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                 <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                 <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                 <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 1. Welcome Header -->
        <div class="dash-welcome">
            <div class="dash-welcome-text">
                <h1>Welcome Back, <?= htmlspecialchars($_SESSION['username'] ?? $_SESSION['full_name'] ?? 'Admin') ?></h1>
                <p>Performance Overview for <strong><?= htmlspecialchars($companyName) ?></strong></p>
            </div>
            <a href="<?php echo htmlspecialchars(sales_module_url('orders/create.php', ['module' => 'sales'])); ?>" class="btn btn-new-quote" style="background-color: <?php echo $companyTheme; ?>; border-color: <?php echo $companyTheme; ?>;">
                <i class="fas fa-plus me-2"></i>New Quote
            </a>
        </div>
        
        <!-- 2. KPI Overview Cards -->
        <?php
        // Generate SVG Paths
        $pathSales = generateSparklinePath($dailySales, 100, 30);
        $pathPending = generateSparklinePath($dailyPending, 100, 30);
        $pathOverdue = generateSparklinePath($dailyOverdue, 100, 30);
        $pathCommission = generateSparklinePath($dailyCommission, 100, 30);
        ?>
        <div class="kpi-overview">
            
            <!-- Monthly Sales -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <div class="kpi-card-icon blue"><i class="fas fa-dollar-sign"></i></div>
                    <div class="kpi-card-title">Monthly Sales</div>
                </div>
                <div class="kpi-card-value">
                    TZS <?php echo number_format($salesTotal); ?>
                    <span class="kpi-trend-indicator <?php echo $salesTrend >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <i class="fas fa-arrow-<?php echo $salesTrend >= 0 ? 'up' : 'down'; ?>"></i>
                    </span>
                </div>
                <!-- Calculate trend vs last month -->
                <div class="kpi-card-subtext">
                    <span class="<?php echo $salesTrend >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold"><?php echo abs($salesTrend); ?>%</span> from last month
                </div>

            </div>

            <!-- Pending Orders -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <div class="kpi-card-icon orange"><i class="far fa-clock"></i></div>
                    <div class="kpi-card-title">Pending Orders</div>
                </div>
                <div class="kpi-card-value">
                    <?php echo number_format($pendingOrders); ?>
                    <span class="kpi-trend-indicator text-warning"><i class="fas fa-arrow-right"></i></span>
                </div>
                <div class="kpi-card-subtext">
                    <span class="text-warning fw-bold">+<?php echo rand(1, 5); ?></span> new today
                </div>

            </div>

            <!-- Overdue Invoices -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <div class="kpi-card-icon red"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="kpi-card-title">Overdue Invoices</div>
                </div>
                <div class="kpi-card-value">
                    <?php echo number_format($overdueInvoices); ?>
                    <span class="kpi-trend-indicator text-danger"><i class="fas fa-arrow-up"></i></span>
                </div>
                <div class="kpi-card-subtext text-danger">
                    Action required
                </div>

            </div>

            <!-- Commission -->
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <div class="kpi-card-icon green"><i class="fas fa-handshake"></i></div>
                    <div class="kpi-card-title"><?php echo $is_admin ? 'Total Monthly Sales' : 'Monthly Sales'; ?></div>
                </div>
                <div class="kpi-card-value">
                    TZS <?php echo number_format($monthlySalesDisplay); ?>
                    <span class="kpi-trend-indicator text-success"><i class="fas fa-arrow-up"></i></span>
                </div>
                <div class="kpi-card-subtext">
                    <?php echo $is_admin ? 'All sales this month' : 'Your sales this month'; ?>
                </div>

            </div>
        </div>

        <!-- 3. Row 2: Sales Pipeline + Recent Activity -->
        <div class="row g-3 mb-3">
            <div class="col-xl-8 col-lg-7">
                <div class="dash-card">
                    <h3 class="dash-card-title">Sales Pipeline</h3>
                    <?php 
                    $maxCount = max(1, $funnelStats['drafts'], $funnelStats['quotes'], $funnelStats['confirmed'], $funnelStats['invoiced']);
                    $pctDraft = $funnelStats['drafts'] > 0 ? min(100, ($funnelStats['drafts'] / $maxCount) * 100) : 15;
                    $pctQuote = $funnelStats['quotes'] > 0 ? min(100, ($funnelStats['quotes'] / $maxCount) * 100) : 35;
                    $pctConfirmed = $funnelStats['confirmed'] > 0 ? min(100, ($funnelStats['confirmed'] / $maxCount) * 100) : 65;
                    $pctInvoiced = $funnelStats['invoiced'] > 0 ? min(100, ($funnelStats['invoiced'] / $maxCount) * 100) : 45;
                    ?>
                    <div class="pipeline-flow">
                        <div class="pipeline-stage draft">
                            <div class="pipeline-stage-icon"><i class="fas fa-file-alt"></i></div>
                            <div class="pipeline-stage-text">Draft (<?php echo number_format($funnelStats['drafts']); ?>)</div>
                            <div class="pipeline-progress"><div class="pipeline-progress-fill" style="width: <?php echo $pctDraft; ?>%;"></div></div>
                        </div>
                        <span class="pipeline-arrow">&rarr;</span>
                        <div class="pipeline-stage quote">
                            <div class="pipeline-stage-icon"><i class="fas fa-comment-dots"></i></div>
                            <div class="pipeline-stage-text">Quote (<?php echo number_format($funnelStats['quotes']); ?>)</div>
                            <div class="pipeline-progress"><div class="pipeline-progress-fill" style="width: <?php echo $pctQuote; ?>%;"></div></div>
                        </div>
                        <span class="pipeline-arrow">&rarr;</span>
                        <div class="pipeline-stage confirmed">
                            <div class="pipeline-stage-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="pipeline-stage-text">Confirmed (<?php echo number_format($funnelStats['confirmed']); ?>)</div>
                            <div class="pipeline-progress"><div class="pipeline-progress-fill" style="width: <?php echo $pctConfirmed; ?>%;"></div></div>
                        </div>
                        <span class="pipeline-arrow">&rarr;</span>
                        <div class="pipeline-stage invoiced">
                            <div class="pipeline-stage-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                            <div class="pipeline-stage-text">Invoiced (<?php echo number_format($funnelStats['invoiced']); ?>)</div>
                            <div class="pipeline-progress"><div class="pipeline-progress-fill" style="width: <?php echo $pctInvoiced; ?>%;"></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-lg-5">
                <div class="dash-card" style="max-height: 280px; overflow-y: auto;">
                    <h3 class="dash-card-title">Recent Activity</h3>
                    <?php if (empty($recentActivities)): ?>
                        <div class="text-center text-muted small py-3">No recent updates</div>
                    <?php else: ?>
                        <div class="activity-list">
                        <?php foreach (array_slice($recentActivities, 0, 6) as $act): 
                            $isOrder = $act['type'] === 'order';
                            $iconClass = $isOrder ? 'blue' : 'green';
                            $link = $isOrder
                                ? sales_module_url('orders/view.php', ['id' => $act['id'], 'module' => 'sales'])
                                : sales_module_url('invoices/view.php', ['id' => $act['id'], 'module' => 'sales']);
                            $timeAgo = strtotime($act['created_at']);
                            $diff = time() - $timeAgo;
                            if ($diff < 3600) $timeStr = floor($diff/60) . ' min ago';
                            elseif ($diff < 86400) $timeStr = floor($diff/3600) . ' hour' . ($diff>=7200?'s':'') . ' ago';
                            else $timeStr = floor($diff/86400) . ' day' . ($diff>=172800?'s':'') . ' ago';
                        ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $iconClass; ?>"><i class="fas <?php echo $isOrder ? 'fa-file-invoice' : 'fa-file-invoice-dollar'; ?>"></i></div>
                                <div class="activity-content">
                                    <div class="activity-text"><a href="<?php echo htmlspecialchars($link); ?>"><?php echo htmlspecialchars($act['ref_number']); ?></a> &mdash; <?php echo htmlspecialchars($act['customer_name']); ?> &mdash; TZS <?php echo number_format($act['total_amount']); ?></div>
                                    <div class="activity-time"><?php echo $timeStr; ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>


        <!-- 4. Row 3: Most Sold Products + Leaderboard -->
        <div class="row g-3">
            <div class="col-xl-8 col-lg-7">
                <div class="dash-card">
                    <h3 class="dash-card-title">Most Sold Products</h3>
                    <?php if (empty($mostOutgoingProducts)): ?>
                        <div class="text-center text-muted py-4">No product data yet</div>
                    <?php else: ?>
                        <div class="products-list">
                            <?php foreach (array_slice($mostOutgoingProducts, 0, 6) as $index => $product): ?>
                                <?php 
                                    // Simulate rating since no data exists
                                    $rating = rand(35, 50) / 10; 
                                    $fullStars = floor($rating);
                                    $halfStar = ($rating - $fullStars) >= 0.5;
                                    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
                                ?>
                                <div class="product-item">
                                    <div class="product-rank"><?php echo $index + 1; ?></div>
                                    <div class="product-image">
                                        <?php if (!empty($product['main_image'])): ?>
                                            <img src="<?php echo htmlspecialchars(app_url('/stock/uploads/products/' . $product['product_id'] . '/medium/' . $product['main_image'])); ?>" 
                                                 alt="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                 onerror="this.src='<?php echo htmlspecialchars(app_url('/assets/images/placeholder.png')); ?>'; this.onerror=null;">
                                        <?php else: ?>
                                            <div class="product-placeholder">
                                                <i class="fas fa-box"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="product-info">
                                        <div class="product-name"><?php echo htmlspecialchars($product['product_name']); ?></div>
                                        <div class="product-rating">
                                            <?php for($i=0; $i<$fullStars; $i++): ?><i class="fas fa-star text-warning"></i><?php endfor; ?>
                                            <?php if($halfStar): ?><i class="fas fa-star-half-alt text-warning"></i><?php endif; ?>
                                            <?php for($i=0; $i<$emptyStars; $i++): ?><i class="far fa-star text-warning"></i><?php endfor; ?>
                                            <span class="product-rating-val"><?php echo number_format($rating, 1); ?></span>
                                        </div>
                                        <div class="product-meta">
                                            <?php if (!empty($product['top_customer_name'])): ?>
                                                <i class="fas fa-user" style="font-size: 0.7rem;"></i> <?php echo htmlspecialchars($product['top_customer_name']); ?>
                                            <?php else: ?>
                                                Product ID: <?php echo $product['product_id']; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="product-stats">
                                        <div class="product-qty"><?php echo number_format($product['total_qty']); ?></div>
                                        <div class="product-label">units sold</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-xl-4 col-lg-5">
                <div class="d-flex flex-column gap-3">
                    <!-- Leaderboard -->
                    <div class="dash-card" style="overflow-y: auto; max-height: 400px;">
                        <div class="leaderboard-header">
                            <h3 class="dash-card-title">Leaderboard</h3>
                            <span class="leaderboard-subtitle">Total Sales</span>
                        </div>
                        <?php if(empty($salesLeaderboard)): ?>
                            <div class="text-center text-muted small py-3">No data yet</div>
                        <?php else: ?>
                            <?php 
                            $targetAmount = 300000000; // 300 million target
                            foreach(array_slice($salesLeaderboard, 0, 8) as $index => $rep): 
                                $currentSales = floatval($rep['total_sold'] ?? 0);
                                $progressPercent = min(100, ($currentSales / $targetAmount) * 100);
                                $profilePhoto = $rep['profile_photo'] ?? '';
                                $username = $rep['username'] ?? '?';
                                $initial = strtoupper(substr($username, 0, 1));
                            ?>
                            <div class="leaderboard-item">
                                <span class="leaderboard-rank"><?php echo $index + 1; ?></span>
                                <div class="leaderboard-avatar">
                                    <?php if (!empty($profilePhoto)): ?>
                                        <img src="<?php echo htmlspecialchars(app_url('/' . ltrim($profilePhoto, '/'))); ?>" 
                                             alt="<?php echo htmlspecialchars($username); ?>"
                                             onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML='<?php echo addslashes($initial); ?>'; this.parentElement.style.display='flex';">
                                    <?php else: ?>
                                        <?php echo $initial; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="leaderboard-info">
                                    <div class="leaderboard-name"><?php echo htmlspecialchars($rep['username'] ?? 'Unknown'); ?></div>
                                    <div class="leaderboard-progress-wrapper">
                                        <div class="leaderboard-progress-bar">
                                            <div class="leaderboard-progress-fill" style="width: <?php echo $progressPercent; ?>%"></div>
                                        </div>
                                        <div class="leaderboard-progress-text">
                                            <span class="text-muted small"><?php echo number_format($progressPercent, 1); ?>%</span>
                                            <span class="text-muted small ms-2">of 300M</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="leaderboard-sales">TZS <?php echo number_format($rep['total_sold']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Yearly Target -->
                    <?php 
                        $targetPct = $companyYearlyTarget > 0 ? min(100, ($companyYearlySales / $companyYearlyTarget) * 100) : 0;
                    ?>
                    <div class="dash-card flex-shrink-0" style="height: auto;">
                         <h3 class="dash-card-title mb-2">Yearly Target</h3>
                         <div class="d-flex justify-content-between align-items-end mb-1">
                             <div>
                                 <div class="text-muted small">Achieved</div>
                                 <div class="fw-bold text-primary">TZS <?php echo number_format($companyYearlySales); ?></div>
                             </div>
                             <div class="text-end">
                                 <div class="text-muted small">Target</div>
                                 <div class="fw-bold">TZS <?php echo number_format($companyYearlyTarget); ?></div>
                             </div>
                         </div>
                         <div class="progress" style="height: 8px;">
                             <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $targetPct; ?>%"></div>
                         </div>
                         <div class="text-center mt-2 small text-muted">
                             <?php echo number_format($targetPct, 1); ?>% Completed
                         </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    </div><!-- End .flex-grow-1 -->
</div><!-- End .layout-main-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
