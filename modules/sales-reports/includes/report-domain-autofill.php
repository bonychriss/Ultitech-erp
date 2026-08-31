<?php

declare(strict_types=1);

require_once __DIR__ . '/report-engine.php';
require_once __DIR__ . '/report-domain-data.php';

function reportEngineSectionAutofillMap(string $domain): array
{
    $domain = reportEngineNormalizeDomain($domain);
    if ($domain === 'sales') {
        return salesReportsSectionAutofillMap();
    }

    $erp = static fn(array $sources) => ['erp' => $sources];
    $ai = static fn(array $extra = []) => array_merge(['ai' => true], $extra);
    $both = static fn(array $sources) => ['ai' => true, 'erp' => $sources];

    return match ($domain) {
        'procurement' => [
            'cover' => ['type' => 'cover'],
            'executive_summary' => $ai(),
            'kpi_overview' => $erp(['inventory_summary']),
            'inventory_overview' => $both(['inventory_summary']),
            'stock_movement_analysis' => $both(['movement_summary']),
            'inventory_valuation' => $both(['stock_by_category']),
            'fast_slow_moving' => $erp(['fast_moving', 'slow_moving']),
            'low_stock_analysis' => $both(['low_stock']),
            'performance_overview' => $ai(['erp' => ['inventory_summary']]),
            'detailed_analysis' => $both(['stock_by_category', 'movement_summary']),
            'trend_analysis' => $erp(['monthly_movements']),
            'exceptions_risks' => $ai(),
            'key_findings' => $ai(),
            'recommendations' => $ai(),
            'action_plan' => $ai(),
            'conclusion' => $ai(),
        ],
        'finance' => [
            'cover' => ['type' => 'cover'],
            'executive_summary' => $ai(),
            'kpi_overview' => $erp(['finance_summary']),
            'financial_overview' => $both(['finance_summary', 'income_expense_trend']),
            'income_revenue_analysis' => $both(['income_detail']),
            'expense_analysis' => $both(['expense_calculations', 'expense_categories']),
            'profit_loss_statement' => $erp(['profit_loss']),
            'cash_flow_statement' => $both(['cash_flow']),
            'cash_bank_balances' => $erp(['account_balances']),
            'bank_reconciliation' => $erp(['bank_reconciliation']),
            'accounts_receivable' => $both(['accounts_receivable_summary']),
            'ar_aging_analysis' => $erp(['accounts_receivable']),
            'accounts_payable' => $both(['accounts_payable_summary']),
            'ap_aging_analysis' => $erp(['ap_aging']),
            'payment_voucher_analysis' => $both(['voucher_list']),
            'trial_balance' => $erp(['trial_balance']),
            'balance_sheet' => $erp(['balance_sheet']),
            'general_ledger' => $erp(['general_ledger']),
            'budget_vs_actual' => $both(['budget_vs_actual']),
            'financial_ratios' => $both(['financial_ratios']),
            'comparative_period_analysis' => $both(['comparative_period']),
            'key_findings' => $ai(['erp' => ['finance_summary']]),
            'recommendations' => $ai(),
            'action_plan' => $ai(),
            'conclusion' => $ai(),
        ],
        'fleet' => [
            'cover' => ['type' => 'cover'],
            'executive_summary' => ['fleet_prose' => true],
            'kpi_fleet_overview' => ['erp' => ['fleet_overview_table']],
            'operational_challenges' => ['fleet_prose' => true],
            'key_findings' => ['fleet_prose' => true],
            'recommendations' => ['fleet_prose' => true],
            'action_plan' => ['fleet_prose' => true],
            'conclusion' => ['fleet_prose' => true],
            // Legacy section keys (older reports)
            'kpi_overview' => ['erp' => ['fleet_overview_table']],
            'fleet_overview' => ['fleet_prose' => true, 'fleet_prose_key' => 'fleet_overview'],
        ],
        'store_warehouse' => [
            'cover' => ['type' => 'cover'],
            'executive_summary' => $ai(),
            'kpi_overview' => $erp(['inventory_summary']),
            'inventory_overview' => $both(['inventory_summary']),
            'stock_movement_analysis' => $both(['movement_summary']),
            'inventory_valuation' => $both(['stock_by_category']),
            'fast_slow_moving' => $erp(['fast_moving', 'slow_moving']),
            'low_stock_analysis' => $both(['low_stock']),
            'performance_overview' => $ai(['erp' => ['inventory_summary']]),
            'detailed_analysis' => $both(['stock_by_category', 'movement_summary']),
            'trend_analysis' => $erp(['monthly_movements']),
            'exceptions_risks' => $ai(),
            'key_findings' => $ai(),
            'recommendations' => $ai(),
            'action_plan' => $ai(),
            'conclusion' => $ai(),
        ],
        default => [],
    };
}

function reportEngineAutofillSections(PDO $pdo, array $report, array $sections): array
{
    $domain = reportEngineReportDomain($report);
    if ($domain === 'sales') {
        return salesReportsAutofillSections($pdo, $report, $sections);
    }

    reportEngineLoadDomainData();
    $filters = reportEngineFiltersFromReport($report);
    $catalog = reportEngineSectionCatalog($domain);
    $map = reportEngineSectionAutofillMap($domain);
    $available = reportEngineBuildSnapshot($pdo, $report)['sections_available'] ?? [];
    $filled = [];

    foreach ($sections as $section) {
        if (empty($section['visible'])) {
            $filled[] = $section;
            continue;
        }

        $key = (string) ($section['key'] ?? '');
        $skipAvailability = in_array(reportEngineReportDomain($report), ['finance', 'fleet'], true);
        if (!$skipAvailability && $available !== [] && !in_array($key, $available, true) && !in_array($key, ['cover', 'executive_summary', 'kpi_overview', 'kpi_fleet_overview', 'operational_challenges', 'key_findings', 'recommendations', 'action_plan', 'conclusion'], true)) {
            $filled[] = array_merge($section, ['content' => '']);
            continue;
        }

        $cfg = $map[$key] ?? ['ai' => true];
        $title = $catalog[$key] ?? (string) ($section['title'] ?? ucfirst($key));
        $parts = [];

        if (($cfg['type'] ?? '') === 'cover') {
            $parts[] = reportEngineBuildCoverHtml($domain, $report);
        } else {
            if ($title !== '') {
                $parts[] = salesReportsSectionHeading($title);
            }
            if (!empty($cfg['fleet_prose'])) {
                $proseKey = (string) ($cfg['fleet_prose_key'] ?? $key);
                $parts[] = reportDomainFleetProseSection($pdo, $report, $proseKey);
            } elseif (!empty($cfg['ai'])) {
                $ai = reportEngineGenerateAiText($pdo, $report, $key);
                $parts[] = (string) ($ai['text'] ?? '');
            }
            foreach ($cfg['erp'] ?? [] as $source) {
                try {
                    $data = reportEngineFetchErpData($pdo, $domain, $source, $filters);
                    $parts[] = salesReportsErpBlockHtml($source, (string) ($data['html'] ?? ''));
                } catch (Throwable $e) {
                    error_log('reportEngineAutofillSections erp ' . $source . ': ' . $e->getMessage());
                }
            }
            if (empty($cfg['ai']) && empty($cfg['erp']) && empty($cfg['fleet_prose'])) {
                $ai = reportEngineGenerateAiText($pdo, $report, $key);
                $parts[] = (string) ($ai['text'] ?? '<p></p>');
            }
        }

        $filled[] = array_merge($section, [
            'content' => implode("\n", array_filter($parts)),
        ]);
    }

    return $filled;
}

function reportEngineApplyAutofill(PDO $pdo, int $reportId, bool $force = false): array
{
    $report = salesReportsGet($pdo, $reportId);
    if (!$report) {
        return ['success' => false, 'error' => 'Report not found'];
    }

    if (reportEngineReportDomain($report) === 'sales') {
        return salesReportsApplyAutofill($pdo, $reportId, $force);
    }

    $doc = salesReportsGetDocument($pdo, $reportId);
    $sections = json_decode((string) ($doc['sections_json'] ?? '[]'), true) ?: [];
    if ($sections === []) {
        $domain = reportEngineReportDomain($report);
        $templateKey = (string) ($report['template_key'] ?? reportEngineDefaultTemplateKey($domain));
        $templates = reportEngineTemplates($domain);
        $template = $templates[$templateKey] ?? reset($templates);
        $sections = salesReportsBuildInitialSections($pdo, $reportId, $template['sections'] ?? [], $report);
    }

    if (!$force && !salesReportsDocumentNeedsAutofill($doc, $sections)) {
        return [
            'success' => true,
            'skipped' => true,
            'content_html' => salesReportsUiMergeDocumentHtml($doc, $sections),
            'sections' => $sections,
        ];
    }

    $filled = reportEngineAutofillSections($pdo, $report, $sections);
    $contentHtml = salesReportsRenderSectionsHtml($filled, $report);
    $sectionsJson = salesReportsJsonEncode($filled);
    $userId = salesReportsUserId();

    if ($doc) {
        $pdo->prepare('UPDATE sales_report_documents SET content = ?, content_html = ?, sections_json = ?, updated_by = ?, autofilled_at = NOW() WHERE report_id = ?')
            ->execute([$sectionsJson, $contentHtml, $sectionsJson, $userId ?: null, $reportId]);
    }

    return [
        'success' => true,
        'skipped' => false,
        'content_html' => $contentHtml,
        'sections' => $filled,
        'message' => 'Report generated from ERP data and AI analysis.',
    ];
}
