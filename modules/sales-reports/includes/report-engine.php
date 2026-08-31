<?php
/**
 * Multi-domain AI Report Engine  registry, schema, routing.
 * Sales remains the default domain; existing behaviour is preserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/sales-reports-format.php';
require_once dirname(__DIR__, 2) . '/analytics/includes/analytics_company_scope.php';

function reportEngineDomains(): array
{
    return [
        'sales' => [
            'key' => 'sales',
            'label' => 'Sales Report',
            'icon' => 'bi-graph-up-arrow',
            'description' => 'Revenue, team performance, quotations, and client analysis.',
            'department_default' => 'Sales',
            'color' => '#6366f1',
        ],
        'procurement' => [
            'key' => 'procurement',
            'label' => 'Stock Report',
            'icon' => 'bi-box-seam',
            'description' => 'Inventory levels, stock quantities, movements, valuation, and low-stock analysis.',
            'department_default' => 'Warehouse',
            'color' => '#059669',
        ],
        'finance' => [
            'key' => 'finance',
            'label' => 'Finance Report',
            'icon' => 'bi-cash-stack',
            'description' => 'Income, expenses, cash position, vouchers, and profitability.',
            'department_default' => 'Finance',
            'color' => '#2563eb',
        ],
        'fleet' => [
            'key' => 'fleet',
            'label' => 'Driver / Fleet Report',
            'icon' => 'bi-truck',
            'description' => 'Delivery trips, driver performance, route costs, and fleet operations.',
            'department_default' => 'Logistics',
            'color' => '#d97706',
        ],
        'store_warehouse' => [
            'key' => 'store_warehouse',
            'label' => 'Store / Warehouse Report',
            'icon' => 'bi-box-seam',
            'description' => 'Inventory levels, stock movements, valuation, and warehouse performance.',
            'department_default' => 'Warehouse',
            'color' => '#7c3aed',
        ],
    ];
}

function reportEngineNormalizeDomain(?string $domain): string
{
    $domain = strtolower(trim((string) $domain));
    $allowed = array_keys(reportEngineDomains());

    return in_array($domain, $allowed, true) ? $domain : 'sales';
}

function reportEngineReportDomain(array $report): string
{
    return reportEngineNormalizeDomain($report['report_domain'] ?? 'sales');
}

function reportEngineEnsureDomainColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        if ($pdo->query("SHOW COLUMNS FROM sales_reports LIKE 'report_domain'")->fetch() === false) {
            $pdo->exec("ALTER TABLE sales_reports ADD COLUMN report_domain VARCHAR(32) NOT NULL DEFAULT 'sales' AFTER report_type");
        }
        if ($pdo->query("SHOW COLUMNS FROM sales_reports LIKE 'filters_json'")->fetch() === false) {
            $pdo->exec("ALTER TABLE sales_reports ADD COLUMN filters_json LONGTEXT NULL AFTER report_domain");
        }
    } catch (Throwable $e) {
        error_log('reportEngineEnsureDomainColumns: ' . $e->getMessage());
    }
}

function reportEngineFiltersFromReport(array $report): array
{
    $base = [
        'start_date' => (string) ($report['start_date'] ?? date('Y-m-01')),
        'end_date' => (string) ($report['end_date'] ?? date('Y-m-d')),
    ];

    $decoded = [];
    if (!empty($report['filters_json'])) {
        $decoded = json_decode((string) $report['filters_json'], true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
    }

    return array_merge($base, $decoded);
}

function reportEngineParseDateRange(?string $start, ?string $end): array
{
    $start = $start ?? date('Y-m-01');
    $end = $end ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $start = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $end = date('Y-m-d');
    }
    if (strtotime($start) > strtotime($end)) {
        [$start, $end] = [$end, $start];
    }

    return ['start_date' => $start, 'end_date' => $end];
}

function reportEngineSectionCatalog(string $domain): array
{
    $domain = reportEngineNormalizeDomain($domain);
    if ($domain === 'sales') {
        return salesReportsSectionCatalog();
    }

    $common = [
        'cover' => 'Cover Page',
        'executive_summary' => 'Executive Summary',
        'kpi_overview' => 'Key Performance Indicators',
        'performance_overview' => 'Performance Overview',
        'detailed_analysis' => 'Detailed Analysis',
        'trend_analysis' => 'Trend Analysis',
        'exceptions_risks' => 'Exceptions & Risks',
        'key_findings' => 'Key Findings',
        'recommendations' => 'Recommendations',
        'action_plan' => 'Action Plan',
        'conclusion' => 'Conclusion',
        'appendix' => 'Appendix',
    ];

    $extra = match ($domain) {
        'procurement' => [
            'inventory_overview' => 'Stock Overview',
            'stock_movement_analysis' => 'Stock Movement Analysis',
            'inventory_valuation' => 'Inventory Valuation',
            'fast_slow_moving' => 'Fast & Slow Moving Items',
            'low_stock_analysis' => 'Low Stock & Stockout Analysis',
        ],
        'finance' => [
            'financial_overview' => 'Financial Overview',
            'income_revenue_analysis' => 'Income / Revenue Analysis',
            'expense_analysis' => 'Expense Analysis',
            'profit_loss_statement' => 'Profit & Loss Statement',
            'cash_flow_statement' => 'Cash Flow Statement',
            'cash_bank_balances' => 'Cash & Bank Balances',
            'bank_reconciliation' => 'Bank Reconciliation',
            'accounts_receivable' => 'Accounts Receivable',
            'ar_aging_analysis' => 'AR Aging Analysis',
            'accounts_payable' => 'Accounts Payable',
            'ap_aging_analysis' => 'AP Aging Analysis',
            'payment_voucher_analysis' => 'Payment Voucher Analysis',
            'trial_balance' => 'Trial Balance',
            'balance_sheet' => 'Balance Sheet',
            'general_ledger' => 'General Ledger / Account Activity',
            'budget_vs_actual' => 'Budget vs Actual',
            'financial_ratios' => 'Financial Ratios',
            'comparative_period_analysis' => 'Comparative Period Analysis',
        ],
        'fleet' => [
            'kpi_fleet_overview' => 'Key Performance Indicators & Fleet Overview',
            'operational_challenges' => 'Operational Challenges Log',
        ],
        'store_warehouse' => [
            'inventory_overview' => 'Inventory Overview',
            'stock_movement_analysis' => 'Stock Movement Analysis',
            'inventory_valuation' => 'Inventory Valuation',
            'fast_slow_moving' => 'Fast & Slow Moving Items',
            'low_stock_analysis' => 'Low Stock & Stockout Analysis',
        ],
        default => [],
    };

    $catalog = array_merge($common, $extra);
    if ($domain === 'finance') {
        $catalog['kpi_overview'] = 'Key Financial KPIs';
        $catalog['key_findings'] = 'Key Findings & Exceptions';
    }
    if ($domain === 'fleet') {
        $catalog['action_plan'] = 'Simple Driver Action Plan';
    }

    return $catalog;
}

function reportEngineDefaultSections(string $domain): array
{
    $domain = reportEngineNormalizeDomain($domain);

    return match ($domain) {
        'procurement' => [
            'cover', 'executive_summary', 'kpi_overview', 'inventory_overview',
            'stock_movement_analysis', 'inventory_valuation', 'fast_slow_moving',
            'low_stock_analysis', 'trend_analysis', 'exceptions_risks',
            'key_findings', 'recommendations', 'action_plan', 'conclusion',
        ],
        'finance' => [
            'cover', 'executive_summary', 'kpi_overview', 'financial_overview',
            'income_revenue_analysis', 'expense_analysis', 'profit_loss_statement',
            'cash_flow_statement', 'cash_bank_balances', 'bank_reconciliation',
            'accounts_receivable', 'ar_aging_analysis', 'accounts_payable', 'ap_aging_analysis',
            'payment_voucher_analysis', 'trial_balance', 'balance_sheet', 'general_ledger',
            'budget_vs_actual', 'financial_ratios', 'comparative_period_analysis',
            'key_findings', 'recommendations', 'action_plan', 'conclusion',
        ],
        'fleet' => [
            'cover', 'executive_summary', 'kpi_fleet_overview', 'operational_challenges',
            'key_findings', 'recommendations', 'action_plan', 'conclusion',
        ],
        'store_warehouse' => [
            'cover', 'executive_summary', 'kpi_overview', 'inventory_overview',
            'stock_movement_analysis', 'inventory_valuation', 'fast_slow_moving',
            'low_stock_analysis', 'trend_analysis', 'exceptions_risks',
            'key_findings', 'recommendations', 'action_plan', 'conclusion',
        ],
        default => salesReportsDepartmentSectionKeys(),
    };
}

function reportEngineTemplates(string $domain): array
{
    $domain = reportEngineNormalizeDomain($domain);
    if ($domain === 'sales') {
        return salesReportsTemplates();
    }

    $sections = reportEngineDefaultSections($domain);
    $label = reportEngineDomains()[$domain]['label'] ?? ucfirst($domain);

    return [
        'standard' => [
            'label' => $label,
            'type' => 'management',
            'sections' => $sections,
        ],
        'blank' => [
            'label' => 'Blank ' . $label,
            'type' => 'custom',
            'sections' => ['cover', 'executive_summary', 'conclusion'],
        ],
    ];
}

function reportEngineDefaultTemplateKey(string $domain): string
{
    return reportEngineNormalizeDomain($domain) === 'sales' ? 'department_quarterly' : 'standard';
}

function reportEngineDomainLabel(string $domain): string
{
    return reportEngineDomains()[reportEngineNormalizeDomain($domain)]['label'] ?? 'Report';
}

function reportEngineBuildCoverHtml(string $domain, array $meta): string
{
    $domain = reportEngineNormalizeDomain($domain);
    if ($domain === 'sales') {
        return salesReportsBuildDepartmentCoverHtml($meta);
    }

    $title = htmlspecialchars((string) ($meta['report_name'] ?? reportEngineDomainLabel($domain)), ENT_QUOTES, 'UTF-8');
    $period = htmlspecialchars(salesReportsFormatCoverPeriod(
        (string) ($meta['start_date'] ?? ''),
        (string) ($meta['end_date'] ?? '')
    ), ENT_QUOTES, 'UTF-8');
    $year = htmlspecialchars(salesReportsFormatCoverYear(
        (string) ($meta['start_date'] ?? ''),
        (string) ($meta['end_date'] ?? '')
    ), ENT_QUOTES, 'UTF-8');
    $prepared = salesReportsPreparedByLine($meta);
    $logo = salesReportsCompanyLogoHtml('72px', 'center');
    $dept = htmlspecialchars(reportEngineDomains()[$domain]['label'] ?? 'Report', ENT_QUOTES, 'UTF-8');
    $company = htmlspecialchars((string) ($_SESSION['company_name'] ?? 'Company'), ENT_QUOTES, 'UTF-8');

    return '<div class="sr-cover-page" style="page-break-after:always;text-align:center;padding:48px 24px;">'
        . $logo
        . '<h1 style="font-size:28px;margin:24px 0 8px;font-weight:700;">' . $title . '</h1>'
        . '<p style="font-size:14px;color:#64748b;margin:0 0 4px;">' . $company . '</p>'
        . '<p style="font-size:13px;color:#475569;margin:0 0 24px;">' . $dept . '</p>'
        . '<p style="font-size:15px;margin:0 0 8px;"><strong>Reporting Period:</strong> ' . $period . '</p>'
        . '<p style="font-size:14px;color:#64748b;margin:0 0 32px;">Year ' . $year . '</p>'
        . $prepared
        . '<p style="font-size:12px;color:#94a3b8;margin-top:32px;">Generated ' . date('d M Y') . '</p>'
        . '</div>';
}

function reportEnginePeriodDefaults(string $domain, array $user, ?string $startDate, ?string $endDate): array
{
    $domain = reportEngineNormalizeDomain($domain);
    $range = reportEngineParseDateRange($startDate, $endDate);
    $meta = reportEngineDomains()[$domain] ?? reportEngineDomains()['sales'];
    $periodLabel = salesReportsFormatCoverPeriod($range['start_date'], $range['end_date']);

    return [
        'report_domain' => $domain,
        'template_key' => reportEngineDefaultTemplateKey($domain),
        'report_type' => 'management',
        'start_date' => $range['start_date'],
        'end_date' => $range['end_date'],
        'report_name' => $periodLabel . ' ' . ($meta['label'] ?? 'Report'),
        'prepared_by' => trim((string) ($user['name'] ?? '')),
        'department' => trim((string) ($user['department'] ?? $meta['department_default'] ?? '')),
        'filters' => [],
    ];
}

function reportEngineDomainPeriodOptions(array $user = []): array
{
    $domains = reportEngineDomains();
    $options = [];

    foreach ($domains as $key => $meta) {
        if ($key === 'sales') {
            continue;
        }
        $defaults = reportEnginePeriodDefaults($key, $user, null, null);
        $options[] = [
            'key' => $key,
            'domain' => $key,
            'label' => $meta['label'],
            'icon' => $meta['icon'],
            'description' => $meta['description'],
            'color' => $meta['color'],
            'requires_date_range' => true,
            'defaults' => $defaults,
            'date_range' => salesReportsFormatPeriod($defaults['start_date'], $defaults['end_date']),
        ];
    }

    return $options;
}

function reportEngineAppendScope(string &$sql, array &$params, string $table, PDO $pdo, string $alias = ''): void
{
    if (function_exists('analytics_append_company_scope')) {
        analytics_append_company_scope($sql, $params, $table, $alias, $pdo);
    }
}

function reportEngineTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    return function_exists('columnExists') && columnExists($table, $column, $pdo);
}

function reportEngineLoadDomainData(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    require_once __DIR__ . '/report-domain-data.php';
    require_once __DIR__ . '/report-domain-autofill.php';
    require_once __DIR__ . '/report-domain-ai.php';
    $loaded = true;
}
