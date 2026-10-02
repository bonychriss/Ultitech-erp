<?php
/**
 * Multi-domain AI Report Engine � registry, schema, routing.
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
            'label' => 'Procurement Report',
            'icon' => 'bi-cart-check',
            'description' => 'Purchase orders, suppliers, import vs domestic spend, customs, and delivery follow-up.',
            'department_default' => 'Procurement',
            'color' => '#0f766e',
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
            'description' => 'Rider delivery report for the selected period, with monthly routes and routes by rider.',
            'department_default' => 'Delivery and Logistics',
            'color' => '#d97706',
        ],
        'store_warehouse' => [
            'key' => 'store_warehouse',
            'label' => 'Store Report',
            'icon' => 'bi-box-seam',
            'description' => 'Stock position, movements, purchases, suppliers, low stock, and replenishment.',
            'department_default' => 'Store / Inventory',
            'color' => '#059669',
        ],
        'operations' => [
            'key' => 'operations',
            'label' => 'Operations Report',
            'icon' => 'bi-gear',
            'description' => 'Operations department report for the selected period.',
            'department_default' => 'Operations',
            'color' => '#0e7490',
        ],
    ];
}

function reportEngineNormalizeDomain(?string $domain): string
{
    $domain = strtolower(trim((string) $domain));
    // Legacy aliases for Store Report only (not Procurement).
    if (in_array($domain, ['stock', 'store'], true)) {
        $domain = 'store_warehouse';
    }
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
            'procurement_activities' => 'Procurement Activities',
            'key_procurement_activities' => 'Key Procurement Activities',
            'suppliers_analysis' => 'Suppliers – Selected Period',
            'order_status_analysis' => 'Procurement / Order Status',
            'key_achievements' => 'Key Achievements',
            'challenges_issues' => 'Challenges',
            'period_comparison' => 'Previous Period Comparison',
            'procurement_trend' => 'Procurement Trend / Summary',
            'pending_deliveries' => 'Pending / Delayed Shipments',
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
            'fleet_introduction' => '1. Introduction',
            'fleet_performance' => '2. Performance Report',
            'fleet_overall' => '3. Overall Performance Summary',
            'fleet_achievements' => '4. Key Achievements',
            'fleet_challenges' => '5. Challenges Encountered',
            'fleet_conclusion' => '6. Conclusion',
            'kpi_fleet_overview' => 'Key Performance Indicators & Fleet Overview',
            'driver_performance' => 'Driver Performance',
            'trip_status_analysis' => 'Trip Status Analysis',
            'delivery_status_analysis' => 'Delivery Order Status Analysis',
            'fleet_status_analysis' => 'Trip & Delivery Status Analysis',
            'key_fleet_activities' => 'Key Fleet Activities',
            'period_comparison' => 'Period Comparison',
            'overdue_orders' => 'Overdue Delivery Orders',
            'operational_challenges' => 'Operational Challenges',
            'challenges_issues' => 'Challenges / Issues',
            'vehicle_utilization' => 'Vehicle Utilization',
            'trend_analysis' => 'Trip Trend Analysis',
        ],
        'operations' => [
            'overall_summary' => 'Overall report',
            'client_followups' => '1. Client follow-ups',
            'documents_preparation' => '2. Documents preparation',
            'social_media' => '3. Social media management',
            'operations_achievements' => 'Achievements',
            'operations_challenges' => 'Challenges',
            'operations_conclusion' => 'Conclusion',
        ],
        'store_warehouse' => [
            'inventory_overview' => 'Stock Overview',
            'stock_movement_analysis' => 'Stock Movement',
            'purchase_activity' => 'Purchase / Procurement Activity',
            'stock_status_analysis' => 'Stock Status',
            'inventory_valuation' => 'Inventory Valuation by Category',
            'product_category_analysis' => 'Product / Category Analysis',
            'fast_slow_moving' => 'Fast & Slow Moving Items',
            'low_stock_analysis' => 'Low Stock & Replenishment',
            'supplier_analysis' => 'Supplier Analysis',
            'key_store_activities' => 'Key Store Activities',
            'challenges_issues' => 'Challenges / Issues',
            'period_comparison' => 'Period Comparison',
        ],
        default => [],
    };

    $catalog = array_merge($common, $extra);
    if ($domain === 'finance') {
        $catalog['kpi_overview'] = 'Key Financial KPIs';
        $catalog['key_findings'] = 'Key Findings & Exceptions';
    }
    if ($domain === 'fleet') {
        $catalog['action_plan'] = 'Fleet Action Plan';
        $catalog['kpi_overview'] = 'Key Performance Indicators';
    }

    return $catalog;
}

function reportEngineDefaultSections(string $domain): array
{
    $domain = reportEngineNormalizeDomain($domain);

    return match ($domain) {
        'procurement' => [
            'cover', 'executive_summary', 'procurement_activities',
            'key_procurement_activities', 'suppliers_analysis', 'order_status_analysis',
            'key_achievements', 'challenges_issues', 'recommendations',
            'period_comparison', 'procurement_trend', 'conclusion',
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
            'cover', 'fleet_introduction', 'fleet_performance', 'fleet_overall',
            'fleet_achievements', 'fleet_challenges', 'fleet_conclusion',
        ],
        'operations' => [
            'cover', 'overall_summary', 'client_followups', 'documents_preparation', 'social_media',
            'operations_achievements', 'operations_challenges', 'operations_conclusion',
        ],
        'store_warehouse' => [
            'cover', 'executive_summary', 'kpi_overview', 'inventory_overview',
            'stock_movement_analysis', 'purchase_activity', 'stock_status_analysis',
            'inventory_valuation', 'product_category_analysis', 'fast_slow_moving',
            'low_stock_analysis', 'supplier_analysis', 'key_store_activities',
            'period_comparison', 'challenges_issues', 'key_findings',
            'recommendations', 'action_plan', 'conclusion',
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

function reportEngineCoverDepartmentIsGeneric(string $department): bool
{
    $department = strtolower(trim(preg_replace('/\s+/', ' ', $department) ?? $department));

    return in_array($department, [
        '', 'sales', 'marketing', 'sales and marketing', 'logistics', 'fleet', 'driver', 'drivers',
        'delivery and logistics', 'operations', 'operation', 'procurement', 'purchasing',
        'warehouse', 'store', 'inventory', 'store / inventory', 'finance',
        'management', 'management department', 'admin', 'administration', 'administrator',
        'general', 'head office', 'director', 'manager',
    ], true);
}

function reportEngineStoredDepartment(string $domain, string $department): string
{
    $domain = reportEngineNormalizeDomain($domain);
    $fallback = trim((string) (reportEngineDomains()[$domain]['department_default'] ?? 'Sales'));
    $department = trim($department);
    if ($department === '' || reportEngineCoverDepartmentIsGeneric($department)) {
        return $fallback !== '' ? $fallback : 'Sales';
    }

    return $department;
}

function reportEngineCoverDepartmentName(string $domain, array $meta): string
{
    $domain = reportEngineNormalizeDomain($domain);
    $department = trim((string) ($meta['department'] ?? ''));
    $defaults = [
        'sales' => 'SALES AND MARKETING DEPARTMENT',
        'fleet' => 'DELIVERY AND LOGISTICS DEPARTMENT',
        'operations' => 'OPERATIONS DEPARTMENT',
        'procurement' => 'PROCUREMENT DEPARTMENT',
        'store_warehouse' => 'STORE / INVENTORY DEPARTMENT',
        'finance' => 'FINANCE DEPARTMENT',
    ];
    if (reportEngineCoverDepartmentIsGeneric($department)) {
        return $defaults[$domain] ?? strtoupper(reportEngineDomainLabel($domain));
    }
    $upper = strtoupper($department);
    if (!str_contains($upper, 'DEPARTMENT')) {
        $upper .= ' DEPARTMENT';
    }

    return $upper;
}

function reportEngineCoverImageUrl(string $file): string
{
    $relative = 'assets/cover/' . ltrim($file, '/');
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/modules/sales-reports'), '/') . '/' . $relative;
    }

    return '/modules/sales-reports/' . $relative;
}

function reportEngineRewriteCoverImagesForPdf(string $html): string
{
    $dir = str_replace('\\', '/', dirname(__DIR__) . '/assets/cover');

    return preg_replace_callback(
        '#src="([^"]*?assets/cover/(logo-\d+\.png))"#',
        static function (array $match) use ($dir): string {
            $path = $dir . '/' . $match[2];
            if (!is_file($path)) {
                return $match[0];
            }

            return 'src="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"';
        },
        $html
    ) ?? $html;
}

function reportEngineBuildCoverHtml(string $domain, array $meta): string
{
    $domain = reportEngineNormalizeDomain($domain);
    $department = htmlspecialchars(reportEngineCoverDepartmentName($domain, $meta), ENT_QUOTES, 'UTF-8');
    $companyName = trim((string) ($_SESSION['company_name'] ?? ''));
    if ($companyName === '') {
        $companyName = 'ULTIMATE GENERAL TRADING';
    }
    $company = htmlspecialchars(strtoupper($companyName), ENT_QUOTES, 'UTF-8');
    $periodLabel = htmlspecialchars(
        salesReportsFormatCoverPeriod(
            (string) ($meta['start_date'] ?? ''),
            (string) ($meta['end_date'] ?? '')
        ),
        ENT_QUOTES,
        'UTF-8'
    );
    $year = htmlspecialchars(
        salesReportsFormatCoverYear(
            (string) ($meta['start_date'] ?? ''),
            (string) ($meta['end_date'] ?? '')
        ),
        ENT_QUOTES,
        'UTF-8'
    );
    $logo = htmlspecialchars(reportEngineCoverImageUrl('logo-0.png'), ENT_QUOTES, 'UTF-8');
    $mark = htmlspecialchars(reportEngineCoverImageUrl('logo-1.png'), ENT_QUOTES, 'UTF-8');
    $blue = '#082a75';
    $navy = '#0f0d29';
    $cell = 'border:0; padding:0;';
    $rule = static function (int $width) use ($cell, $blue): string {
        return '<table border="0" cellspacing="0" cellpadding="0" style="width:' . $width . 'px; border-collapse:collapse; border:0; margin:0 0 14px;">'
            . '<tr><td style="' . $cell . ' width:' . $width . 'px; height:4px; background:' . $blue . '; font-size:1px; line-height:1px;">&nbsp;</td></tr></table>';
    };

    return '<div class="sr-cover-page" style="page-break-after:always; mso-page-break-after:always; background:#ffffff; text-align:left;">'
        . '<table border="0" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; border:0; margin:0;">'
        . '<tr>'
        . '<td style="' . $cell . ' width:51%; vertical-align:top; padding:48px 12px 0 36px;">'
        . $rule(108)
        . '<p style="margin:0; font-family:Arial, sans-serif; font-size:28pt; font-weight:700; color:' . $blue . '; line-height:1.08;">' . $periodLabel . '</p>'
        . '<p style="margin:8px 0 0; font-family:Arial, sans-serif; font-size:28pt; font-weight:700; color:' . $blue . '; line-height:1.08;">REPORT ' . $year . '</p>'
        . '</td>'
        . '<td style="' . $cell . ' width:49%; vertical-align:top; text-align:right; padding:24px 20px 0 0;">'
        . '<img src="' . $logo . '" alt="" style="width:210px; height:auto;" />'
        . '</td>'
        . '</tr>'
        . '<tr><td colspan="2" style="' . $cell . ' height:108mm; font-size:1px; line-height:1px;">&nbsp;</td></tr>'
        . '<tr>'
        . '<td colspan="2" style="' . $cell . ' background:' . $navy . '; vertical-align:bottom;">'
        . '<table border="0" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; border:0; margin:0; background:' . $navy . ';">'
        . '<tr>'
        . '<td style="' . $cell . ' width:51%; height:52mm; background:#ffffff; vertical-align:bottom; padding:0 16px 7mm 36px;">'
        . '<p style="margin:0 0 8px; font-family:Cambria, Georgia, serif; font-size:11pt; letter-spacing:0.04em; color:' . $blue . ';">' . $department . '</p>'
        . $rule(120)
        . '<p style="margin:0; font-family:Cambria, Georgia, serif; font-size:12pt; font-weight:700; color:' . $blue . ';">' . $company . '</p>'
        . '</td>'
        . '<td style="' . $cell . ' width:49%; height:52mm; background:' . $navy . '; text-align:center; vertical-align:bottom; padding:0 0 8mm 0;">'
        . '<img src="' . $mark . '" alt="" style="width:68px; height:auto;" />'
        . '</td>'
        . '</tr>'
        . '<tr><td colspan="2" style="' . $cell . ' height:26mm; background:' . $navy . '; font-size:1px; line-height:1px;">&nbsp;</td></tr>'
        . '</table>'
        . '</td>'
        . '</tr>'
        . '</table>'
        . '<!-- /sr-cover-page --></div>';
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
        'department' => reportEngineStoredDepartment($domain, (string) ($meta['department_default'] ?? '')),
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
