<?php
/**
 * Sales Report � ERP data providers (group-wise team reporting).
 */

declare(strict_types=1);

require_once __DIR__ . '/sales-reports-lib.php';
require_once __DIR__ . '/sales-reports-format.php';
require_once dirname(__DIR__, 2) . '/analytics/includes/analytics_helpers.php';
require_once dirname(__DIR__, 2) . '/analytics/includes/analytics_company_scope.php';
require_once dirname(__DIR__, 2) . '/analytics/includes/smart_report_sales_helpers.php';

function salesReportsErpMenu(): array
{
    return [
        'Sales' => [
            'sales_summary' => 'Sales Summary',
            'sales_transactions' => 'Sales Transactions',
            'sales_by_customer' => 'Sales by Customer',
            'sales_by_product' => 'Sales by Product',
            'sales_by_category' => 'Sales by Category',
            'team_performance' => 'Sales Team Performance',
            'quarter_comparison' => 'Period Comparison (Team)',
            'quotation_analysis' => 'Quotation Analysis',
            'top_clients' => 'Top Client Contribution',
            'rep_monthly_overview' => 'Salesperson Monthly Overview',
            'delayed_orders' => 'Delayed / Outstanding Orders',
            'payment_analysis' => 'Payment Analysis',
            'outstanding_invoices' => 'Outstanding Invoices',
            'sales_returns' => 'Sales Returns',
            'discounts' => 'Discounts',
        ],
        'Charts' => [
            'sales_trend' => 'Sales Trend',
            'chart_by_customer' => 'Sales by Customer',
            'chart_by_product' => 'Sales by Product',
            'chart_team_performance' => 'Team Performance',
        ],
        'Other' => [
            'company_info' => 'Company Information',
        ],
    ];
}

function salesReportsFiltersFromReport(array $report): array
{
    return [
        'start_date' => (string) ($report['start_date'] ?? date('Y-m-01')),
        'end_date' => (string) ($report['end_date'] ?? date('Y-m-d')),
    ];
}

function salesReportsFetchErpData(PDO $pdo, string $source, array $filters): array
{
    $source = strtolower(trim($source));
    $drill = smart_report_sales_drilldown($pdo, $filters);
    $team = smart_report_sales_team_performance_data($pdo, $filters);

    return match ($source) {
        'sales_summary' => salesReportsDataSalesSummary($drill),
        'sales_transactions' => salesReportsDataTransactions($pdo, $filters),
        'sales_by_customer' => salesReportsDataByCustomer($drill),
        'sales_by_product' => salesReportsDataByProduct($drill),
        'sales_by_category' => salesReportsDataByCategory($drill),
        'team_performance' => salesReportsDataTeamPerformance($team, $drill),
        'quarter_comparison' => salesReportsDataQuarterComparison($pdo, $filters),
        'quotation_analysis' => salesReportsDataQuotationAnalysis($pdo, $filters),
        'top_clients' => salesReportsDataTopClients($drill),
        'rep_monthly_overview' => salesReportsDataRepMonthlyOverview($pdo, $filters),
        'delayed_orders' => salesReportsDataDelayedOrders($pdo, $filters),
        'payment_analysis' => salesReportsDataPaymentAnalysis($drill),
        'outstanding_invoices' => salesReportsDataOutstanding($pdo, $filters),
        'sales_returns' => salesReportsDataReturns($pdo, $filters),
        'discounts' => salesReportsDataDiscounts($pdo, $filters),
        'profitability' => salesReportsDataProfitability($drill),
        'sales_trend' => salesReportsDataSalesTrend($drill),
        'chart_by_customer' => salesReportsDataChartByCustomer($drill),
        'chart_by_product' => salesReportsDataChartByProduct($drill),
        'chart_team_performance' => salesReportsDataChartTeam($team),
        'company_info' => salesReportsDataCompanyInfo(),
        default => ['html' => '<p>Unknown data source.</p>', 'snapshot' => []],
    };
}

function salesReportsDataSalesSummary(array $drill): array
{
    $s = $drill['summary'] ?? [];
    $period = $drill['period']['label'] ?? '';
    $html = '<table class="sr-data-table" border="1" cellpadding="6" style="border-collapse:collapse;width:100%;">'
        . '<tr><td><strong>Reporting Period</strong></td><td>' . htmlspecialchars($period) . '</td></tr>'
        . '<tr><td><strong>Total Sales</strong></td><td>' . salesReportsFormatMoney((float) ($s['total_revenue'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>Total Orders / Invoices</strong></td><td>' . number_format((int) ($s['invoice_count'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>Amount Collected</strong></td><td>' . salesReportsFormatMoney((float) ($s['total_collected'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>Outstanding</strong></td><td>' . salesReportsFormatMoney((float) ($s['outstanding'] ?? 0)) . '</td></tr>'
        . '</table>';
    return ['html' => $html, 'snapshot' => $s, 'type' => 'summary'];
}

function salesReportsDataTransactions(PDO $pdo, array $filters): array
{
    $start = $filters['start_date'];
    $end = $filters['end_date'];
    $rows = [];
    if (tableExists('invoices', $pdo)) {
        $sql = "SELECT i.invoice_number, i.invoice_date, i.total_amount, i.amount_paid, i.balance_due, i.status,
                       COALESCE(c.company_name, 'Walk-in') AS customer_name
                FROM invoices i
                LEFT JOIN customers c ON c.id = i.customer_id
                WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
        $params = [$start, $end];
        analytics_append_company_scope($sql, $params, 'invoices', 'i', $pdo);
        $sql .= ' ORDER BY i.invoice_date DESC LIMIT 50';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $html = '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">'
        . '<thead><tr style="background:#4361ee;color:#fff;"><th>Invoice</th><th>Date</th><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>'
            . '<td>' . htmlspecialchars((string) $r['invoice_number']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['invoice_date']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['customer_name']) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) $r['total_amount']) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) $r['amount_paid']) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) $r['balance_due']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['status']) . '</td>'
            . '</tr>';
    }
    if ($rows === []) {
        $html .= '<tr><td colspan="7">No transactions for this period.</td></tr>';
    }
    $html .= '</tbody></table>';
    return ['html' => $html, 'snapshot' => $rows, 'type' => 'table'];
}

function salesReportsDataByCustomer(array $drill): array
{
    $customers = $drill['top_customers'] ?? [];
    $html = '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">'
        . '<thead><tr style="background:#4361ee;color:#fff;"><th>#</th><th>Customer</th><th>Invoices</th><th>Total Sales</th></tr></thead><tbody>';
    $i = 1;
    foreach (array_slice($customers, 0, 20) as $c) {
        $html .= '<tr>'
            . '<td>' . $i++ . '</td>'
            . '<td>' . htmlspecialchars((string) ($c['company_name'] ?? $c['customer_name'] ?? '')) . '</td>'
            . '<td align="right">' . number_format((int) ($c['invoice_count'] ?? 0)) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) ($c['total'] ?? $c['revenue'] ?? 0)) . '</td>'
            . '</tr>';
    }
    if ($customers === []) {
        $html .= '<tr><td colspan="4">No customer data for this period.</td></tr>';
    }
    $html .= '</tbody></table>';
    return ['html' => $html, 'snapshot' => $customers, 'type' => 'table'];
}

function salesReportsDataByProduct(array $drill): array
{
    $products = $drill['revenue_by_product'] ?? [];
    $html = '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">'
        . '<thead><tr style="background:#4361ee;color:#fff;"><th>#</th><th>Product</th><th>Revenue</th><th>COGS</th></tr></thead><tbody>';
    $i = 1;
    foreach (array_slice($products, 0, 20) as $p) {
        $html .= '<tr>'
            . '<td>' . $i++ . '</td>'
            . '<td>' . htmlspecialchars((string) ($p['product_name'] ?? '')) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) ($p['revenue'] ?? 0)) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) ($p['cogs'] ?? 0)) . '</td>'
            . '</tr>';
    }
    if ($products === []) {
        $html .= '<tr><td colspan="4">No product data for this period.</td></tr>';
    }
    $html .= '</tbody></table>';
    return ['html' => $html, 'snapshot' => $products, 'type' => 'table'];
}

function salesReportsDataByCategory(array $drill): array
{
    $segments = $drill['revenue_by_segment'] ?? [];
    $html = '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">'
        . '<thead><tr style="background:#4361ee;color:#fff;"><th>Category / Segment</th><th>Invoices</th><th>Revenue</th></tr></thead><tbody>';
    foreach ($segments as $s) {
        $html .= '<tr>'
            . '<td>' . htmlspecialchars(ucfirst((string) ($s['segment'] ?? ''))) . '</td>'
            . '<td align="right">' . number_format((int) ($s['invoice_count'] ?? 0)) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) ($s['revenue'] ?? 0)) . '</td>'
            . '</tr>';
    }
    if ($segments === []) {
        $html .= '<tr><td colspan="3">No category data for this period.</td></tr>';
    }
    $html .= '</tbody></table>';
    return ['html' => $html, 'snapshot' => $segments, 'type' => 'table'];
}

function salesReportsDataTeamPerformance(array $team, array $drill): array
{
    $reps = $team['reps'] ?? [];
    $html = '<p><strong>Team Summary (Group Report)</strong></p>'
        . '<table class="sr-data-table" border="1" cellpadding="6" style="border-collapse:collapse;width:100%;margin-bottom:16px;">'
        . '<tr><td><strong>Team Target</strong></td><td>' . salesReportsFormatMoney((float) ($team['team_target'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>Team Actual Sales</strong></td><td>' . salesReportsFormatMoney((float) ($team['team_actual'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>Team Achievement</strong></td><td>' . ($team['achievement_pct'] !== null ? number_format((float) $team['achievement_pct'], 1) . '%' : 'N/A') . '</td></tr>'
        . '<tr><td><strong>Active Sales Personnel</strong></td><td>' . number_format((int) ($team['rep_count'] ?? count($reps))) . '</td></tr>'
        . '<tr><td><strong>Reps On Track</strong></td><td>' . number_format((int) ($team['reps_on_track'] ?? 0)) . '</td></tr>'
        . '</table>'
        . '<p><em>Individual contributions below � presented as team group analysis, not individual rep reports.</em></p>'
        . '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">'
        . '<thead><tr style="background:#4361ee;color:#fff;"><th>Salesperson</th><th>Department</th><th>Invoices</th><th>Quotations</th><th>Actual Sales</th><th>Target</th><th>Achievement</th></tr></thead><tbody>';
    foreach ($reps as $r) {
        $ach = $r['achievement_pct'] !== null
            ? number_format((float) $r['achievement_pct'], 1) . '%' . (!empty($r['achievement_is_contribution']) ? ' (share)' : '')
            : 'N/A';
        $html .= '<tr>'
            . '<td>' . htmlspecialchars((string) ($r['name'] ?? '')) . '</td>'
            . '<td>' . htmlspecialchars((string) ($r['department'] ?? '')) . '</td>'
            . '<td align="right">' . number_format((int) ($r['invoice_count'] ?? 0)) . '</td>'
            . '<td align="right">' . number_format((int) ($r['quotation_count'] ?? 0)) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) ($r['actual'] ?? 0)) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) ($r['target'] ?? 0)) . '</td>'
            . '<td align="right">' . $ach . '</td>'
            . '</tr>';
    }
    if ($reps === []) {
        $html .= '<tr><td colspan="7">No team performance data for this period.</td></tr>';
    }
    $html .= '</tbody></table>';
    return ['html' => $html, 'snapshot' => ['team' => $team, 'summary' => $drill['summary'] ?? []], 'type' => 'team'];
}

function salesReportsDataPaymentAnalysis(array $drill): array
{
    $s = $drill['summary'] ?? [];
    $aging = $drill['ar_aging'] ?? [];
    $html = '<table class="sr-data-table" border="1" cellpadding="6" style="border-collapse:collapse;width:100%;">'
        . '<tr><td><strong>Total Collected</strong></td><td>' . salesReportsFormatMoney((float) ($s['total_collected'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>Outstanding Balance</strong></td><td>' . salesReportsFormatMoney((float) ($s['outstanding'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>Collection Rate</strong></td><td>'
        . (($s['total_revenue'] ?? 0) > 0 ? number_format(((float) $s['total_collected'] / (float) $s['total_revenue']) * 100, 1) . '%' : 'N/A')
        . '</td></tr></table>'
        . '<h4>AR Aging</h4>'
        . '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">'
        . '<tr><td>Current</td><td align="right">' . salesReportsFormatMoney((float) ($aging['current'] ?? 0)) . '</td></tr>'
        . '<tr><td>1�30 Days</td><td align="right">' . salesReportsFormatMoney((float) ($aging['days_1_30'] ?? 0)) . '</td></tr>'
        . '<tr><td>31�60 Days</td><td align="right">' . salesReportsFormatMoney((float) ($aging['days_31_60'] ?? 0)) . '</td></tr>'
        . '<tr><td>61�90 Days</td><td align="right">' . salesReportsFormatMoney((float) ($aging['days_61_90'] ?? 0)) . '</td></tr>'
        . '<tr><td>90+ Days</td><td align="right">' . salesReportsFormatMoney((float) ($aging['days_90_plus'] ?? 0)) . '</td></tr>'
        . '</table>';
    return ['html' => $html, 'snapshot' => ['summary' => $s, 'aging' => $aging], 'type' => 'summary'];
}

function salesReportsDataOutstanding(PDO $pdo, array $filters): array
{
    $rows = [];
    if (tableExists('invoices', $pdo)) {
        $sql = "SELECT i.invoice_number, i.invoice_date, i.due_date, i.balance_due, i.status,
                       COALESCE(c.company_name, 'Walk-in') AS customer_name
                FROM invoices i
                LEFT JOIN customers c ON c.id = i.customer_id
                WHERE i.status != 'cancelled' AND i.balance_due > 0";
        $params = [];
        analytics_append_company_scope($sql, $params, 'invoices', 'i', $pdo);
        $sql .= ' ORDER BY i.due_date ASC LIMIT 50';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $html = '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">'
        . '<thead><tr style="background:#4361ee;color:#fff;"><th>Invoice</th><th>Customer</th><th>Due Date</th><th>Balance</th><th>Status</th></tr></thead><tbody>';
    $total = 0.0;
    foreach ($rows as $r) {
        $bal = (float) ($r['balance_due'] ?? 0);
        $total += $bal;
        $html .= '<tr>'
            . '<td>' . htmlspecialchars((string) $r['invoice_number']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['customer_name']) . '</td>'
            . '<td>' . htmlspecialchars((string) ($r['due_date'] ?? '')) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney($bal) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['status']) . '</td>'
            . '</tr>';
    }
    $html .= '<tr style="font-weight:bold;"><td colspan="3">Total Outstanding</td><td align="right">' . salesReportsFormatMoney($total) . '</td><td></td></tr>';
    $html .= '</tbody></table>';
    return ['html' => $html, 'snapshot' => $rows, 'type' => 'table'];
}

function salesReportsDataReturns(PDO $pdo, array $filters): array
{
    return ['html' => '<p>No sales returns data source configured for this period.</p>', 'snapshot' => [], 'type' => 'text'];
}

function salesReportsDataDiscounts(PDO $pdo, array $filters): array
{
    return ['html' => '<p>Discount analysis aggregated at team level. Insert Sales Summary and Team Performance for discount context.</p>', 'snapshot' => [], 'type' => 'text'];
}

function salesReportsDataProfitability(array $drill): array
{
    $gp = $drill['gross_profit'] ?? [];
    $html = '<table class="sr-data-table" border="1" cellpadding="6" style="border-collapse:collapse;width:100%;">'
        . '<tr><td><strong>Revenue</strong></td><td>' . salesReportsFormatMoney((float) ($gp['revenue'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>COGS</strong></td><td>' . salesReportsFormatMoney((float) ($gp['cogs'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>Gross Profit</strong></td><td>' . salesReportsFormatMoney((float) ($gp['gross_profit'] ?? 0)) . '</td></tr>'
        . '<tr><td><strong>Margin</strong></td><td>' . number_format((float) ($gp['margin_pct'] ?? 0), 1) . '%</td></tr>'
        . '</table>';
    return ['html' => $html, 'snapshot' => $gp, 'type' => 'summary'];
}

function salesReportsDataSalesTrend(array $drill): array
{
    $months = $drill['revenue_by_month'] ?? [];
    $labels = [];
    $values = [];
    foreach ($months as $m) {
        $labels[] = (string) ($m['ym'] ?? '');
        $values[] = (float) ($m['revenue'] ?? 0);
    }
    $chartId = 'chart_' . bin2hex(random_bytes(4));
    $html = '<div class="sr-chart-block" data-chart-type="line" data-chart-id="' . $chartId . '" data-labels="' . htmlspecialchars(json_encode($labels), ENT_QUOTES) . '" data-values="' . htmlspecialchars(json_encode($values), ENT_QUOTES) . '">'
        . '<canvas id="' . $chartId . '" width="600" height="300"></canvas></div>';
    return ['html' => $html, 'snapshot' => $months, 'type' => 'chart', 'chart' => ['labels' => $labels, 'values' => $values]];
}

function salesReportsDataChartByCustomer(array $drill): array
{
    $customers = array_slice($drill['top_customers'] ?? [], 0, 10);
    $labels = array_map(static fn($c) => (string) ($c['company_name'] ?? $c['customer_name'] ?? ''), $customers);
    $values = array_map(static fn($c) => (float) ($c['total'] ?? $c['revenue'] ?? 0), $customers);
    $chartId = 'chart_' . bin2hex(random_bytes(4));
    $html = '<div class="sr-chart-block" data-chart-type="bar" data-chart-id="' . $chartId . '" data-labels="' . htmlspecialchars(json_encode($labels), ENT_QUOTES) . '" data-values="' . htmlspecialchars(json_encode($values), ENT_QUOTES) . '">'
        . '<canvas id="' . $chartId . '" width="600" height="300"></canvas></div>';
    return ['html' => $html, 'snapshot' => $customers, 'type' => 'chart', 'chart' => ['labels' => $labels, 'values' => $values]];
}

function salesReportsDataChartByProduct(array $drill): array
{
    $products = array_slice($drill['revenue_by_product'] ?? [], 0, 10);
    $labels = array_map(static fn($p) => (string) ($p['product_name'] ?? ''), $products);
    $values = array_map(static fn($p) => (float) ($p['revenue'] ?? 0), $products);
    $chartId = 'chart_' . bin2hex(random_bytes(4));
    $html = '<div class="sr-chart-block" data-chart-type="bar" data-chart-id="' . $chartId . '" data-labels="' . htmlspecialchars(json_encode($labels), ENT_QUOTES) . '" data-values="' . htmlspecialchars(json_encode($values), ENT_QUOTES) . '">'
        . '<canvas id="' . $chartId . '" width="600" height="300"></canvas></div>';
    return ['html' => $html, 'snapshot' => $products, 'type' => 'chart', 'chart' => ['labels' => $labels, 'values' => $values]];
}

function salesReportsDataChartTeam(array $team): array
{
    $reps = $team['reps'] ?? [];
    $labels = array_map(static fn($r) => (string) ($r['name'] ?? ''), $reps);
    $values = array_map(static fn($r) => (float) ($r['actual'] ?? 0), $reps);
    $chartId = 'chart_' . bin2hex(random_bytes(4));
    $html = '<div class="sr-chart-block" data-chart-type="bar" data-chart-id="' . $chartId . '" data-labels="' . htmlspecialchars(json_encode($labels), ENT_QUOTES) . '" data-values="' . htmlspecialchars(json_encode($values), ENT_QUOTES) . '">'
        . '<p><em>Team group chart � sales by team member</em></p>'
        . '<canvas id="' . $chartId . '" width="600" height="300"></canvas></div>';
    return ['html' => $html, 'snapshot' => $reps, 'type' => 'chart', 'chart' => ['labels' => $labels, 'values' => $values]];
}

function salesReportsDataCompanyInfo(): array
{
    $name = htmlspecialchars((string) ($_SESSION['company_name'] ?? 'Company'), ENT_QUOTES, 'UTF-8');
    $html = '<table class="sr-data-table" border="1" cellpadding="6" style="border-collapse:collapse;width:100%;">'
        . '<tr><td><strong>Company</strong></td><td>' . $name . '</td></tr>'
        . '<tr><td><strong>Report Generated</strong></td><td>' . date('d M Y H:i') . '</td></tr>'
        . '</table>';
    return ['html' => $html, 'snapshot' => ['company' => $_SESSION['company_name'] ?? ''], 'type' => 'info'];
}

function salesReportsDataQuarterComparison(PDO $pdo, array $filters): array
{
    $previous = salesReportsPreviousPeriodFilters($filters);
    $labels = salesReportsPeriodPairLabels($filters, $previous);
    $teamCurrent = smart_report_sales_team_performance_data($pdo, $filters);
    $teamPrevious = smart_report_sales_team_performance_data($pdo, $previous);

    $prevByUser = [];
    foreach ($teamPrevious['reps'] ?? [] as $rep) {
        $prevByUser[(int) ($rep['user_id'] ?? 0)] = (float) ($rep['actual'] ?? 0);
    }

    $rows = [];
    $totalPrev = 0.0;
    $totalCur = 0.0;
    foreach ($teamCurrent['reps'] ?? [] as $rep) {
        if (empty($rep['counts_toward_target'])) {
            continue;
        }
        $userId = (int) ($rep['user_id'] ?? 0);
        $cur = (float) ($rep['actual'] ?? 0);
        $prev = (float) ($prevByUser[$userId] ?? 0);
        $variance = $cur - $prev;
        $variancePct = $prev > 0 ? round(($variance / $prev) * 100, 2) : ($cur > 0 ? 100.0 : 0.0);
        $rows[] = [
            'name' => (string) ($rep['name'] ?? ''),
            'previous' => $prev,
            'current' => $cur,
            'variance' => $variance,
            'variance_pct' => $variancePct,
            'trend' => salesReportsVarianceTrend($variancePct),
        ];
        $totalPrev += $prev;
        $totalCur += $cur;
    }

    $totalVariance = $totalCur - $totalPrev;
    $totalVariancePct = $totalPrev > 0 ? round(($totalVariance / $totalPrev) * 100, 2) : 0.0;

    $html = '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%; font-size:10pt;">'
        . '<thead><tr style="background:#1a1a2e;color:#fff;">'
        . '<th>Sales Person</th>'
        . '<th>' . htmlspecialchars($labels['previous']) . ' Sales</th>'
        . '<th>' . htmlspecialchars($labels['current']) . ' Sales</th>'
        . '<th>Variance</th>'
        . '<th>Variance (%)</th>'
        . '<th>Trend</th>'
        . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>'
            . '<td>' . htmlspecialchars($row['name']) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney($row['previous']) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney($row['current']) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney($row['variance']) . '</td>'
            . '<td align="right">' . number_format($row['variance_pct'], 2) . '%</td>'
            . '<td>' . htmlspecialchars($row['trend']) . '</td>'
            . '</tr>';
    }

    $html .= '<tr style="font-weight:bold;background:#f5f5f5;">'
        . '<td>Total</td>'
        . '<td align="right">' . salesReportsFormatMoney($totalPrev) . '</td>'
        . '<td align="right">' . salesReportsFormatMoney($totalCur) . '</td>'
        . '<td align="right">' . salesReportsFormatMoney($totalVariance) . '</td>'
        . '<td align="right">' . number_format($totalVariancePct, 2) . '%</td>'
        . '<td>' . htmlspecialchars(salesReportsVarianceTrend($totalVariancePct)) . '</td>'
        . '</tr></tbody></table>';

    return [
        'html' => $html,
        'snapshot' => [
            'labels' => $labels,
            'rows' => $rows,
            'total_previous' => $totalPrev,
            'total_current' => $totalCur,
            'total_variance_pct' => $totalVariancePct,
        ],
        'type' => 'table',
    ];
}

function salesReportsDataQuotationAnalysis(PDO $pdo, array $filters): array
{
    $team = smart_report_sales_team_performance_data($pdo, $filters);
    $rows = [];
    $totalSent = 0;
    $totalConverted = 0;

    foreach ($team['reps'] ?? [] as $rep) {
        if (empty($rep['counts_toward_target'])) {
            continue;
        }
        $userId = (int) ($rep['user_id'] ?? 0);
        $stats = salesReportsRepQuoteStats($pdo, $filters, $userId);
        $sent = (int) ($stats['quotations_sent'] ?? 0);
        $converted = (int) ($stats['orders_converted'] ?? 0);
        $rate = $sent > 0 ? round(($converted / $sent) * 100, 2) : 0.0;
        $rows[] = [
            'name' => (string) ($rep['name'] ?? ''),
            'quotations_sent' => $sent,
            'orders_converted' => $converted,
            'conversion_rate' => $rate,
        ];
        $totalSent += $sent;
        $totalConverted += $converted;
    }

    $teamRate = $totalSent > 0 ? round(($totalConverted / $totalSent) * 100, 2) : 0.0;

    $html = '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%; font-size:10pt;">'
        . '<thead><tr style="background:#1a1a2e;color:#fff;">'
        . '<th>Sales Person</th><th>Quotation Sent</th><th>Orders Converted</th><th>Conversion Rate (%)</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>'
            . '<td>' . htmlspecialchars($row['name']) . '</td>'
            . '<td align="right">' . number_format($row['quotations_sent']) . '</td>'
            . '<td align="right">' . number_format($row['orders_converted']) . '</td>'
            . '<td align="right">' . number_format($row['conversion_rate'], 2) . '%</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>'
        . '<p style="margin-top:12px;"><strong>Team total:</strong> '
        . number_format($totalSent) . ' quotations submitted, '
        . number_format($totalConverted) . ' converted to orders'
        . ($teamRate > 0 ? ' (' . number_format($teamRate, 2) . '% conversion rate).' : '.');

    return [
        'html' => $html,
        'snapshot' => [
            'rows' => $rows,
            'total_sent' => $totalSent,
            'total_converted' => $totalConverted,
            'team_conversion_rate' => $teamRate,
        ],
        'type' => 'table',
    ];
}

function salesReportsRepQuoteStats(PDO $pdo, array $filters, int $userId): array
{
    $sent = 0;
    $converted = 0;

    if (tableExists('sales_orders', $pdo) && columnExists('sales_orders', 'created_by', $pdo)) {
        $start = $filters['start_date'];
        $end = $filters['end_date'];
        [$createdClause, $createdParams] = smart_report_rep_created_by_clause('so', $userId);
        $quoteDateCol = columnExists('sales_orders', 'quote_date', $pdo)
            ? 'so.quote_date'
            : 'DATE(so.created_at)';

        $sql = "SELECT so.status, COUNT(*) AS cnt
                FROM sales_orders so
                WHERE so.status != 'cancelled'
                  AND {$quoteDateCol} BETWEEN ? AND ?
                  AND {$createdClause}
                GROUP BY so.status";
        $params = array_merge([$start, $end], $createdParams);
        analytics_scoped_tables($sql, $params, ['so' => 'sales_orders'], $pdo);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $convertedStatuses = ['confirmed', 'processing', 'completed', 'delivered', 'invoiced', 'paid'];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cnt = (int) ($row['cnt'] ?? 0);
            $sent += $cnt;
            if (in_array(strtolower((string) ($row['status'] ?? '')), $convertedStatuses, true)) {
                $converted += $cnt;
            }
        }
    }

    if ($converted === 0 && tableExists('invoices', $pdo) && columnExists('invoices', 'created_by', $pdo)) {
        $invoices = smart_report_rep_invoices($pdo, $filters, $userId);
        $converted = count($invoices);
        if ($sent < $converted) {
            $openQuotes = smart_report_rep_quotations($pdo, $filters, $userId);
            $sent = $converted + count($openQuotes);
        }
    }

    return ['quotations_sent' => $sent, 'orders_converted' => $converted];
}

function salesReportsDataTopClients(array $drill): array
{
    $customers = array_slice($drill['top_customers'] ?? [], 0, 8);
    if ($customers === []) {
        return ['html' => '<p>No major client data for this period.</p>', 'snapshot' => [], 'type' => 'list'];
    }

    $html = '<ul style="line-height:1.7;">';
    foreach ($customers as $c) {
        $name = htmlspecialchars((string) ($c['company_name'] ?? $c['customer_name'] ?? ''));
        $total = salesReportsFormatMoney((float) ($c['total'] ?? $c['revenue'] ?? 0));
        $invoices = number_format((int) ($c['invoice_count'] ?? 0));
        $html .= '<li><strong>' . $name . '</strong> &mdash; ' . $total
            . ' across ' . $invoices . ' invoice' . ((int) ($c['invoice_count'] ?? 0) === 1 ? '' : 's') . '.</li>';
    }
    $html .= '</ul>';

    return ['html' => $html, 'snapshot' => $customers, 'type' => 'list'];
}

function salesReportsDataDelayedOrders(PDO $pdo, array $filters): array
{
    $rows = [];
    if (tableExists('invoices', $pdo)) {
        $sql = "SELECT i.invoice_number, i.invoice_date, i.total_amount, i.balance_due, i.status,
                       COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in') AS customer_name,
                       COALESCE(NULLIF(TRIM(u.full_name), ''), u.username, '') AS rep_name
                FROM invoices i
                LEFT JOIN customers c ON c.id = i.customer_id
                LEFT JOIN users u ON u.id = i.created_by
                WHERE i.status != 'cancelled'
                  AND i.invoice_date BETWEEN ? AND ?
                  AND i.balance_due > 0";
        $params = [$filters['start_date'], $filters['end_date']];
        analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers', 'u' => 'users'], $pdo);
        $sql .= ' ORDER BY i.balance_due DESC LIMIT 15';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($rows === []) {
        return [
            'html' => '<p>No significant delayed or unpaid orders identified for this period.</p>',
            'snapshot' => [],
            'type' => 'table',
        ];
    }

    $totalDelayed = array_sum(array_map(static fn($r) => (float) ($r['balance_due'] ?? 0), $rows));
    $html = '<p>Orders with outstanding balances during the reporting period (combined value: '
        . salesReportsFormatMoney($totalDelayed) . '):</p>'
        . '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%; font-size:10pt;">'
        . '<thead><tr style="background:#1a1a2e;color:#fff;">'
        . '<th>Sales Person</th><th>Customer</th><th>Invoice</th><th>Amount</th><th>Balance</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>'
            . '<td>' . htmlspecialchars((string) ($r['rep_name'] ?? '')) . '</td>'
            . '<td>' . htmlspecialchars((string) ($r['customer_name'] ?? '')) . '</td>'
            . '<td>' . htmlspecialchars((string) ($r['invoice_number'] ?? '')) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) ($r['total_amount'] ?? 0)) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney((float) ($r['balance_due'] ?? 0)) . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';

    return ['html' => $html, 'snapshot' => ['rows' => $rows, 'total_delayed' => $totalDelayed], 'type' => 'table'];
}

function salesReportsDataRepMonthlyOverview(PDO $pdo, array $filters): array
{
    $team = smart_report_sales_team_performance_data($pdo, $filters);
    $months = smart_report_sales_month_columns($filters['start_date'], $filters['end_date']);
    $year = salesReportsFormatCoverYear($filters['start_date'], $filters['end_date']);
    $periodLabel = salesReportsFormatCoverPeriod($filters['start_date'], $filters['end_date']);
    $html = '';

    foreach ($team['reps'] ?? [] as $rep) {
        if (empty($rep['counts_toward_target'])) {
            continue;
        }
        $userId = (int) ($rep['user_id'] ?? 0);
        $repName = strtoupper((string) ($rep['name'] ?? 'Salesperson'));
        $html .= '<div class="sr-rep-appendix" style="page-break-before:always; margin-top:24px;">'
            . '<h3 style="text-transform:uppercase; font-size:11pt;">' . htmlspecialchars($repName)
            . '&rsquo;S ' . htmlspecialchars($year) . ' SEMI-QUARTER SALES OVERVIEW</h3>';

        $periodTotal = 0.0;
        $periodUnpaid = 0.0;

        foreach ($months as $ym) {
            $monthStart = $ym . '-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart));
            $monthLabel = strtoupper(date('F', strtotime($monthStart)));
            $invoices = salesReportsRepInvoicesDetailed($pdo, [
                'start_date' => max($monthStart, $filters['start_date']),
                'end_date' => min($monthEnd, $filters['end_date']),
            ], $userId);

            $html .= '<p style="font-weight:bold; margin:16px 0 6px;">' . htmlspecialchars($monthLabel) . '</p>';
            $html .= salesReportsRenderInvoiceDetailTable($invoices);

            $monthTotal = array_sum(array_map(static fn($r) => (float) ($r['total_amount'] ?? 0), $invoices));
            $monthUnpaid = array_sum(array_map(static fn($r) => (float) ($r['balance_due'] ?? 0), $invoices));
            $periodTotal += $monthTotal;
            $periodUnpaid += $monthUnpaid;
        }

        $html .= '<p style="margin-top:12px;"><strong>TOTAL ' . htmlspecialchars($periodLabel) . ' SALES</strong> '
            . salesReportsFormatMoney($periodTotal) . '</p>';
        if ($periodUnpaid > 0) {
            $html .= '<p><strong>UNPAID AMOUNT FROM ' . htmlspecialchars($periodLabel) . '</strong> '
                . salesReportsFormatMoney($periodUnpaid) . '</p>';
        }
        $html .= '</div>';
    }

    if ($html === '') {
        $html = '<p>No salesperson invoice breakdown available for this period.</p>';
    }

    return ['html' => $html, 'snapshot' => ['rep_count' => count($team['reps'] ?? [])], 'type' => 'appendix'];
}

function salesReportsRepInvoicesDetailed(PDO $pdo, array $filters, int $userId): array
{
    if (!tableExists('invoices', $pdo) || !columnExists('invoices', 'created_by', $pdo)) {
        return [];
    }

    $start = $filters['start_date'];
    $end = $filters['end_date'];
    [$createdClause, $createdParams] = smart_report_rep_created_by_clause('i', $userId);
    $hasCustomer = tableExists('customers', $pdo) && columnExists('invoices', 'customer_id', $pdo);
    $customerSelect = $hasCustomer
        ? ", COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in') AS customer_name"
        : ", 'Walk-in' AS customer_name";
    $customerJoin = $hasCustomer ? ' LEFT JOIN customers c ON c.id = i.customer_id' : '';

    $sql = "SELECT i.invoice_number, i.invoice_date, i.total_amount, i.amount_paid, i.balance_due, i.status
                   {$customerSelect}
            FROM invoices i{$customerJoin}
            WHERE i.status != 'cancelled'
              AND i.invoice_date BETWEEN ? AND ?
              AND {$createdClause}";
    $params = array_merge([$start, $end], $createdParams);
    $scopes = ['i' => 'invoices'];
    if ($hasCustomer) {
        $scopes['c'] = 'customers';
    }
    analytics_scoped_tables($sql, $params, $scopes, $pdo);
    $sql .= ' ORDER BY i.invoice_date ASC, i.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function salesReportsRenderInvoiceDetailTable(array $invoices): string
{
    $html = '<table class="sr-data-table" border="1" cellpadding="4" style="border-collapse:collapse;width:100%; font-size:9pt; margin-bottom:8px;">'
        . '<thead><tr style="background:#333;color:#fff;">'
        . '<th>Date</th><th>Company Name</th><th>Invoice Number</th><th>Amount</th><th>Paid</th><th>Balance</th>'
        . '</tr></thead><tbody>';

    $totalAmount = 0.0;
    $totalPaid = 0.0;
    $totalBalance = 0.0;

    if ($invoices === []) {
        $html .= '<tr><td colspan="6"><em>No invoices this month.</em></td></tr>';
    }

    foreach ($invoices as $inv) {
        $amount = (float) ($inv['total_amount'] ?? 0);
        $paid = (float) ($inv['amount_paid'] ?? 0);
        $balance = (float) ($inv['balance_due'] ?? 0);
        $totalAmount += $amount;
        $totalPaid += $paid;
        $totalBalance += $balance;
        $dateLabel = !empty($inv['invoice_date']) ? date('j-M-y', strtotime((string) $inv['invoice_date'])) : '-';
        $balanceLabel = $balance > 0 ? salesReportsFormatMoney($balance) : '-';

        $html .= '<tr>'
            . '<td>' . htmlspecialchars($dateLabel) . '</td>'
            . '<td>' . htmlspecialchars((string) ($inv['customer_name'] ?? '')) . '</td>'
            . '<td>' . htmlspecialchars((string) ($inv['invoice_number'] ?? '')) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney($amount) . '</td>'
            . '<td align="right">' . ($paid > 0 ? salesReportsFormatMoney($paid) : '-') . '</td>'
            . '<td align="right">' . $balanceLabel . '</td>'
            . '</tr>';
    }

    if ($invoices !== []) {
        $html .= '<tr style="font-weight:bold;background:#f0f0f0;">'
            . '<td colspan="3"></td>'
            . '<td align="right">' . salesReportsFormatMoney($totalAmount) . '</td>'
            . '<td align="right">' . salesReportsFormatMoney($totalPaid) . '</td>'
            . '<td align="right">' . ($totalBalance > 0 ? salesReportsFormatMoney($totalBalance) : '-') . '</td>'
            . '</tr>';
    }

    $html .= '</tbody></table>';

    return $html;
}

function salesReportsRefreshLiveBlocks(PDO $pdo, string $html, array $filters): string
{
    return preg_replace_callback(
        '/<div[^>]*class="[^"]*sr-erp-block[^"]*"[^>]*data-erp-source="([^"]+)"[^>]*data-erp-mode="live"[^>]*>.*?<\/div>/s',
        static function (array $m) use ($pdo, $filters) {
            $source = $m[1];
            $data = salesReportsFetchErpData($pdo, $source, $filters);
            return '<div class="sr-erp-block" data-erp-source="' . htmlspecialchars($source, ENT_QUOTES) . '" data-erp-mode="live" contenteditable="false">' . ($data['html'] ?? '') . '</div>';
        },
        $html
    ) ?? $html;
}

function salesReportsAiSnapshot(array $report, PDO $pdo): array
{
    $filters = salesReportsFiltersFromReport($report);
    $drill = smart_report_sales_drilldown($pdo, $filters);
    $team = smart_report_sales_team_performance_data($pdo, $filters);
    $previous = salesReportsPreviousPeriodFilters($filters);
    $teamPrevious = smart_report_sales_team_performance_data($pdo, $previous);
    $quotation = salesReportsDataQuotationAnalysis($pdo, $filters);
    $quarterComparison = salesReportsDataQuarterComparison($pdo, $filters);

    return [
        'period' => salesReportsFormatPeriod($report['start_date'], $report['end_date']),
        'cover_period' => salesReportsFormatCoverPeriod($report['start_date'], $report['end_date']),
        'report_name' => $report['report_name'],
        'department' => salesReportsDepartmentLabel($report),
        'prepared_by' => $report['prepared_by'] ?? '',
        'summary' => $drill['summary'] ?? [],
        'team' => [
            'team_target' => $team['team_target'] ?? 0,
            'team_actual' => $team['team_actual'] ?? 0,
            'achievement_pct' => $team['achievement_pct'] ?? null,
            'rep_count' => $team['rep_count'] ?? 0,
            'reps_on_track' => $team['reps_on_track'] ?? 0,
            'reps' => array_map(static fn($r) => [
                'name' => $r['name'] ?? '',
                'actual' => $r['actual'] ?? 0,
                'target' => $r['target'] ?? 0,
                'achievement_pct' => $r['achievement_pct'] ?? null,
            ], $team['reps'] ?? []),
        ],
        'previous_period' => [
            'team_actual' => $teamPrevious['team_actual'] ?? 0,
            'growth_pct' => ($quarterComparison['snapshot']['total_variance_pct'] ?? null),
        ],
        'quotation_analysis' => $quotation['snapshot'] ?? [],
        'quarter_comparison' => $quarterComparison['snapshot'] ?? [],
        'top_customers' => array_slice($drill['top_customers'] ?? [], 0, 8),
        'gross_profit' => $drill['gross_profit'] ?? [],
        'currency' => salesReportsCurrency(),
    ];
}
