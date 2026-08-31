<?php

declare(strict_types=1);

require_once __DIR__ . '/report-engine.php';
require_once __DIR__ . '/report-domain-data.php';

function reportEngineAiNarrativeOnlyHint(string $domain): string
{
    return ' IMPORTANT: Key metrics are already shown in the KPI Overview table elsewhere in this report. '
        . 'Do NOT repeat a full KPI list, metric table, or bullet list of every figure. '
        . 'Write interpretive narrative only.';
}

function reportEngineAiSectionInstruction(string $domain, string $section): string
{
    $domain = reportEngineNormalizeDomain($domain);
    $label = reportEngineDomainLabel($domain);
    $narrative = reportEngineAiNarrativeOnlyHint($domain);

    $common = 'Write professional management-report HTML (p, ul, li only). Use ONLY figures from the data snapshot. Do not invent numbers. Skip topics with no supporting data.';

    return match ($domain) {
        'procurement' => match ($section) {
            'executive_summary' => $common . ' Summarize inventory value, stock levels, movements, and stockout risk.',
            'inventory_overview' => $common . ' Describe overall stock position using KPI figures.',
            'inventory_valuation' => $common . ' Analyze inventory value by category if data exists.',
            'stock_movement_analysis' => $common . ' Interpret stock inflows/outflows and movement types.',
            'fast_slow_moving' => $common . ' Comment on fast and slow moving SKUs from snapshot lists.',
            'low_stock_analysis' => $common . ' Highlight low stock and out-of-stock items requiring replenishment.',
            'exceptions_risks' => $common . ' Describe stockout/low stock exceptions only.',
            'key_findings', 'recommendations', 'action_plan', 'conclusion' => $common,
            default => $common . " Write content for the {$label} section.",
        },
        'finance' => match ($section) {
            'executive_summary' => $common . ' Summarize income, expenses, net profit, cash position, receivables, and payables.',
            'financial_overview' => $common . ' Provide a high-level view of financial performance for the period.',
            'income_revenue_analysis' => $common . ' Analyze collected income and monthly revenue trends.',
            'expense_analysis' => $common . ' Analyze expense sources, category share, and how total expenses are calculated.',
            'profit_loss_statement' => $common . ' Interpret the profit and loss statement using exact figures.',
            'cash_flow_statement' => $common . ' Explain operating, investing, and financing cash movements.',
            'cash_bank_balances' => $common . ' Comment on cash and bank account balances and liquidity.',
            'bank_reconciliation' => $common . ' Note any book vs movement variances across bank accounts.',
            'accounts_receivable' => $common . ' Summarize outstanding receivables and collection exposure.',
            'ar_aging_analysis' => $common . ' Analyze AR aging buckets and overdue exposure.',
            'accounts_payable' => $common . ' Summarize outstanding payables and pending obligations.',
            'ap_aging_analysis' => $common . ' Analyze AP aging buckets and overdue supplier balances.',
            'payment_voucher_analysis' => $common . ' Review payment voucher activity, status, and trends.',
            'trial_balance' => $common . ' Comment on trial balance totals and material account movements.',
            'balance_sheet' => $common . ' Interpret assets, liabilities, and equity position.',
            'general_ledger' => $common . ' Highlight notable general ledger / account transactions.',
            'budget_vs_actual' => $common . ' Compare budgeted amounts against actual expenses.',
            'financial_ratios' => $common . ' Interpret key financial ratios using snapshot KPIs only.',
            'comparative_period_analysis' => $common . ' Compare current period metrics with the prior period.',
            'key_findings' => $common . ' Summarize key findings, exceptions, and financial risks from the snapshot.',
            'recommendations', 'action_plan', 'conclusion' => $common,
            default => $common . " Write content for the {$label} section.",
        },
        'fleet' => match ($section) {
            'executive_summary' => $common . $narrative . ' Write 2-3 sentences with at most three headline figures.',
            'fleet_overview' => $common . $narrative . ' Describe operational patterns, utilization, and period activity in prose.',
            'driver_performance' => $common . ' Comment on driver rankings only if driver_performance data exists in the snapshot.',
            'route_cost_analysis' => $common . $narrative . ' Discuss route costs and delivery efficiency without restating all KPIs.',
            'operational_exceptions' => $common . ' Describe open trips or low completion rates from exceptions only.',
            'key_findings' => $common . $narrative . ' Provide 3-5 insight bullets focused on risks, gaps, and notable trends.',
            'recommendations' => $common . $narrative . ' Provide 3-5 practical recommendations as bullets. No metric recap.',
            'action_plan' => $common . $narrative . ' Provide 3-5 action steps as bullets. No metric recap.',
            'conclusion' => $common . $narrative . ' One short closing paragraph only.',
            default => $common . " Write content for the {$label} section.",
        },
        'store_warehouse' => match ($section) {
            'executive_summary' => $common . ' Summarize inventory value, stock levels, movements, and stockout risk.',
            'inventory_valuation' => $common . ' Analyze inventory value by category if data exists.',
            'stock_movement_analysis' => $common . ' Interpret stock inflows/outflows and movement types.',
            'fast_slow_moving' => $common . ' Comment on fast and slow moving SKUs from snapshot lists.',
            'low_stock_analysis' => $common . ' Highlight low stock and out-of-stock items requiring replenishment.',
            'exceptions_risks' => $common . ' Describe stockout/low stock exceptions only.',
            'key_findings', 'recommendations', 'action_plan', 'conclusion' => $common,
            default => $common . " Write content for the {$label} section.",
        },
        default => $common,
    };
}

function reportEngineAiRulesFallback(string $domain, string $section, array $snapshot): string
{
    $domain = reportEngineNormalizeDomain($domain);
    $kpis = $snapshot['kpis'] ?? [];
    $cur = htmlspecialchars($snapshot['currency'] ?? 'TZS');
    $period = htmlspecialchars($snapshot['period'] ?? '');

    if ($domain === 'procurement') {
        return match ($section) {
            'executive_summary' => '<p>Stock position for ' . $period . ': '
                . number_format((int) ($kpis['total_products'] ?? 0)) . ' products, '
                . number_format((float) ($kpis['total_units'] ?? 0), 0) . ' units on hand, valued at '
                . $cur . ' ' . number_format((float) ($kpis['inventory_value'] ?? 0), 0) . '.</p>',
            'key_findings' => '<ul><li>Low stock items: ' . number_format((int) ($kpis['low_stock_count'] ?? 0)) . '</li>'
                . '<li>Out of stock items: ' . number_format((int) ($kpis['out_of_stock_count'] ?? 0)) . '</li>'
                . '<li>Stock movements in period: ' . number_format((int) ($kpis['movement_count'] ?? 0)) . '</li></ul>',
            'recommendations' => '<ul><li>Replenish low and out-of-stock items</li><li>Review slow-moving stock for clearance or reorder adjustment</li></ul>',
            default => '<p>Stock analysis for ' . $period . ' based on ERP inventory data.</p>',
        };
    }

    if ($domain === 'finance') {
        return match ($section) {
            'executive_summary' => '<p>Financial performance for ' . $period . ': income '
                . $cur . ' ' . number_format((float) ($kpis['total_income'] ?? 0), 0)
                . ', expenses ' . $cur . ' ' . number_format((float) ($kpis['total_expenses'] ?? 0), 0)
                . ', net profit ' . $cur . ' ' . number_format((float) ($kpis['net_profit'] ?? 0), 0) . '.</p>',
            'key_findings' => '<ul><li>Net profit margin: '
                . ($kpis['profit_margin_pct'] !== null ? number_format((float) $kpis['profit_margin_pct'], 1) . '%' : 'N/A') . '</li>'
                . '<li>Outstanding receivables: ' . $cur . ' ' . number_format((float) ($kpis['outstanding_receivables'] ?? 0), 0) . '</li></ul>',
            default => '<p>Financial analysis for ' . $period . ' based on ERP finance data.</p>',
        };
    }

    if ($domain === 'fleet') {
        $trips = (int) ($kpis['total_trips'] ?? 0);
        $deliveries = (int) ($kpis['total_deliveries'] ?? 0);
        return match ($section) {
            'executive_summary' => '<p>Fleet operations for ' . $period . ' recorded '
                . number_format($trips) . ' trips and '
                . number_format($deliveries) . ' delivery orders.</p>',
            'fleet_overview' => $trips > 0 || $deliveries > 0
                ? '<p>Fleet activity for ' . $period . ' is summarized in the KPI table above. Review trip volume, completion rates, and route costs for operational follow-up.</p>'
                : '<p>No fleet trips or deliveries were recorded during ' . $period . '. Investigate scheduling, demand, or data capture if activity was expected.</p>',
            'key_findings' => $trips === 0 && $deliveries === 0
                ? '<ul><li>No trips or deliveries were recorded in the selected period.</li><li>Fleet utilization and driver activity require review if operations were expected.</li></ul>'
                : '<ul><li>Fleet KPIs for ' . $period . ' are shown in the KPI Overview section.</li><li>Review driver performance and monthly trip trends for detailed breakdowns.</li></ul>',
            'recommendations' => $trips === 0 && $deliveries === 0
                ? '<ul><li>Confirm whether fleet operations were planned for this period.</li><li>Verify delivery trip and order data is being captured in the ERP.</li><li>Assign follow-up to restore trip scheduling and reporting.</li></ul>'
                : '<ul><li>Review underperforming routes and drivers using the performance tables.</li><li>Address open trips and incomplete deliveries promptly.</li></ul>',
            'action_plan' => '<ul><li>Review KPI Overview and performance tables with the logistics team.</li><li>Assign owners for any open trips or delivery exceptions.</li><li>Schedule a follow-up review for the next reporting period.</li></ul>',
            'conclusion' => $trips === 0 && $deliveries === 0
                ? '<p>The fleet recorded no operational activity during ' . $period . '. Management should confirm whether this reflects actual operations or a data/process gap.</p>'
                : '<p>Fleet performance for ' . $period . ' is documented in the KPI and performance sections above. Use those tables as the basis for operational decisions.</p>',
            default => '<p>Fleet analysis for ' . $period . ' based on delivery trip and order data.</p>',
        };
    }

    if ($domain === 'store_warehouse') {
        return match ($section) {
            'executive_summary' => '<p>Inventory position: '
                . number_format((int) ($kpis['total_products'] ?? 0)) . ' products, '
                . number_format((float) ($kpis['total_units'] ?? 0), 0) . ' units on hand, valued at '
                . $cur . ' ' . number_format((float) ($kpis['inventory_value'] ?? 0), 0) . '.</p>',
            default => '<p>Store and warehouse analysis for ' . $period . ' based on ERP inventory data.</p>',
        };
    }

    return '<p></p>';
}

function reportEngineGenerateAiText(PDO $pdo, array $report, string $section, string $instruction = ''): array
{
    $domain = reportEngineReportDomain($report);
    if ($domain === 'sales') {
        return salesReportsGenerateAiText($pdo, $report, $section, $instruction);
    }

    reportEngineLoadDomainData();
    $snapshot = reportEngineBuildSnapshot($pdo, $report);
    $rulesText = reportEngineAiRulesFallback($domain, $section, $snapshot);

    $aiPath = dirname(__DIR__, 3) . '/includes/ai_helpers.php';
    if (!is_file($aiPath)) {
        return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
    }
    require_once $aiPath;

    if (!function_exists('ai_fetch_settings_row') || !function_exists('ai_openai_request')) {
        return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
    }

    try {
        $settings = ai_fetch_settings_row();
        if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
            return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
        }

        $catalog = reportEngineSectionCatalog($domain);
        $sectionLabel = $catalog[$section] ?? ucfirst(str_replace('_', ' ', $section));
        $dataJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $sectionGuide = reportEngineAiSectionInstruction($domain, $section);
        $domainLabel = reportEngineDomainLabel($domain);

        $system = "You are a professional {$domainLabel} writer for an ERP management report. "
            . 'CRITICAL: Use ONLY numeric figures from the JSON snapshot. Never invent numbers. '
            . 'Do NOT duplicate KPI tables or repeat every metric — KPI Overview already contains the figures. '
            . 'Write concise interpretive prose for narrative sections. Omit sections/topics with no data. '
            . 'Return plain HTML only (p, ul, li). No markdown.';

        $user = "Write content for \"{$sectionLabel}\".\n\n"
            . "Period: {$snapshot['period']}\n"
            . "Department: {$snapshot['department']}\n"
            . "Currency: {$snapshot['currency']}\n\n"
            . "Guidance: {$sectionGuide}\n\n"
            . "ERP Data Snapshot:\n{$dataJson}\n";
        if ($instruction !== '') {
            $user .= "\nAdditional instruction: {$instruction}\n";
        }

        $result = ai_openai_request([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);

        $text = trim((string) ($result['content'] ?? ''));
        if ($text === '') {
            return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
        }
        $text = preg_replace('/^```(?:html)?\s*|\s*```$/s', '', $text) ?? $text;

        return ['success' => true, 'source' => 'ai', 'text' => $text];
    } catch (Throwable $e) {
        error_log('reportEngineGenerateAiText: ' . $e->getMessage());

        return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
    }
}

function reportEngineGenerateAiTextForReport(PDO $pdo, array $report, string $section, string $instruction = ''): array
{
    return reportEngineGenerateAiText($pdo, $report, $section, $instruction);
}
