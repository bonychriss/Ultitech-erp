<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/analytics/includes/analytics_helpers.php';
require_once dirname(__DIR__, 2) . '/analytics/includes/smart_report_finance_helpers.php';

function reportDomainFinanceAccountOptions(PDO $pdo): array
{
    if (!tableExists('financial_accounts', $pdo)) {
        return [];
    }
    $sql = "SELECT id, name FROM financial_accounts WHERE status = 'active' ORDER BY name ASC LIMIT 100";
    $params = [];
    reportEngineAppendScope($sql, $params, 'financial_accounts', $pdo);
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    return array_map(static fn($r) => ['value' => (string) (int) $r['id'], 'label' => (string) ($r['name'] ?? 'Account')], $rows);
}

function reportDomainFinanceSnapshot(PDO $pdo, array $filters): array
{
    $kpis = reportDomainFinanceKpis($pdo, $filters);

    return [
        'kpis' => $kpis,
        'monthly_revenue' => reportDomainFinanceMonthlyIncome($pdo, $filters),
        'monthly_expenses' => reportDomainFinanceMonthlyExpenses($pdo, $filters),
        'expense_categories' => reportDomainFinanceExpenseCategories($pdo, $filters),
        'expense_breakdown' => reportDomainFinanceExpenseBreakdown($pdo, $filters),
        'accounts' => reportDomainFinanceAccountBalances($pdo, $filters),
        'ar_aging' => reportDomainFinanceArSummary($pdo, $filters),
        'ap_aging' => reportDomainFinanceApAging($pdo, $filters),
        'ap_summary' => reportDomainFinanceApSummary($pdo, $filters),
        'exceptions' => reportDomainFinanceExceptions($pdo, $filters, $kpis),
        'data_quality' => reportEngineDataQualityNotes($kpis['data_quality_notes'] ?? []),
        'sections_available' => reportDomainFinanceAvailableSections($kpis),
    ];
}

function reportDomainFinanceAvailableSections(array $kpis): array
{
    return reportEngineDefaultSections('finance');
}

function reportDomainFinanceKpis(PDO $pdo, array $filters): array
{
    $start = $filters['start_date'];
    $end = $filters['end_date'];
    $notes = [];

    $totalIncome = 0.0;
    if (tableExists('invoices', $pdo)) {
        $totalIncome = analytics_sum_invoices($pdo, $start, $end, 'amount_paid');
    } else {
        $notes[] = 'Invoice table not found; revenue KPIs unavailable.';
    }

    $totalExpenses = analytics_sum_expenses($pdo, $start, $end);
    $netProfit = $totalIncome - $totalExpenses;

    $voucherCount = 0;
    $pendingVouchers = 0;
    if (tableExists('payment_vouchers', $pdo)) {
        $sql = "SELECT COUNT(*) FROM payment_vouchers WHERE date_created BETWEEN ? AND ?";
        $params = [$start, $end];
        reportEngineApplySqlFilters($sql, $params, $filters, ['voucher_status' => 'status']);
        reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $voucherCount = (int) ($st->fetchColumn() ?: 0);

        $sql2 = "SELECT COUNT(*) FROM payment_vouchers
                 WHERE status NOT IN ('approved','rejected','cancelled') AND date_created BETWEEN ? AND ?";
        $params2 = [$start, $end];
        reportEngineAppendScope($sql2, $params2, 'payment_vouchers', $pdo);
        $st2 = $pdo->prepare($sql2);
        $st2->execute($params2);
        $pendingVouchers = (int) ($st2->fetchColumn() ?: 0);
    }

    $cashBalance = 0.0;
    $accountCount = 0;
    if (tableExists('financial_accounts', $pdo)) {
        $sql = 'SELECT COALESCE(SUM(current_balance), 0), COUNT(*) FROM financial_accounts WHERE status = \'active\'';
        $params = [];
        if (!empty($filters['account_id'])) {
            $sql .= ' AND id = ?';
            $params[] = (int) $filters['account_id'];
        }
        reportEngineAppendScope($sql, $params, 'financial_accounts', $pdo);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $cashBalance = (float) ($row[0] ?? 0);
        $accountCount = (int) ($row[1] ?? 0);
    }

    $outstanding = 0.0;
    if (tableExists('invoices', $pdo)) {
        $sql = "SELECT COALESCE(SUM(balance_due), 0) FROM invoices WHERE status != 'cancelled'";
        $params = [];
        reportEngineAppendScope($sql, $params, 'invoices', $pdo);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $outstanding = (float) ($st->fetchColumn() ?: 0);
    }

    $outstandingPayables = reportDomainFinanceOutstandingPayables($pdo, $filters);

    $marginPct = $totalIncome > 0 ? round(($netProfit / $totalIncome) * 100, 1) : null;
    $exceptions = reportDomainFinanceExceptions($pdo, $filters, []);

    return [
        'total_income' => $totalIncome,
        'total_expenses' => $totalExpenses,
        'net_profit' => $netProfit,
        'profit_margin_pct' => $marginPct,
        'voucher_count' => $voucherCount,
        'pending_vouchers' => $pendingVouchers,
        'cash_balance' => $cashBalance,
        'account_count' => $accountCount,
        'outstanding_receivables' => $outstanding,
        'outstanding_payables' => $outstandingPayables,
        'exceptions_count' => count($exceptions),
        'data_quality_notes' => $notes,
    ];
}

function reportDomainFinanceMonthlyIncome(PDO $pdo, array $filters): array
{
    if (!tableExists('invoices', $pdo)) {
        return [];
    }
    $sql = "SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS ym,
                   COALESCE(SUM(amount_paid), 0) AS total, COUNT(*) AS count
            FROM invoices
            WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?";
    $params = [$filters['start_date'], $filters['end_date']];
    reportEngineAppendScope($sql, $params, 'invoices', $pdo);
    $sql .= ' GROUP BY ym ORDER BY ym ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['label'] = date('M Y', strtotime(($r['ym'] ?? date('Y-m')) . '-01'));
    }
    unset($r);

    return $rows;
}

function reportDomainFinanceMonthlyExpenses(PDO $pdo, array $filters): array
{
    if (!tableExists('payment_vouchers', $pdo)) {
        return [];
    }
    $sql = "SELECT DATE_FORMAT(date_created, '%Y-%m') AS ym,
                   COALESCE(SUM(total_amount), 0) AS total, COUNT(*) AS count
            FROM payment_vouchers
            WHERE status = 'approved' AND date_created BETWEEN ? AND ?";
    $params = [$filters['start_date'], $filters['end_date']];
    reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo);
    $sql .= ' GROUP BY ym ORDER BY ym ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['label'] = date('M Y', strtotime(($r['ym'] ?? date('Y-m')) . '-01'));
    }
    unset($r);

    return $rows;
}

function reportDomainFinanceExpenseCategories(PDO $pdo, array $filters): array
{
    if (!tableExists('payment_vouchers', $pdo)) {
        return [];
    }
    $sql = "SELECT LEFT(COALESCE(description, payee_name, 'Other'), 50) AS category,
                   COALESCE(SUM(total_amount), 0) AS total, COUNT(*) AS count
            FROM payment_vouchers
            WHERE status = 'approved' AND date_created BETWEEN ? AND ?";
    $params = [$filters['start_date'], $filters['end_date']];
    reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo);
    $sql .= ' GROUP BY category ORDER BY total DESC LIMIT 12';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reportDomainFinanceExpenseBreakdown(PDO $pdo, array $filters): array
{
    $start = $filters['start_date'];
    $end = $filters['end_date'];
    $lines = [];

    if (tableExists('payment_vouchers', $pdo)) {
        $sql = "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
                FROM payment_vouchers
                WHERE status = 'approved' AND date_created BETWEEN ? AND ?";
        $params = [$start, $end];
        reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $lines[] = [
            'source' => 'Payment Vouchers (approved)',
            'table' => 'payment_vouchers',
            'count' => (int) ($row['count'] ?? 0),
            'amount' => (float) ($row['total'] ?? 0),
        ];
    }

    if (tableExists('erp_expenses', $pdo)) {
        $sql = "SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
                FROM erp_expenses WHERE date BETWEEN ? AND ?";
        $params = [$start, $end];
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((float) ($row['total'] ?? 0) > 0) {
            $lines[] = [
                'source' => 'ERP Expenses',
                'table' => 'erp_expenses',
                'count' => (int) ($row['count'] ?? 0),
                'amount' => (float) ($row['total'] ?? 0),
            ];
        }
    }

    if (tableExists('expenses_requests', $pdo)) {
        $sql = "SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
                FROM expenses_requests
                WHERE status = 'approved' AND date BETWEEN ? AND ?";
        $params = [$start, $end];
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((float) ($row['total'] ?? 0) > 0) {
            $lines[] = [
                'source' => 'Approved Expense Requests',
                'table' => 'expenses_requests',
                'count' => (int) ($row['count'] ?? 0),
                'amount' => (float) ($row['total'] ?? 0),
            ];
        }
    }

    $total = array_sum(array_map(static fn($l) => (float) ($l['amount'] ?? 0), $lines));

    return [
        'lines' => $lines,
        'total_expenses' => $total,
        'line_items' => reportDomainFinanceExpenseLineItems($pdo, $filters),
    ];
}

function reportDomainFinanceExpenseLineItems(PDO $pdo, array $filters): array
{
    $start = $filters['start_date'];
    $end = $filters['end_date'];
    $items = [];

    if (tableExists('payment_vouchers', $pdo) && tableExists('voucher_items', $pdo)) {
        $sql = "SELECT pv.voucher_no, pv.payee_name, pv.date_created, pv.status,
                       vi.name AS item_name, vi.budget_type, vi.amount
                FROM voucher_items vi
                JOIN payment_vouchers pv ON pv.id = vi.voucher_id
                WHERE pv.status = 'approved' AND pv.date_created BETWEEN ? AND ?";
        $params = [$start, $end];
        reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo, 'pv');
        $sql .= ' ORDER BY pv.date_created DESC, vi.id ASC LIMIT 150';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'date' => substr((string) ($row['date_created'] ?? ''), 0, 10),
                'reference' => (string) ($row['voucher_no'] ?? ''),
                'payee' => (string) ($row['payee_name'] ?? ''),
                'category' => (string) ($row['budget_type'] ?? $row['item_name'] ?? 'General'),
                'description' => (string) ($row['item_name'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'source' => 'voucher_item',
            ];
        }
    }

    if ($items === [] && tableExists('payment_vouchers', $pdo)) {
        $sql = "SELECT voucher_no, payee_name, date_created, description, total_amount
                FROM payment_vouchers
                WHERE status = 'approved' AND date_created BETWEEN ? AND ?";
        $params = [$start, $end];
        reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo);
        $sql .= ' ORDER BY date_created DESC LIMIT 100';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'date' => substr((string) ($row['date_created'] ?? ''), 0, 10),
                'reference' => (string) ($row['voucher_no'] ?? ''),
                'payee' => (string) ($row['payee_name'] ?? ''),
                'category' => (string) ($row['description'] ?? 'General'),
                'description' => (string) ($row['description'] ?? ''),
                'amount' => (float) ($row['total_amount'] ?? 0),
                'source' => 'payment_voucher',
            ];
        }
    }

    return $items;
}

function reportDomainFinanceExpenseCategoriesHtml(array $snapshot, array $kpis): string
{
    $categories = $snapshot['expense_categories'] ?? [];
    if ($categories === []) {
        return '<p class="sr-muted">No expense categories for this period.</p>';
    }

    $total = (float) ($kpis['total_expenses'] ?? 0);
    if ($total <= 0) {
        $total = array_sum(array_map(static fn($r) => (float) ($r['total'] ?? 0), $categories));
    }

    $rows = [];
    $cumulative = 0.0;
    foreach ($categories as $r) {
        $amount = (float) ($r['total'] ?? 0);
        $cumulative += $amount;
        $pct = $total > 0 ? round(($amount / $total) * 100, 1) : 0.0;
        $cumPct = $total > 0 ? round(($cumulative / $total) * 100, 1) : 0.0;
        $rows[] = [
            (string) ($r['category'] ?? ''),
            number_format((int) ($r['count'] ?? 0)),
            salesReportsFormatMoney($amount),
            number_format($pct, 1) . '%',
            number_format($cumPct, 1) . '%',
        ];
    }
    $rows[] = ['Total', '', salesReportsFormatMoney($total), '100%', '100%'];

    return reportEngineRenderDataTable(
        ['Category', 'Transactions', 'Amount', '% of Total', 'Cumulative %'],
        $rows
    );
}

function reportDomainFinanceExpenseCalculationsHtml(PDO $pdo, array $filters, array $snapshot, array $kpis): string
{
    $breakdown = $snapshot['expense_breakdown'] ?? reportDomainFinanceExpenseBreakdown($pdo, $filters);
    $lines = $breakdown['lines'] ?? [];
    $totalExpenses = (float) ($kpis['total_expenses'] ?? ($breakdown['total_expenses'] ?? 0));
    $totalIncome = (float) ($kpis['total_income'] ?? 0);
    $netProfit = (float) ($kpis['net_profit'] ?? ($totalIncome - $totalExpenses));
    $period = salesReportsFormatPeriod($filters['start_date'], $filters['end_date']);

    $html = '<p><strong>Expense calculation for ' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . '</strong></p>';

    if ($lines === []) {
        $html .= '<p class="sr-muted">No expense records found for this period.</p>';
        return $html;
    }

    $sourceRows = [];
    foreach ($lines as $line) {
        $amount = (float) ($line['amount'] ?? 0);
        $share = $totalExpenses > 0 ? number_format(($amount / $totalExpenses) * 100, 1) . '%' : '0%';
        $sourceRows[] = [
            (string) ($line['source'] ?? ''),
            (string) ($line['table'] ?? ''),
            number_format((int) ($line['count'] ?? 0)),
            salesReportsFormatMoney($amount),
            $share,
        ];
    }
    $sourceRows[] = ['Total Expenses', 'Sum of sources above', '', salesReportsFormatMoney($totalExpenses), '100%'];

    $html .= reportEngineRenderDataTable(
        ['Expense Source', 'Data Table', 'Count', 'Amount', '% of Total Expenses'],
        $sourceRows
    );

    $html .= reportEngineRenderDataTable(
        ['Calculation', 'Formula', 'Result'],
        [
            ['Total Income (collected)', 'Sum of invoice payments in period', salesReportsFormatMoney($totalIncome)],
            ['Total Expenses', 'Sum of approved vouchers and other expense sources', salesReportsFormatMoney($totalExpenses)],
            ['Net Profit / (Loss)', 'Total Income ? Total Expenses', salesReportsFormatMoney($netProfit)],
            ['Expense Ratio', 'Total Expenses ÷ Total Income', $totalIncome > 0 ? number_format(($totalExpenses / $totalIncome) * 100, 1) . '%' : 'N/A'],
            ['Net Margin', 'Net Profit ÷ Total Income', $totalIncome > 0 ? number_format(($netProfit / $totalIncome) * 100, 1) . '%' : 'N/A'],
        ]
    );

    $monthly = $snapshot['monthly_expenses'] ?? [];
    if ($monthly !== []) {
        $monthRows = [];
        $running = 0.0;
        foreach ($monthly as $m) {
            $amount = (float) ($m['total'] ?? 0);
            $running += $amount;
            $monthRows[] = [
                (string) ($m['label'] ?? $m['ym'] ?? ''),
                number_format((int) ($m['count'] ?? 0)),
                salesReportsFormatMoney($amount),
                salesReportsFormatMoney($running),
            ];
        }
        $html .= '<p><strong>Monthly expense accumulation</strong></p>';
        $html .= reportEngineRenderDataTable(
            ['Month', 'Vouchers', 'Monthly Expense', 'Running Total'],
            $monthRows
        );
    }

    $lineItems = $breakdown['line_items'] ?? [];
    if ($lineItems !== []) {
        $html .= '<p><strong>Expense line detail</strong></p>';
        $html .= reportEngineRenderDataTable(
            ['Date', 'Reference', 'Payee', 'Category', 'Description', 'Amount'],
            array_map(static fn($r) => [
                (string) ($r['date'] ?? ''),
                (string) ($r['reference'] ?? ''),
                (string) ($r['payee'] ?? ''),
                (string) ($r['category'] ?? ''),
                (string) ($r['description'] ?? ''),
                salesReportsFormatMoney((float) ($r['amount'] ?? 0)),
            ], array_slice($lineItems, 0, 100))
        );
    }

    return $html;
}

function reportDomainFinanceAccountBalances(PDO $pdo, array $filters): array
{
    if (!tableExists('financial_accounts', $pdo)) {
        return [];
    }
    $sql = "SELECT id, name, type, currency, current_balance FROM financial_accounts WHERE status = 'active'";
    $params = [];
    if (!empty($filters['account_id'])) {
        $sql .= ' AND id = ?';
        $params[] = (int) $filters['account_id'];
    }
    reportEngineAppendScope($sql, $params, 'financial_accounts', $pdo);
    $sql .= ' ORDER BY current_balance DESC LIMIT 20';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reportDomainFinanceArSummary(PDO $pdo, array $filters): array
{
    if (!tableExists('invoices', $pdo)) {
        return [];
    }
    require_once dirname(__DIR__, 2) . '/analytics/includes/smart_report_sales_helpers.php';
    $drill = smart_report_sales_drilldown($pdo, $filters);

    return $drill['ar_aging'] ?? [];
}

function reportDomainFinanceExceptions(PDO $pdo, array $filters, array $kpis): array
{
    $exceptions = [];
    if (($kpis['pending_vouchers'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'pending_vouchers',
            'message' => number_format((int) $kpis['pending_vouchers']) . ' payment voucher(s) awaiting approval.',
            'severity' => 'medium',
        ];
    }
    if (($kpis['net_profit'] ?? 0) < 0) {
        $exceptions[] = [
            'type' => 'negative_profit',
            'message' => 'Net profit is negative for the selected period.',
            'severity' => 'high',
        ];
    }
    if (($kpis['outstanding_receivables'] ?? 0) > ($kpis['total_income'] ?? 0) * 0.5 && ($kpis['total_income'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'high_receivables',
            'message' => 'Outstanding receivables exceed 50% of collected income.',
            'severity' => 'medium',
        ];
    }

    return $exceptions;
}

function reportDomainFinanceErpMenu(): array
{
    return [
        'Summary' => [
            'finance_summary' => 'Financial Summary KPIs',
            'income_expense_trend' => 'Income vs Expense Trend',
            'financial_ratios' => 'Financial Ratios',
        ],
        'Statements' => [
            'profit_loss' => 'Profit & Loss Statement',
            'cash_flow' => 'Cash Flow Statement',
            'balance_sheet' => 'Balance Sheet',
            'trial_balance' => 'Trial Balance',
        ],
        'Cash & Bank' => [
            'account_balances' => 'Cash & Bank Balances',
            'bank_reconciliation' => 'Bank Reconciliation',
            'general_ledger' => 'General Ledger Activity',
        ],
        'Receivables & Payables' => [
            'accounts_receivable_summary' => 'Accounts Receivable Summary',
            'accounts_receivable' => 'AR Aging Analysis',
            'accounts_payable_summary' => 'Accounts Payable Summary',
            'ap_aging' => 'AP Aging Analysis',
        ],
        'Analysis' => [
            'income_detail' => 'Income / Revenue Detail',
            'expense_categories' => 'Expense Categories',
            'expense_calculations' => 'Expense Calculations',
            'voucher_list' => 'Payment Vouchers',
            'budget_vs_actual' => 'Budget vs Actual',
            'comparative_period' => 'Comparative Period Analysis',
        ],
    ];
}

function reportDomainFinanceFetch(PDO $pdo, string $source, array $filters): array
{
    $snapshot = reportDomainFinanceSnapshot($pdo, $filters);
    $kpis = $snapshot['kpis'] ?? [];
    $period = salesReportsFormatPeriod($filters['start_date'], $filters['end_date']);
    $start = $filters['start_date'];
    $end = $filters['end_date'];
    $hasLedger = function_exists('smart_report_finance_has_ledger') && smart_report_finance_has_ledger($pdo);

    return match ($source) {
        'finance_summary' => [
            'html' => reportEngineRenderKpiTable([
                ['label' => 'Total Income (Collected)', 'value' => salesReportsFormatMoney((float) ($kpis['total_income'] ?? 0))],
                ['label' => 'Total Expenses', 'value' => salesReportsFormatMoney((float) ($kpis['total_expenses'] ?? 0))],
                ['label' => 'Net Profit', 'value' => salesReportsFormatMoney((float) ($kpis['net_profit'] ?? 0))],
                ['label' => 'Profit Margin', 'value' => $kpis['profit_margin_pct'] !== null ? number_format((float) $kpis['profit_margin_pct'], 1) . '%' : 'N/A'],
                ['label' => 'Cash / Bank Balance', 'value' => salesReportsFormatMoney((float) ($kpis['cash_balance'] ?? 0))],
                ['label' => 'Outstanding Receivables', 'value' => salesReportsFormatMoney((float) ($kpis['outstanding_receivables'] ?? 0))],
                ['label' => 'Outstanding Payables', 'value' => salesReportsFormatMoney((float) ($kpis['outstanding_payables'] ?? 0))],
                ['label' => 'Pending Vouchers', 'value' => number_format((int) ($kpis['pending_vouchers'] ?? 0))],
            ], $period),
            'snapshot' => $kpis,
        ],
        'income_expense_trend' => [
            'html' => reportDomainFinanceTrendCombinedHtml($snapshot),
            'snapshot' => ['income' => $snapshot['monthly_revenue'] ?? [], 'expense' => $snapshot['monthly_expenses'] ?? []],
        ],
        'income_detail' => [
            'html' => reportDomainFinanceIncomeDetailHtml($snapshot),
            'snapshot' => $snapshot['monthly_revenue'] ?? [],
        ],
        'expense_categories' => [
            'html' => reportDomainFinanceExpenseCategoriesHtml($snapshot, $kpis),
            'snapshot' => $snapshot['expense_categories'] ?? [],
        ],
        'expense_calculations' => [
            'html' => reportDomainFinanceExpenseCalculationsHtml($pdo, $filters, $snapshot, $kpis),
            'snapshot' => $snapshot['expense_breakdown'] ?? [],
        ],
        'account_balances' => [
            'html' => reportEngineRenderDataTable(
                ['Account', 'Type', 'Currency', 'Balance'],
                array_map(static fn($r) => [
                    (string) ($r['name'] ?? ''),
                    (string) ($r['type'] ?? ''),
                    (string) ($r['currency'] ?? ''),
                    salesReportsFormatMoney((float) ($r['current_balance'] ?? 0)),
                ], $snapshot['accounts'] ?? [])
            ),
            'snapshot' => $snapshot['accounts'] ?? [],
        ],
        'accounts_receivable' => [
            'html' => reportDomainFinanceArAgingTableHtml($snapshot['ar_aging'] ?? []),
            'snapshot' => $snapshot['ar_aging'] ?? [],
        ],
        'accounts_receivable_summary' => [
            'html' => reportDomainFinanceArSummaryHtml($pdo, $filters, $kpis),
            'snapshot' => $snapshot['ar_aging'] ?? [],
        ],
        'accounts_payable_summary' => [
            'html' => reportDomainFinanceApSummaryHtml($pdo, $filters, $kpis),
            'snapshot' => $snapshot['ap_summary'] ?? [],
        ],
        'ap_aging' => [
            'html' => reportDomainFinanceApAgingTableHtml($snapshot['ap_aging'] ?? []),
            'snapshot' => $snapshot['ap_aging'] ?? [],
        ],
        'voucher_list' => [
            'html' => reportDomainFinanceVoucherListHtml($pdo, $filters),
            'snapshot' => [],
        ],
        'profit_loss' => [
            'html' => reportDomainFinanceProfitLossHtml($pdo, $start, $end, $kpis, $hasLedger),
            'snapshot' => [],
        ],
        'cash_flow' => [
            'html' => reportDomainFinanceCashFlowHtml($pdo, $start, $end, $snapshot, $hasLedger),
            'snapshot' => [],
        ],
        'bank_reconciliation' => [
            'html' => reportDomainFinanceBankReconciliationHtml($pdo, $filters, $snapshot),
            'snapshot' => [],
        ],
        'trial_balance' => [
            'html' => reportDomainFinanceTrialBalanceHtml($pdo, $start, $end, $hasLedger),
            'snapshot' => [],
        ],
        'balance_sheet' => [
            'html' => reportDomainFinanceBalanceSheetHtml($pdo, $end, $kpis, $hasLedger),
            'snapshot' => [],
        ],
        'general_ledger' => [
            'html' => reportDomainFinanceGeneralLedgerHtml($pdo, $start, $end, $hasLedger),
            'snapshot' => [],
        ],
        'budget_vs_actual' => [
            'html' => reportDomainFinanceBudgetVsActualHtml($pdo, $filters, $kpis),
            'snapshot' => [],
        ],
        'financial_ratios' => [
            'html' => reportDomainFinanceRatiosHtml($kpis, $snapshot),
            'snapshot' => [],
        ],
        'comparative_period' => [
            'html' => reportDomainFinanceComparativeHtml($pdo, $filters, $kpis),
            'snapshot' => [],
        ],
        default => ['html' => '<p class="sr-muted">Unknown finance data source.</p>', 'snapshot' => []],
    };
}

function reportDomainFinanceTrendCombinedHtml(array $snapshot): string
{
    $income = [];
    foreach ($snapshot['monthly_revenue'] ?? [] as $r) {
        $income[$r['ym'] ?? ''] = (float) ($r['total'] ?? 0);
    }
    $expense = [];
    foreach ($snapshot['monthly_expenses'] ?? [] as $r) {
        $expense[$r['ym'] ?? ''] = (float) ($r['total'] ?? 0);
    }
    $months = array_unique(array_merge(array_keys($income), array_keys($expense)));
    sort($months);
    if ($months === []) {
        return '<p class="sr-muted">No monthly trend data for this period.</p>';
    }
    $rows = [];
    foreach ($months as $ym) {
        $rows[] = [
            date('M Y', strtotime($ym . '-01')),
            salesReportsFormatMoney($income[$ym] ?? 0),
            salesReportsFormatMoney($expense[$ym] ?? 0),
            salesReportsFormatMoney(($income[$ym] ?? 0) - ($expense[$ym] ?? 0)),
        ];
    }

    return reportEngineRenderDataTable(['Month', 'Income', 'Expenses', 'Net'], $rows);
}

function reportDomainFinanceArHtml(array $ar): string
{
    return reportDomainFinanceArAgingTableHtml($ar);
}

function reportDomainFinanceVoucherListHtml(PDO $pdo, array $filters): string
{
    if (!tableExists('payment_vouchers', $pdo)) {
        return '<p class="sr-muted">Payment vouchers not available.</p>';
    }
    $sql = "SELECT voucher_no, payee_name, total_amount, date_created, status
            FROM payment_vouchers WHERE date_created BETWEEN ? AND ?";
    $params = [$filters['start_date'], $filters['end_date']];
    reportEngineApplySqlFilters($sql, $params, $filters, ['voucher_status' => 'status']);
    reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo);
    $sql .= ' ORDER BY date_created DESC LIMIT 100';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return reportEngineRenderDataTable(
        ['Voucher #', 'Payee', 'Amount', 'Date', 'Status'],
        array_map(static fn($r) => [
            (string) ($r['voucher_no'] ?? ''),
            (string) ($r['payee_name'] ?? ''),
            salesReportsFormatMoney((float) ($r['total_amount'] ?? 0)),
            substr((string) ($r['date_created'] ?? ''), 0, 10),
            (string) ($r['status'] ?? ''),
        ], $rows)
    );
}

function reportDomainFinanceOutstandingPayables(PDO $pdo, array $filters): float
{
    $total = 0.0;
    if (tableExists('payment_vouchers', $pdo)) {
        $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM payment_vouchers
                WHERE status NOT IN ('approved','rejected','cancelled','paid')";
        $params = [];
        reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo);
        if (columnExists('payment_vouchers', 'is_paid', $pdo)) {
            $sql .= " AND (is_paid = 0 OR is_paid IS NULL)";
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $total += (float) ($st->fetchColumn() ?: 0);
    }
    if (tableExists('erp_outstanding_invoices', $pdo)) {
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM erp_outstanding_invoices WHERE type = 'payable' AND status = 'outstanding'";
        $params = [];
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $total += (float) ($st->fetchColumn() ?: 0);
    }

    return $total;
}

function reportDomainFinanceApSummary(PDO $pdo, array $filters): array
{
    $summary = [
        'total_outstanding' => reportDomainFinanceOutstandingPayables($pdo, $filters),
        'pending_vouchers' => 0,
        'manual_payables' => 0,
    ];
    if (tableExists('payment_vouchers', $pdo)) {
        $sql = "SELECT COUNT(*) FROM payment_vouchers
                WHERE status NOT IN ('approved','rejected','cancelled','paid')";
        $params = [];
        reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $summary['pending_vouchers'] = (int) ($st->fetchColumn() ?: 0);
    }
    if (tableExists('erp_outstanding_invoices', $pdo)) {
        $st = $pdo->query("SELECT COALESCE(SUM(amount), 0), COUNT(*) FROM erp_outstanding_invoices WHERE type = 'payable' AND status = 'outstanding'");
        $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $summary['manual_payables'] = (float) ($row[0] ?? 0);
        $summary['manual_payable_count'] = (int) ($row[1] ?? 0);
    }

    return $summary;
}

function reportDomainFinanceApAging(PDO $pdo, array $filters): array
{
    $buckets = [
        'current' => 0.0,
        'days_1_30' => 0.0,
        'days_31_60' => 0.0,
        'days_61_90' => 0.0,
        'days_90_plus' => 0.0,
        'total_outstanding' => 0.0,
        'items' => [],
    ];
    $today = date('Y-m-d');

    if (tableExists('payment_vouchers', $pdo)) {
        $sql = "SELECT payee_name, total_amount, date_created, status
                FROM payment_vouchers
                WHERE status NOT IN ('approved','rejected','cancelled','paid')";
        $params = [];
        reportEngineAppendScope($sql, $params, 'payment_vouchers', $pdo);
        if (columnExists('payment_vouchers', 'is_paid', $pdo)) {
            $sql .= ' AND (is_paid = 0 OR is_paid IS NULL)';
        }
        $sql .= ' ORDER BY date_created ASC LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $amount = (float) ($row['total_amount'] ?? 0);
            $days = max(0, (int) floor((strtotime($today) - strtotime(substr((string) ($row['date_created'] ?? $today), 0, 10))) / 86400));
            $bucket = reportDomainFinanceAgingBucket($days);
            $buckets[$bucket] += $amount;
            $buckets['total_outstanding'] += $amount;
            $buckets['items'][] = [
                'party' => (string) ($row['payee_name'] ?? 'Supplier'),
                'amount' => $amount,
                'days' => $days,
                'bucket' => $bucket,
            ];
        }
    }

    if (tableExists('erp_outstanding_invoices', $pdo)) {
        $st = $pdo->query("SELECT entity_name, amount, invoice_date FROM erp_outstanding_invoices WHERE type = 'payable' AND status = 'outstanding'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $days = max(0, (int) floor((strtotime($today) - strtotime(substr((string) ($row['invoice_date'] ?? $today), 0, 10))) / 86400));
            $bucket = reportDomainFinanceAgingBucket($days);
            $buckets[$bucket] += $amount;
            $buckets['total_outstanding'] += $amount;
            $buckets['items'][] = [
                'party' => (string) ($row['entity_name'] ?? 'Payable'),
                'amount' => $amount,
                'days' => $days,
                'bucket' => $bucket,
            ];
        }
    }

    return $buckets;
}

function reportDomainFinanceAgingBucket(int $days): string
{
    if ($days <= 0) {
        return 'current';
    }
    if ($days <= 30) {
        return 'days_1_30';
    }
    if ($days <= 60) {
        return 'days_31_60';
    }
    if ($days <= 90) {
        return 'days_61_90';
    }

    return 'days_90_plus';
}

function reportDomainFinanceArAgingTableHtml(array $ar): string
{
    if ($ar === []) {
        return '<p class="sr-muted">Accounts receivable aging data not available.</p>';
    }

    return reportEngineRenderDataTable(
        ['Aging Bucket', 'Amount'],
        [
            ['Current', salesReportsFormatMoney((float) ($ar['current'] ?? 0))],
            ['1-30 days', salesReportsFormatMoney((float) ($ar['days_1_30'] ?? 0))],
            ['31-60 days', salesReportsFormatMoney((float) ($ar['days_31_60'] ?? 0))],
            ['61-90 days', salesReportsFormatMoney((float) ($ar['days_61_90'] ?? 0))],
            ['90+ days', salesReportsFormatMoney((float) ($ar['days_90_plus'] ?? 0))],
            ['Total Outstanding', salesReportsFormatMoney((float) ($ar['total_outstanding'] ?? 0))],
        ]
    );
}

function reportDomainFinanceApAgingTableHtml(array $ap): string
{
    if (($ap['total_outstanding'] ?? 0) <= 0 && ($ap['items'] ?? []) === []) {
        return '<p class="sr-muted">No outstanding payables found for aging analysis.</p>';
    }

    $html = reportEngineRenderDataTable(
        ['Aging Bucket', 'Amount'],
        [
            ['Current', salesReportsFormatMoney((float) ($ap['current'] ?? 0))],
            ['1-30 days', salesReportsFormatMoney((float) ($ap['days_1_30'] ?? 0))],
            ['31-60 days', salesReportsFormatMoney((float) ($ap['days_31_60'] ?? 0))],
            ['61-90 days', salesReportsFormatMoney((float) ($ap['days_61_90'] ?? 0))],
            ['90+ days', salesReportsFormatMoney((float) ($ap['days_90_plus'] ?? 0))],
            ['Total Outstanding', salesReportsFormatMoney((float) ($ap['total_outstanding'] ?? 0))],
        ]
    );

    $items = array_slice($ap['items'] ?? [], 0, 50);
    if ($items !== []) {
        $html .= reportEngineRenderDataTable(
            ['Supplier / Payee', 'Days', 'Bucket', 'Amount'],
            array_map(static fn($r) => [
                (string) ($r['party'] ?? ''),
                number_format((int) ($r['days'] ?? 0)),
                str_replace('_', '-', (string) ($r['bucket'] ?? '')),
                salesReportsFormatMoney((float) ($r['amount'] ?? 0)),
            ], $items)
        );
    }

    return $html;
}

function reportDomainFinanceArSummaryHtml(PDO $pdo, array $filters, array $kpis): string
{
    return reportEngineRenderKpiTable([
        ['label' => 'Total Outstanding Receivables', 'value' => salesReportsFormatMoney((float) ($kpis['outstanding_receivables'] ?? 0))],
        ['label' => 'Income Collected (Period)', 'value' => salesReportsFormatMoney((float) ($kpis['total_income'] ?? 0))],
        ['label' => 'AR as % of Period Income', 'value' => ($kpis['total_income'] ?? 0) > 0
            ? number_format(((float) ($kpis['outstanding_receivables'] ?? 0) / (float) $kpis['total_income']) * 100, 1) . '%'
            : 'N/A'],
    ]);
}

function reportDomainFinanceApSummaryHtml(PDO $pdo, array $filters, array $kpis): string
{
    $ap = reportDomainFinanceApSummary($pdo, $filters);

    return reportEngineRenderKpiTable([
        ['label' => 'Total Outstanding Payables', 'value' => salesReportsFormatMoney((float) ($kpis['outstanding_payables'] ?? 0))],
        ['label' => 'Pending Payment Vouchers', 'value' => number_format((int) ($ap['pending_vouchers'] ?? 0))],
        ['label' => 'Manual Payable Records', 'value' => salesReportsFormatMoney((float) ($ap['manual_payables'] ?? 0))],
    ]);
}

function reportDomainFinanceIncomeDetailHtml(array $snapshot): string
{
    $rows = [];
    foreach ($snapshot['monthly_revenue'] ?? [] as $r) {
        $rows[] = [
            (string) ($r['label'] ?? $r['ym'] ?? ''),
            salesReportsFormatMoney((float) ($r['total'] ?? 0)),
            number_format((int) ($r['count'] ?? 0)),
        ];
    }
    if ($rows === []) {
        return '<p class="sr-muted">No income detail for this period.</p>';
    }

    return reportEngineRenderDataTable(['Month', 'Collected Income', 'Invoice Count'], $rows);
}

function reportDomainFinanceProfitLossHtml(PDO $pdo, string $start, string $end, array $kpis, bool $hasLedger): string
{
    if ($hasLedger && function_exists('smart_report_finance_profit_loss')) {
        $pl = smart_report_finance_profit_loss($pdo, $start, $end);
        $rows = [];
        foreach ($pl['lines'] ?? [] as $line) {
            $type = (string) ($line['type'] ?? 'item');
            if ($type === 'section') {
                $rows[] = [(string) ($line['label'] ?? ''), '', ''];
                continue;
            }
            $rows[] = [
                (string) ($line['label'] ?? ''),
                salesReportsFormatMoney((float) ($line['current'] ?? 0)),
                salesReportsFormatMoney((float) ($line['previous'] ?? 0)),
            ];
        }
        if ($rows !== []) {
            $periods = $pl['periods'] ?? [];
            $curr = $periods['current']['short'] ?? 'Current';
            $prev = $periods['previous']['short'] ?? 'Previous';

            return reportEngineRenderDataTable(['Particulars', $curr, $prev], $rows);
        }
    }

    return reportEngineRenderDataTable(
        ['Line Item', 'Amount'],
        [
            ['Total Income (Collected)', salesReportsFormatMoney((float) ($kpis['total_income'] ?? 0))],
            ['Total Expenses', salesReportsFormatMoney((float) ($kpis['total_expenses'] ?? 0))],
            ['Net Profit / (Loss)', salesReportsFormatMoney((float) ($kpis['net_profit'] ?? 0))],
            ['Net Profit Margin', $kpis['profit_margin_pct'] !== null ? number_format((float) $kpis['profit_margin_pct'], 1) . '%' : 'N/A'],
        ]
    );
}

function reportDomainFinanceCashFlowHtml(PDO $pdo, string $start, string $end, array $snapshot, bool $hasLedger): string
{
    if ($hasLedger && function_exists('smart_report_finance_cash_flow')) {
        $cf = smart_report_finance_cash_flow($pdo, $start, $end);

        return reportEngineRenderDataTable(
            ['Cash Flow Line', 'Amount'],
            [
                ['Net Income', salesReportsFormatMoney((float) ($cf['net_income'] ?? 0))],
                ['Change in Accounts Receivable', salesReportsFormatMoney((float) ($cf['change_ar'] ?? 0))],
                ['Change in Inventory', salesReportsFormatMoney((float) ($cf['change_inventory'] ?? 0))],
                ['Change in Accounts Payable', salesReportsFormatMoney((float) ($cf['change_ap'] ?? 0))],
                ['Net Cash from Operating Activities', salesReportsFormatMoney((float) ($cf['operating_total'] ?? 0))],
                ['Net Cash from Investing Activities', salesReportsFormatMoney((float) ($cf['investing_total'] ?? 0))],
                ['Net Cash from Financing Activities', salesReportsFormatMoney((float) ($cf['financing_total'] ?? 0))],
                ['Net Change in Cash', salesReportsFormatMoney((float) ($cf['net_change'] ?? 0))],
                ['Cash at Beginning of Period', salesReportsFormatMoney((float) ($cf['start_cash'] ?? 0))],
                ['Cash at End of Period', salesReportsFormatMoney((float) ($cf['end_cash'] ?? 0))],
            ]
        );
    }

    $inflows = 0.0;
    $outflows = 0.0;
    if (tableExists('account_transactions', $pdo)) {
        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS inflows,
                    COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS outflows
                FROM account_transactions
                WHERE transaction_date BETWEEN ? AND ?";
        $params = [$start, $end];
        reportEngineAppendScope($sql, $params, 'account_transactions', $pdo);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $inflows = (float) ($row['inflows'] ?? 0);
        $outflows = (float) ($row['outflows'] ?? 0);
    } else {
        $inflows = (float) ($snapshot['kpis']['total_income'] ?? 0);
        $outflows = (float) ($snapshot['kpis']['total_expenses'] ?? 0);
    }

    return reportEngineRenderDataTable(
        ['Cash Flow Line', 'Amount'],
        [
            ['Cash Inflows', salesReportsFormatMoney($inflows)],
            ['Cash Outflows', salesReportsFormatMoney($outflows)],
            ['Net Cash Movement', salesReportsFormatMoney($inflows - $outflows)],
        ]
    );
}

function reportDomainFinanceBankReconciliationHtml(PDO $pdo, array $filters, array $snapshot): string
{
    $accounts = $snapshot['accounts'] ?? [];
    if ($accounts === []) {
        return '<p class="sr-muted">No bank or cash accounts found for reconciliation.</p>';
    }

    $rows = [];
    $start = $filters['start_date'];
    $end = $filters['end_date'];
    foreach ($accounts as $account) {
        $accountId = (int) ($account['id'] ?? 0);
        $bookBalance = (float) ($account['current_balance'] ?? 0);
        $periodNet = 0.0;
        if ($accountId > 0 && tableExists('account_transactions', $pdo)) {
            $sql = "SELECT
                        COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0)
                        - COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0)
                    FROM account_transactions
                    WHERE account_id = ? AND transaction_date BETWEEN ? AND ?";
            $st = $pdo->prepare($sql);
            $st->execute([$accountId, $start, $end]);
            $periodNet = (float) ($st->fetchColumn() ?: 0);
        }
        $rows[] = [
            (string) ($account['name'] ?? ''),
            salesReportsFormatMoney($bookBalance),
            salesReportsFormatMoney($periodNet),
            salesReportsFormatMoney($bookBalance - $periodNet),
        ];
    }

    return reportEngineRenderDataTable(
        ['Account', 'Book Balance', 'Period Net Movement', 'Variance Indicator'],
        $rows
    );
}

function reportDomainFinanceTrialBalanceHtml(PDO $pdo, string $start, string $end, bool $hasLedger): string
{
    if ($hasLedger && function_exists('smart_report_finance_trial_balance')) {
        $tb = smart_report_finance_trial_balance($pdo, $start, $end);
        $rows = array_map(static fn($r) => [
            (string) ($r['code'] ?? ''),
            (string) ($r['name'] ?? ''),
            (string) ($r['type'] ?? ''),
            salesReportsFormatMoney((float) ($r['debit'] ?? 0)),
            salesReportsFormatMoney((float) ($r['credit'] ?? 0)),
        ], $tb['rows'] ?? []);
        if ($rows !== []) {
            $rows[] = ['', 'TOTAL', '', salesReportsFormatMoney((float) ($tb['total_debit'] ?? 0)), salesReportsFormatMoney((float) ($tb['total_credit'] ?? 0))];
        }

        return reportEngineRenderDataTable(['Code', 'Account', 'Type', 'Debit', 'Credit'], $rows);
    }

    return '<p class="sr-muted">Trial balance requires a general ledger (erp_accounts / journal entries). Not available in this installation.</p>';
}

function reportDomainFinanceBalanceSheetHtml(PDO $pdo, string $asOf, array $kpis, bool $hasLedger): string
{
    if ($hasLedger && function_exists('smart_report_finance_balance_sheet')) {
        $bs = smart_report_finance_balance_sheet($pdo, $asOf);
        $rows = [];
        foreach (['asset_rows' => 'Assets', 'liability_rows' => 'Liabilities', 'equity_rows' => 'Equity'] as $key => $label) {
            $rows[] = [$label, ''];
            foreach ($bs[$key] ?? [] as $item) {
                $rows[] = ['  ' . (string) ($item['label'] ?? ''), salesReportsFormatMoney((float) ($item['amount'] ?? 0))];
            }
        }
        $rows[] = ['Retained Earnings (Current Period)', salesReportsFormatMoney((float) ($bs['retained_earnings'] ?? 0))];
        $rows[] = ['Total Assets', salesReportsFormatMoney((float) ($bs['total_assets'] ?? 0))];
        $rows[] = ['Total Liabilities + Equity', salesReportsFormatMoney((float) ($bs['total_liab_equity'] ?? 0))];

        return reportEngineRenderDataTable(['Account', 'Balance'], $rows);
    }

    return reportEngineRenderDataTable(
        ['Summary Item', 'Balance'],
        [
            ['Cash & Bank (Active Accounts)', salesReportsFormatMoney((float) ($kpis['cash_balance'] ?? 0))],
            ['Accounts Receivable', salesReportsFormatMoney((float) ($kpis['outstanding_receivables'] ?? 0))],
            ['Accounts Payable', salesReportsFormatMoney((float) ($kpis['outstanding_payables'] ?? 0))],
            ['Estimated Net Position', salesReportsFormatMoney((float) ($kpis['cash_balance'] ?? 0) + (float) ($kpis['outstanding_receivables'] ?? 0) - (float) ($kpis['outstanding_payables'] ?? 0))],
        ]
    );
}

function reportDomainFinanceGeneralLedgerHtml(PDO $pdo, string $start, string $end, bool $hasLedger): string
{
    if ($hasLedger && function_exists('smart_report_finance_general_ledger')) {
        $rows = smart_report_finance_general_ledger($pdo, $start, $end, 100);

        return reportEngineRenderDataTable(
            ['Date', 'Reference', 'Account', 'Debit', 'Credit', 'Description'],
            array_map(static fn($r) => [
                substr((string) ($r['date'] ?? ''), 0, 10),
                (string) ($r['reference'] ?? ''),
                (string) ($r['account_name'] ?? ''),
                salesReportsFormatMoney((float) ($r['debit'] ?? 0)),
                salesReportsFormatMoney((float) ($r['credit'] ?? 0)),
                (string) ($r['journal_desc'] ?? ''),
            ], $rows)
        );
    }

    if (!tableExists('account_transactions', $pdo)) {
        return '<p class="sr-muted">General ledger / account activity not available.</p>';
    }

    $sql = "SELECT t.transaction_date, t.type, t.amount, t.description, t.reference_type, t.reference_id, a.name AS account_name
            FROM account_transactions t
            JOIN financial_accounts a ON t.account_id = a.id
            WHERE t.transaction_date BETWEEN ? AND ?
            ORDER BY t.transaction_date DESC, t.id DESC
            LIMIT 100";
    $params = [$start, $end];
    reportEngineAppendScope($sql, $params, 'account_transactions', $pdo);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return reportEngineRenderDataTable(
        ['Date', 'Account', 'Type', 'Amount', 'Reference', 'Description'],
        array_map(static fn($r) => [
            substr((string) ($r['transaction_date'] ?? ''), 0, 10),
            (string) ($r['account_name'] ?? ''),
            ucfirst((string) ($r['type'] ?? '')),
            salesReportsFormatMoney((float) ($r['amount'] ?? 0)),
            trim((string) ($r['reference_type'] ?? '') . (($r['reference_id'] ?? '') !== '' ? ' #' . $r['reference_id'] : '')),
            (string) ($r['description'] ?? ''),
        ], $rows)
    );
}

function reportDomainFinanceBudgetVsActualHtml(PDO $pdo, array $filters, array $kpis): string
{
    $start = $filters['start_date'];
    $end = $filters['end_date'];
    if (tableExists('budgets', $pdo) && tableExists('budget_items', $pdo)) {
        $sql = "SELECT bi.item_name, bi.category, bi.budgeted_amount, b.name AS budget_name
                FROM budget_items bi
                JOIN budgets b ON b.id = bi.budget_id
                WHERE b.is_active = 1 AND b.start_date <= ? AND b.end_date >= ?
                ORDER BY bi.budgeted_amount DESC
                LIMIT 50";
        $st = $pdo->prepare($sql);
        $st->execute([$end, $start]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($items !== []) {
            $actual = (float) ($kpis['total_expenses'] ?? 0);
            $budgetTotal = array_sum(array_map(static fn($r) => (float) ($r['budgeted_amount'] ?? 0), $items));

            return reportEngineRenderDataTable(
                ['Budget', 'Line Item', 'Category', 'Budgeted', 'Actual (Period Expenses)', 'Variance'],
                array_map(static function ($r) use ($actual, $budgetTotal) {
                    $budgeted = (float) ($r['budgeted_amount'] ?? 0);
                    $share = $budgetTotal > 0 ? ($budgeted / $budgetTotal) * $actual : 0.0;

                    return [
                        (string) ($r['budget_name'] ?? ''),
                        (string) ($r['item_name'] ?? ''),
                        (string) ($r['category'] ?? ''),
                        salesReportsFormatMoney($budgeted),
                        salesReportsFormatMoney($share),
                        salesReportsFormatMoney($budgeted - $share),
                    ];
                }, $items)
            );
        }
    }

    if (tableExists('finance_budgets', $pdo)) {
        $month = date('Y-m', strtotime($start));
        $st = $pdo->prepare('SELECT category, amount FROM finance_budgets WHERE month = ? ORDER BY amount DESC');
        $st->execute([$month]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows !== []) {
            return reportEngineRenderDataTable(
                ['Category', 'Budget', 'Actual Expenses (Period)', 'Variance'],
                array_map(static fn($r) => [
                    (string) ($r['category'] ?? ''),
                    salesReportsFormatMoney((float) ($r['amount'] ?? 0)),
                    salesReportsFormatMoney((float) ($kpis['total_expenses'] ?? 0)),
                    salesReportsFormatMoney((float) ($r['amount'] ?? 0) - (float) ($kpis['total_expenses'] ?? 0)),
                ], $rows)
            );
        }
    }

    return '<p class="sr-muted">No budget records found for this period. Configure budgets in Finance to enable budget vs actual analysis.</p>';
}

function reportDomainFinanceRatiosHtml(array $kpis, array $snapshot): string
{
    $income = (float) ($kpis['total_income'] ?? 0);
    $expenses = (float) ($kpis['total_expenses'] ?? 0);
    $net = (float) ($kpis['net_profit'] ?? 0);
    $cash = (float) ($kpis['cash_balance'] ?? 0);
    $ar = (float) ($kpis['outstanding_receivables'] ?? 0);
    $ap = (float) ($kpis['outstanding_payables'] ?? 0);

    $rows = [
        ['Net Profit Margin', $kpis['profit_margin_pct'] !== null ? number_format((float) $kpis['profit_margin_pct'], 1) . '%' : 'N/A'],
        ['Expense Ratio', $income > 0 ? number_format(($expenses / $income) * 100, 1) . '%' : 'N/A'],
        ['Operating Cash Proxy (Income - Expenses)', salesReportsFormatMoney($net)],
        ['Current Liquidity Proxy (Cash / Expenses)', $expenses > 0 ? number_format($cash / $expenses, 2) . 'x' : 'N/A'],
        ['Receivables Turnover Proxy (Income / AR)', $ar > 0 ? number_format($income / $ar, 2) . 'x' : 'N/A'],
        ['Payables Coverage Proxy (Cash / AP)', $ap > 0 ? number_format($cash / $ap, 2) . 'x' : 'N/A'],
        ['Working Capital Proxy (Cash + AR - AP)', salesReportsFormatMoney($cash + $ar - $ap)],
    ];

    return reportEngineRenderDataTable(['Ratio / Metric', 'Value'], $rows);
}

function reportDomainFinanceComparativeHtml(PDO $pdo, array $filters, array $kpis): string
{
    $start = $filters['start_date'];
    $end = $filters['end_date'];
    if (function_exists('smart_report_finance_period_pair')) {
        $periods = smart_report_finance_period_pair($start, $end);
        $prevFilters = array_merge($filters, [
            'start_date' => $periods['previous']['start'],
            'end_date' => $periods['previous']['end'],
        ]);
        $prevKpis = reportDomainFinanceKpis($pdo, $prevFilters);
        $currLabel = $periods['current']['short'] ?? 'Current';
        $prevLabel = $periods['previous']['short'] ?? 'Previous';

        return reportEngineRenderDataTable(
            ['Metric', $currLabel, $prevLabel, 'Change'],
            [
                ['Total Income', salesReportsFormatMoney((float) ($kpis['total_income'] ?? 0)), salesReportsFormatMoney((float) ($prevKpis['total_income'] ?? 0)), reportDomainFinanceChangeLabel((float) ($kpis['total_income'] ?? 0), (float) ($prevKpis['total_income'] ?? 0))],
                ['Total Expenses', salesReportsFormatMoney((float) ($kpis['total_expenses'] ?? 0)), salesReportsFormatMoney((float) ($prevKpis['total_expenses'] ?? 0)), reportDomainFinanceChangeLabel((float) ($kpis['total_expenses'] ?? 0), (float) ($prevKpis['total_expenses'] ?? 0))],
                ['Net Profit', salesReportsFormatMoney((float) ($kpis['net_profit'] ?? 0)), salesReportsFormatMoney((float) ($prevKpis['net_profit'] ?? 0)), reportDomainFinanceChangeLabel((float) ($kpis['net_profit'] ?? 0), (float) ($prevKpis['net_profit'] ?? 0))],
                ['Cash / Bank Balance', salesReportsFormatMoney((float) ($kpis['cash_balance'] ?? 0)), salesReportsFormatMoney((float) ($prevKpis['cash_balance'] ?? 0)), reportDomainFinanceChangeLabel((float) ($kpis['cash_balance'] ?? 0), (float) ($prevKpis['cash_balance'] ?? 0))],
            ]
        );
    }

    return reportDomainFinanceTrendCombinedHtml(['monthly_revenue' => [], 'monthly_expenses' => []]);
}

function reportDomainFinanceChangeLabel(float $current, float $previous): string
{
    if (abs($previous) < 0.0001) {
        return abs($current) < 0.0001 ? '0%' : 'N/A';
    }
    $pct = (($current - $previous) / abs($previous)) * 100;

    return ($pct >= 0 ? '+' : '') . number_format($pct, 1) . '%';
}
