<?php
require_once __DIR__ . '/includes/layout.php';
extract(analytics_bootstrap());

$kpis = analytics_overview_kpis($pdo, $filters);
$salesTrend = analytics_sales_trend($pdo, $filters);
$incomeExpense = analytics_income_vs_expense($pdo, $filters);
$empPerf = analytics_employee_performance_chart($pdo, $filters);
$missionStatus = analytics_mission_status_chart($pdo, $filters);
$topPerformer = analytics_monthly_top_performer($pdo);

$salesReportCount = 0;
$salesReportDrafts = 0;
$salesReportsUrl = '../sales-reports/index.php?module=analytics';
try {
    require_once __DIR__ . '/../sales-reports/includes/sales-reports-lib.php';
    salesReportsEnsureSchema();
    $salesReportRows = salesReportsList($pdo);
    $salesReportCount = count($salesReportRows);
    $salesReportDrafts = count(array_filter($salesReportRows, static fn($r) => ($r['status'] ?? '') === 'draft'));
} catch (Throwable $e) {
    error_log('analytics index sales reports: ' . $e->getMessage());
}

analytics_page_start(
    'Overview Dashboard',
    'Cross-module KPIs and business insights at a glance.',
    'index'
);
?>
<div class="da-actions" style="margin-bottom:16px;">
    <a href="<?= htmlspecialchars(analytics_export_url('overview')) ?>" class="da-btn da-btn-secondary">
        <i class="bi bi-download"></i> Export CSV
    </a>
</div>

<?php include __DIR__ . '/includes/filters.php'; ?>

<div class="da-hub-grid">
    <a href="<?= htmlspecialchars($salesReportsUrl) ?>" class="da-hub-card da-hub-card--sales">
        <div class="da-hub-card-icon"><i class="bi bi-file-earmark-text"></i></div>
        <div class="da-hub-card-body">
            <h3 class="da-hub-card-title">Business Reports</h3>
            <p class="da-hub-card-desc">Generate AI-powered Sales, Procurement, Finance, Fleet, and Store/Warehouse reports.</p>
            <div class="da-hub-card-stats">
                <span><strong><?= (int) $salesReportCount ?></strong> report<?= $salesReportCount === 1 ? '' : 's' ?></span>
                <?php if ($salesReportDrafts > 0): ?>
                <span class="da-hub-card-badge"><?= (int) $salesReportDrafts ?> draft<?= $salesReportDrafts === 1 ? '' : 's' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <span class="da-hub-card-action"><i class="bi bi-arrow-right"></i> Open</span>
    </a>
</div>

<div class="da-kpi-grid">
    <article class="da-kpi da-kpi--info">
        <div class="da-kpi-label">Total Sales</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($kpis['total_sales']) ?></div>
        <div class="da-kpi-sub"><?= htmlspecialchars($filters['start_date']) ?> � <?= htmlspecialchars($filters['end_date']) ?></div>
    </article>
    <article class="da-kpi da-kpi--danger">
        <div class="da-kpi-label">Total Expenses</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($kpis['total_expenses']) ?></div>
    </article>
    <article class="da-kpi da-kpi--success">
        <div class="da-kpi-label">Net Profit</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($kpis['net_profit']) ?></div>
        <div class="da-kpi-sub">Collected income minus expenses</div>
    </article>
    <article class="da-kpi da-kpi--warning">
        <div class="da-kpi-label">Pending Payments</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($kpis['pending_payments']) ?></div>
        <div class="da-kpi-sub">Outstanding receivables</div>
    </article>
    <article class="da-kpi da-kpi--danger">
        <div class="da-kpi-label">Low Stock Alerts</div>
        <div class="da-kpi-value"><?= (int) $kpis['low_stock_alerts'] ?></div>
        <div class="da-kpi-sub">Products at or below reorder level</div>
    </article>
    <article class="da-kpi">
        <div class="da-kpi-label">Employee Performance</div>
        <div class="da-kpi-value"><?= number_format($kpis['employee_performance_score'], 1) ?>%</div>
        <div class="da-kpi-sub">Avg mission completion (period)</div>
    </article>
    <article class="da-kpi da-kpi--success">
        <div class="da-kpi-label">Mission Completion</div>
        <div class="da-kpi-value"><?= number_format($kpis['mission_completion_rate'], 1) ?>%</div>
        <div class="da-kpi-sub">Current week team average</div>
    </article>
</div>

<?php if ($topPerformer): ?>
<div class="da-highlight">
    <h3><i class="bi bi-trophy"></i> Monthly Top Performer</h3>
    <div class="da-kpi-value"><?= htmlspecialchars($topPerformer['full_name']) ?></div>
    <div class="da-kpi-sub" style="color:rgba(255,255,255,0.85);margin-top:8px;">
        <?= htmlspecialchars($topPerformer['department'] ?? '�') ?>
        � <?= number_format((float) $topPerformer['avg_rate'], 1) ?>% completion
        � <?= (int) $topPerformer['total_points'] ?> award points
    </div>
</div>
<?php endif; ?>

<div class="da-charts">
    <div class="da-chart-card">
        <h3>Sales Trend</h3>
        <div class="da-chart-wrap"><canvas id="salesTrendChart"></canvas></div>
    </div>
    <div class="da-chart-card">
        <h3>Income vs Expense</h3>
        <div class="da-chart-wrap"><canvas id="incomeExpenseChart"></canvas></div>
    </div>
    <div class="da-chart-card">
        <h3>Employee Performance</h3>
        <div class="da-chart-wrap"><canvas id="empPerfChart"></canvas></div>
    </div>
    <div class="da-chart-card">
        <h3>Mission Status</h3>
        <div class="da-chart-wrap"><canvas id="missionStatusChart"></canvas></div>
    </div>
</div>

<?php
analytics_render_chart_script('salesTrendChart', [
    'type' => 'line',
    'data' => [
        'labels' => $salesTrend['labels'],
        'datasets' => [[
            'label' => 'Sales',
            'data' => $salesTrend['data'],
            'borderColor' => '#6366f1',
            'backgroundColor' => 'rgba(99,102,241,0.1)',
            'fill' => true,
            'tension' => 0.3,
        ]],
    ],
    'options' => ['responsive' => true, 'maintainAspectRatio' => false],
]);

analytics_render_chart_script('incomeExpenseChart', [
    'type' => 'bar',
    'data' => [
        'labels' => $incomeExpense['labels'],
        'datasets' => [
            ['label' => 'Income', 'data' => $incomeExpense['income'], 'backgroundColor' => '#10b981'],
            ['label' => 'Expenses', 'data' => $incomeExpense['expense'], 'backgroundColor' => '#ef4444'],
        ],
    ],
    'options' => ['responsive' => true, 'maintainAspectRatio' => false],
]);

analytics_render_chart_script('empPerfChart', [
    'type' => 'bar',
    'data' => [
        'labels' => $empPerf['labels'],
        'datasets' => [[
            'label' => 'Completion %',
            'data' => $empPerf['data'],
            'backgroundColor' => '#8b5cf6',
        ]],
    ],
    'options' => [
        'responsive' => true,
        'maintainAspectRatio' => false,
        'indexAxis' => count($empPerf['labels']) > 6 ? 'y' : 'x',
        'scales' => ['x' => ['max' => 100], 'y' => ['max' => 100]],
    ],
]);

analytics_render_chart_script('missionStatusChart', [
    'type' => 'doughnut',
    'data' => [
        'labels' => $missionStatus['labels'],
        'datasets' => [[
            'data' => $missionStatus['data'],
            'backgroundColor' => ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
        ]],
    ],
    'options' => ['responsive' => true, 'maintainAspectRatio' => false],
]);

analytics_page_end();
