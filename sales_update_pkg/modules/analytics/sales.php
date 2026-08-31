<?php
require_once __DIR__ . '/includes/layout.php';
extract(analytics_bootstrap());

$totalSales = analytics_sum_invoices($pdo, $filters['start_date'], $filters['end_date'], 'total_amount');
$totalCollected = analytics_sum_invoices($pdo, $filters['start_date'], $filters['end_date'], 'amount_paid');
$outstanding = max(0, $totalSales - $totalCollected);
$salesTrend = analytics_sales_trend($pdo, $filters);
$rows = analytics_sales_rows($pdo, $filters);

$invoiceCount = count($rows);
$avgInvoice = $invoiceCount > 0 ? $totalSales / $invoiceCount : 0;

$topCustomers = [];
if (tableExists('invoices', $pdo) && tableExists('customers', $pdo)) {
    $sql = "SELECT COALESCE(c.company_name, 'Walk-in') AS customer_name,
                COUNT(i.id) AS invoice_count,
                SUM(i.total_amount) AS revenue,
                SUM(i.amount_paid) AS collected
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
    $params = [$filters['start_date'], $filters['end_date']];
    analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
    $sql .= ' GROUP BY c.id, c.company_name ORDER BY revenue DESC LIMIT 10';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $topCustomers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

analytics_page_start(
    'Sales & Revenue Analysis',
    'Invoice trends, collections, and customer revenue breakdown.',
    'sales'
);
?>
<div class="da-actions" style="margin-bottom:16px;">
    <a href="<?= htmlspecialchars(analytics_export_url('sales')) ?>" class="da-btn da-btn-secondary">
        <i class="bi bi-download"></i> Export CSV
    </a>
</div>

<?php include __DIR__ . '/includes/filters.php'; ?>

<div class="da-kpi-grid">
    <article class="da-kpi da-kpi--info">
        <div class="da-kpi-label">Total Sales</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($totalSales) ?></div>
    </article>
    <article class="da-kpi da-kpi--success">
        <div class="da-kpi-label">Collected</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($totalCollected) ?></div>
    </article>
    <article class="da-kpi da-kpi--warning">
        <div class="da-kpi-label">Outstanding</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($outstanding) ?></div>
    </article>
    <article class="da-kpi">
        <div class="da-kpi-label">Invoices</div>
        <div class="da-kpi-value"><?= number_format($invoiceCount) ?></div>
        <div class="da-kpi-sub">Avg <?= analytics_fmt_money($avgInvoice) ?></div>
    </article>
</div>

<div class="da-charts">
    <div class="da-chart-card" style="grid-column: 1 / -1;">
        <h3>Sales Trend</h3>
        <div class="da-chart-wrap"><canvas id="salesTrendChart"></canvas></div>
    </div>
</div>

<div class="da-table-card">
    <div class="da-table-head">
        <h3>Top Customers by Revenue</h3>
    </div>
    <div class="da-table-wrap">
        <?php if (empty($topCustomers)): ?>
            <div class="da-empty">No customer sales data in this period.</div>
        <?php else: ?>
        <table class="da-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Invoices</th>
                    <th>Revenue</th>
                    <th>Collected</th>
                    <th>Outstanding</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topCustomers as $c): ?>
                <?php $out = (float) $c['revenue'] - (float) $c['collected']; ?>
                <tr>
                    <td><strong><?= htmlspecialchars($c['customer_name']) ?></strong></td>
                    <td><?= (int) $c['invoice_count'] ?></td>
                    <td><?= analytics_fmt_money($c['revenue']) ?></td>
                    <td><?= analytics_fmt_money($c['collected']) ?></td>
                    <td><?= analytics_fmt_money($out) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="da-table-card">
    <div class="da-table-head">
        <h3>Invoice Detail</h3>
    </div>
    <div class="da-table-wrap">
        <?php if (empty($rows)): ?>
            <div class="da-empty">No invoices found for the selected date range.</div>
        <?php else: ?>
        <table class="da-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['invoice_number'] ?? '�') ?></td>
                    <td><?= htmlspecialchars($row['invoice_date'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['customer_name'] ?? '�') ?></td>
                    <td><?= analytics_fmt_money($row['total_amount'] ?? 0) ?></td>
                    <td><?= analytics_fmt_money($row['amount_paid'] ?? 0) ?></td>
                    <td><?= analytics_fmt_money($row['balance_due'] ?? 0) ?></td>
                    <td><?= analytics_status_badge((string) ($row['status'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php
analytics_render_chart_script('salesTrendChart', [
    'type' => 'line',
    'data' => [
        'labels' => $salesTrend['labels'],
        'datasets' => [[
            'label' => 'Daily Sales',
            'data' => $salesTrend['data'],
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37,99,235,0.12)',
            'fill' => true,
            'tension' => 0.35,
        ]],
    ],
    'options' => ['responsive' => true, 'maintainAspectRatio' => false],
]);

analytics_page_end();
