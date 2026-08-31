<?php
require_once __DIR__ . '/includes/layout.php';
extract(analytics_bootstrap());

$totalExpenses = analytics_sum_expenses($pdo, $filters['start_date'], $filters['end_date']);
$totalIncome = analytics_sum_invoices($pdo, $filters['start_date'], $filters['end_date'], 'amount_paid');
$netProfit = $totalIncome - $totalExpenses;
$incomeExpense = analytics_income_vs_expense($pdo, $filters);
$rows = analytics_finance_rows($pdo, $filters);

$expenseByCategory = [];
if (tableExists('payment_vouchers', $pdo)) {
    $st = $pdo->prepare(
        "SELECT LEFT(COALESCE(description, payee_name, 'Other'), 40) AS category,
                SUM(total_amount) AS total, COUNT(*) AS cnt
         FROM payment_vouchers
         WHERE status = 'approved' AND date_created BETWEEN ? AND ?
         GROUP BY category
         ORDER BY total DESC
         LIMIT 8"
    );
    $st->execute([$filters['start_date'], $filters['end_date']]);
    $expenseByCategory = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pendingVouchers = 0;
if (tableExists('payment_vouchers', $pdo)) {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM payment_vouchers WHERE status NOT IN ('approved','rejected','cancelled') AND date_created BETWEEN ? AND ?"
    );
    $st->execute([$filters['start_date'], $filters['end_date']]);
    $pendingVouchers = (int) ($st->fetchColumn() ?: 0);
}

analytics_page_start(
    'Finance & Expense Analysis',
    'Income vs expenses, voucher tracking, and profitability overview.',
    'finance'
);
?>
<div class="da-actions" style="margin-bottom:16px;">
    <a href="<?= htmlspecialchars(analytics_export_url('finance')) ?>" class="da-btn da-btn-secondary">
        <i class="bi bi-download"></i> Export CSV
    </a>
</div>

<?php include __DIR__ . '/includes/filters.php'; ?>

<div class="da-kpi-grid">
    <article class="da-kpi da-kpi--success">
        <div class="da-kpi-label">Total Income</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($totalIncome) ?></div>
        <div class="da-kpi-sub">Cash collected from invoices</div>
    </article>
    <article class="da-kpi da-kpi--danger">
        <div class="da-kpi-label">Total Expenses</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($totalExpenses) ?></div>
    </article>
    <article class="da-kpi da-kpi--<?= $netProfit >= 0 ? 'success' : 'danger' ?>">
        <div class="da-kpi-label">Net Profit</div>
        <div class="da-kpi-value"><?= analytics_fmt_money($netProfit) ?></div>
    </article>
    <article class="da-kpi da-kpi--warning">
        <div class="da-kpi-label">Pending Vouchers</div>
        <div class="da-kpi-value"><?= number_format($pendingVouchers) ?></div>
        <div class="da-kpi-sub">Awaiting approval</div>
    </article>
</div>

<div class="da-charts">
    <div class="da-chart-card">
        <h3>Income vs Expense (Monthly)</h3>
        <div class="da-chart-wrap"><canvas id="incomeExpenseChart"></canvas></div>
    </div>
    <div class="da-chart-card">
        <h3>Expense Breakdown</h3>
        <div class="da-chart-wrap"><canvas id="expensePieChart"></canvas></div>
    </div>
</div>

<div class="da-table-card">
    <div class="da-table-head">
        <h3>Expense & Payment Transactions</h3>
    </div>
    <div class="da-table-wrap">
        <?php if (empty($rows)): ?>
            <div class="da-empty">No finance transactions in this period.</div>
        <?php else: ?>
        <table class="da-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Date</th>
                    <th>Party</th>
                    <th>Source</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['ref'] ?? '�') ?></td>
                    <td><?= htmlspecialchars(substr((string) ($row['txn_date'] ?? ''), 0, 10)) ?></td>
                    <td><?= htmlspecialchars($row['party'] ?? '�') ?></td>
                    <td><?= htmlspecialchars($row['source'] ?? '�') ?></td>
                    <td><?= analytics_fmt_money($row['amount'] ?? 0) ?></td>
                    <td><?= analytics_status_badge((string) ($row['status'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php
analytics_render_chart_script('incomeExpenseChart', [
    'type' => 'bar',
    'data' => [
        'labels' => $incomeExpense['labels'],
        'datasets' => [
            ['label' => 'Income', 'data' => $incomeExpense['income'], 'backgroundColor' => '#10b981'],
            ['label' => 'Expenses', 'data' => $incomeExpense['expense'], 'backgroundColor' => '#f97316'],
        ],
    ],
    'options' => ['responsive' => true, 'maintainAspectRatio' => false],
]);

$catLabels = array_column($expenseByCategory, 'category');
$catData = array_map(static function ($r) {
    return (float) $r['total'];
}, $expenseByCategory);

analytics_render_chart_script('expensePieChart', [
    'type' => 'doughnut',
    'data' => [
        'labels' => $catLabels ?: ['No data'],
        'datasets' => [[
            'data' => $catData ?: [1],
            'backgroundColor' => ['#6366f1', '#8b5cf6', '#ec4899', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#64748b'],
        ]],
    ],
    'options' => ['responsive' => true, 'maintainAspectRatio' => false],
]);

analytics_page_end();
